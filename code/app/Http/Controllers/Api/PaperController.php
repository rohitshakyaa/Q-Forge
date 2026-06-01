<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blueprint;
use App\Models\Paper;
use App\Models\PaperQuestion;
use App\Models\Question;
use App\Services\Export\PaperWordBuilder;
use App\Services\PaperGeneration\PaperGenerator;
use App\Services\PaperGeneration\Support\GenerationResult;
use App\Services\PaperGeneration\Support\PaperViewModel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PaperController extends Controller
{
    public function __construct(private readonly PaperGenerator $generator)
    {
    }

    /**
     * Run the deterministic generation engine for a teacher-owned blueprint.
     *
     * Success → persist a draft paper + paper_questions (used_count is NOT
     * incremented here; that is deferred to Save in M3) and return the paper
     * with an all-green constraint checklist. Infeasible → return the
     * best-effort partial paper plus missing_slots, persisting nothing.
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blueprint_id' => ['required', 'integer', 'exists:blueprints,id'],
        ]);

        $blueprint = Blueprint::with('subject')->findOrFail($validated['blueprint_id']);
        abort_unless($blueprint->owner_id === $request->user()->id, 403, 'This blueprint belongs to another user.');

        $result = $this->generator->generate($blueprint);

        if (! $result->satisfiable) {
            return response()->json([
                'satisfiable' => false,
                'paper' => $this->partialPaperPayload($blueprint, $result),
                'missing_slots' => $result->missingSlotsArray(),
                'constraint_results' => $result->constraintResultsArray(),
            ]);
        }

        $paper = $this->persist($blueprint, $result);

        return response()->json([
            'satisfiable' => true,
            'paper' => $this->paperPayload($paper, $result),
            'constraint_results' => $result->constraintResultsArray(),
        ]);
    }

    private function persist(Blueprint $blueprint, GenerationResult $result): Paper
    {
        return DB::transaction(function () use ($blueprint, $result) {
            $paper = Paper::create([
                'owner_id' => $blueprint->owner_id,
                'blueprint_id' => $blueprint->id,
                'subject_id' => $blueprint->subject_id,
                'name' => $blueprint->name,
                'total_marks' => $blueprint->total_marks,
                'duration' => $blueprint->duration,
                'status' => 'draft',
                'export_count' => 0,
                'generated_at' => now(),
            ]);

            $pickedIds = [];

            foreach ($result->blueprint->slots as $slot) {
                $question = $result->selections[$slot->index] ?? null;
                if ($question === null) {
                    continue;
                }

                $paper->paperQuestions()->create([
                    'question_id' => $question->id,
                    'unit_id' => $question->unit_id,
                    'section_label' => $slot->sectionLabel,
                    'display_no' => $slot->displayNo,
                    'marks' => $slot->marks,
                    'is_ai' => $question->source === 'ai',
                ]);

                $pickedIds[] = $question->id;
            }

            // Denormalized usage counter (display + LRU tie-break). Repetition
            // control itself is derived from paper_questions, not this column.
            if (! empty($pickedIds)) {
                Question::whereIn('id', $pickedIds)->increment('used_count');
            }

            return $paper;
        });
    }

    private function paperPayload(Paper $paper, GenerationResult $result): array
    {
        return [
            'id' => $paper->id,
            'name' => $paper->name,
            'subject' => $paper->subject?->code,
            'marks' => $paper->total_marks,
            'duration' => $paper->duration,
            'status' => $paper->status,
            'questions' => count($result->selections),
            'generated_at' => $paper->generated_at?->toIso8601String(),
            'sections' => $result->toSections(),
        ];
    }

    /** Shape an unpersisted, best-effort partial paper for the infeasible response. */
    private function partialPaperPayload(Blueprint $blueprint, GenerationResult $result): array
    {
        return [
            'id' => null,
            'name' => $blueprint->name,
            'subject' => $blueprint->subject?->code,
            'marks' => $blueprint->total_marks,
            'duration' => $blueprint->duration,
            'status' => 'draft',
            'questions' => count($result->selections),
            'generated_at' => null,
            'sections' => $result->toSections(),
        ];
    }

    /**
     * Paper history for the authenticated teacher (most recent first). Lightweight
     * list rows — no per-paper sections (those are loaded on demand via show()).
     */
    public function index(Request $request): JsonResponse
    {
        $papers = Paper::query()
            ->where('owner_id', $request->user()->id)
            ->with('subject')
            ->withCount('paperQuestions')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get();

        $data = $papers->map(fn (Paper $paper) => [
            'id' => $paper->id,
            'name' => $paper->name,
            'subject' => $paper->subject?->code,
            'marks' => $paper->total_marks,
            'duration' => $paper->duration,
            'status' => $paper->status,
            'export_count' => $paper->export_count,
            'questions' => $paper->paper_questions_count,
            'generated_at' => $paper->generated_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $data]);
    }

    /** Full paper (with sections) for the Paper View / Export screens. */
    public function show(Request $request, Paper $paper): JsonResponse
    {
        $this->authorizeOwner($request, $paper);

        return response()->json(['paper' => PaperViewModel::for($paper)->toArray()]);
    }

    /** Rename and/or mark a draft as saved. */
    public function update(Request $request, Paper $paper): JsonResponse
    {
        $this->authorizeOwner($request, $paper);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:saved'],
        ]);

        $paper->fill($validated)->save();

        return response()->json(['paper' => PaperViewModel::for($paper)->toArray()]);
    }

    public function destroy(Request $request, Paper $paper): JsonResponse
    {
        $this->authorizeOwner($request, $paper);

        $paper->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Owner-wide usage analytics derived from the teacher's papers and their
     * paper_questions snapshots.
     */
    public function analytics(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $paperIds = Paper::where('owner_id', $userId)->pluck('id');
        $usages = PaperQuestion::whereIn('paper_id', $paperIds);

        $questionsUsed = (clone $usages)->count();
        $uniqueQuestions = (clone $usages)->distinct('question_id')->count('question_id');
        $totalExports = (int) Paper::where('owner_id', $userId)->sum('export_count');

        return response()->json([
            'generated' => $paperIds->count(),
            'questionsUsed' => $questionsUsed,
            'uniqueQuestions' => $uniqueQuestions,
            'reuseRate' => $uniqueQuestions > 0 ? round($questionsUsed / $uniqueQuestions, 1) : 0,
            'totalExports' => $totalExports,
        ]);
    }

    /**
     * Export a paper to PDF or DOCX from the shared PaperViewModel. Each export
     * bumps export_count and marks the paper exported.
     */
    public function export(Request $request, Paper $paper): Response
    {
        $this->authorizeOwner($request, $paper);

        $validated = $request->validate([
            'format' => ['required', 'in:pdf,docx'],
        ]);

        $vm = PaperViewModel::for($paper);

        $paper->forceFill([
            'export_count' => $paper->export_count + 1,
            'status' => 'exported',
        ])->save();

        if ($validated['format'] === 'pdf') {
            $pdf = Pdf::loadView('exports.paper', [
                'header' => $vm->header(),
                'sections' => $vm->sections(),
            ]);

            return $pdf->download($vm->filename().'.pdf');
        }

        $path = (new PaperWordBuilder($vm))->build();

        return response()->download(
            $path,
            $vm->filename().'.docx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        )->deleteFileAfterSend(true);
    }

    /** 403 unless the paper belongs to the requesting teacher. */
    private function authorizeOwner(Request $request, Paper $paper): void
    {
        abort_unless($paper->owner_id === $request->user()->id, 403, 'This paper belongs to another user.');
    }
}
