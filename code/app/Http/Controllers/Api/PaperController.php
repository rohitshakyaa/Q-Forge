<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blueprint;
use App\Models\Paper;
use App\Services\PaperGeneration\PaperGenerator;
use App\Services\PaperGeneration\Support\GenerationResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
}
