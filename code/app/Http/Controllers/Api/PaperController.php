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
use App\Services\PaperGeneration\VectorSimilarityGuard;
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
     * Run the generation engine for a teacher-owned blueprint and return a
     * **preview** — nothing is persisted here. Seed-parameterised (a fresh random
     * seed per run varies the paper; a client-supplied seed reproduces one) with
     * within-paper semantic dedup — see PaperGenerator.
     *
     * The response carries the `seed` + `blueprint_id` so the client can hand
     * them back to `store()` (Save) to persist the exact same paper. Because the
     * engine is deterministic given its seed, Save re-generates rather than
     * round-tripping the selection. Infeasible → best-effort partial paper plus
     * missing_slots. Either way `used_count`, `last_used_at`, and history are
     * untouched until the teacher explicitly saves.
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blueprint_id' => ['required', 'integer', 'exists:blueprints,id'],
            // Optional: pin the run to reproduce a specific paper. Omitted on a
            // normal "generate"/"regenerate", so each run draws a fresh variant.
            'seed' => ['sometimes', 'integer', 'min:0'],
        ]);

        $blueprint = Blueprint::with('subject')->findOrFail($validated['blueprint_id']);
        abort_unless($blueprint->owner_id === $request->user()->id, 403, 'This blueprint belongs to another user.');

        // A fresh random seed per run varies the paper across regenerations and
        // between teachers; a client may pass its own to reproduce one exactly.
        $seed = $validated['seed'] ?? random_int(0, PHP_INT_MAX);

        $result = $this->generator->generate(
            $blueprint,
            $seed,
            app(VectorSimilarityGuard::class),
        );

        if (! $result->satisfiable) {
            return response()->json([
                'satisfiable' => false,
                'seed' => $seed,
                'blueprint_id' => $blueprint->id,
                // AI bank expansion adds questions, not slots, so it can only help when
                // the shortfall is about bank contents — never when coverage needs more
                // units than the blueprint has questions.
                'expandable' => ! $result->coverageStructurallyInfeasible(),
                'shortfall_reason' => $result->coverageDeficitMessage(),
                'paper' => $this->previewPaperPayload($blueprint, $result),
                'missing_slots' => $result->missingSlotsArray(),
                'constraint_results' => $result->constraintResultsArray(),
            ]);
        }

        return response()->json([
            'satisfiable' => true,
            // Echoed so the client can pass them back to Save to persist this exact paper.
            'seed' => $seed,
            'blueprint_id' => $blueprint->id,
            'paper' => $this->previewPaperPayload($blueprint, $result),
            'constraint_results' => $result->constraintResultsArray(),
        ]);
    }

    /**
     * Save a previewed paper (POST /papers). Re-generates deterministically from
     * the preview's seed and persists it as a kept paper. The engine is a pure
     * function of (blueprint, seed, bank), so the saved paper matches the preview
     * unless the bank changed in between — in which case we refuse and ask for a
     * regenerate rather than persist a paper the teacher never saw.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blueprint_id' => ['required', 'integer', 'exists:blueprints,id'],
            'seed' => ['required', 'integer', 'min:0'],
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $blueprint = Blueprint::with('subject')->findOrFail($validated['blueprint_id']);
        abort_unless($blueprint->owner_id === $request->user()->id, 403, 'This blueprint belongs to another user.');

        $result = $this->generator->generate(
            $blueprint,
            $validated['seed'],
            app(VectorSimilarityGuard::class),
        );

        abort_unless(
            $result->satisfiable,
            422,
            'This paper can no longer be generated from the current question bank — please regenerate.',
        );

        $paper = $this->persist($blueprint, $result, $validated['name'] ?? null);

        return response()->json([
            'paper' => $this->paperPayload($paper, $result),
        ], 201);
    }

    private function persist(Blueprint $blueprint, GenerationResult $result, ?string $name = null): Paper
    {
        return DB::transaction(function () use ($blueprint, $result, $name) {
            $paper = Paper::create([
                'owner_id' => $blueprint->owner_id,
                'blueprint_id' => $blueprint->id,
                'subject_id' => $blueprint->subject_id,
                'name' => $name ?? $blueprint->name,
                'total_marks' => $blueprint->total_marks,
                'duration' => $blueprint->duration,
                // Persisted only on an explicit Save, so it goes straight to
                // 'saved' — there is no auto-created 'draft' any more.
                'status' => 'saved',
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
                    'unit_id' => $this->snapshotUnitId($question, $result->blueprint->allowedUnitIds),
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

            // "Last used" on the blueprint cards; only a persisted paper counts as use.
            $blueprint->update(['last_used_at' => now()]);

            return $paper;
        });
    }

    /**
     * The unit the placed question displays as. Primary when the blueprint is
     * unrestricted or allows it; otherwise the lowest-id tagged unit that IS
     * allowed — the paper never labels a question with a unit the blueprint
     * excluded (a multi-unit question can qualify through a secondary tag).
     *
     * @param  int[]  $allowedUnitIds
     */
    private function snapshotUnitId(Question $question, array $allowedUnitIds): ?int
    {
        if (empty($allowedUnitIds) || in_array((int) $question->unit_id, $allowedUnitIds, true)) {
            return $question->unit_id;
        }

        $allowedTags = array_intersect($question->taggedUnitIds(), $allowedUnitIds);
        sort($allowedTags);

        return $allowedTags[0] ?? $question->unit_id;
    }

    private function paperPayload(Paper $paper, GenerationResult $result): array
    {
        return [
            'id' => $paper->id,
            'name' => $paper->name,
            'subject' => $paper->subject?->code,
            'subject_name' => $paper->subject?->name,
            'marks' => $paper->total_marks,
            'duration' => $paper->duration,
            'status' => $paper->status,
            'questions' => count($result->selections),
            'generated_at' => $paper->generated_at?->toIso8601String(),
            'sections' => $result->toSections(),
        ];
    }

    /**
     * Shape an unpersisted paper — the generate preview (whether fully satisfiable
     * or a best-effort partial). `id: null` marks it as not-yet-saved; the client
     * pairs it with the response's `seed` to Save it.
     */
    private function previewPaperPayload(Blueprint $blueprint, GenerationResult $result): array
    {
        return [
            'id' => null,
            'name' => $blueprint->name,
            'subject' => $blueprint->subject?->code,
            'subject_name' => $blueprint->subject?->name,
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
            ->where('origin', 'generated')
            ->with('subject')
            ->withCount('paperQuestions')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get();

        $data = $papers->map(fn (Paper $paper) => [
            'id' => $paper->id,
            'name' => $paper->name,
            'subject' => $paper->subject?->code,
            'subject_name' => $paper->subject?->name,
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

    /** Rename a saved paper. (Papers persist as 'saved' on Save, so there is no
     *  draft→saved transition to perform here any more.) */
    public function update(Request $request, Paper $paper): JsonResponse
    {
        $this->authorizeOwner($request, $paper);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
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

        // Owner-scoped on generated papers only — imported past exams (admin-owned,
        // origin=imported) never surface in a teacher's analytics.
        $paperIds = Paper::where('owner_id', $userId)->where('origin', 'generated')->pluck('id');
        $usages = PaperQuestion::whereIn('paper_id', $paperIds);

        $questionsUsed = (clone $usages)->count();
        $uniqueQuestions = (clone $usages)->distinct('question_id')->count('question_id');
        $totalExports = (int) Paper::where('owner_id', $userId)->where('origin', 'generated')->sum('export_count');

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
