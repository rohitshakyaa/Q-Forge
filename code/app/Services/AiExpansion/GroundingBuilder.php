<?php

namespace App\Services\AiExpansion;

use App\Models\ContentChunk;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Services\PythonService;
use App\Services\Rag\QdrantClient;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the per-slot grounding block that Laravel hands to Python
 * `/generate-questions`.
 *
 * M6 Phase 2 upgraded this from primary-key SQL retrieval to a budget-based
 * hybrid (docs/RAG-GUIDE.md, Phase 2):
 *
 *  - **Fast path** — when the slot's full material (syllabus + target units)
 *    fits the prompt budget, send it whole: for a small corpus, complete
 *    context beats top-k excerpts of it.
 *  - **Retrieval path** — over budget, the block is built from the top-k
 *    content chunks semantically closest to the slot (embedded slot query →
 *    Qdrant `chunks`, subject/unit-filtered). This is the textbook RAG loop.
 *  - **Exemplars** — always semantic when RAG is up: the top-3 approved
 *    same-type questions nearest the slot query (was: first three by id).
 *
 * Grounding is mandatory either way: an ungrounded model invents
 * plausible-but-off-syllabus questions. Everything fails open — with RAG
 * disabled or down this degrades to exactly the M5 behaviour (full content,
 * SQL exemplars) and records why in `notes` so the job can log it.
 */
class GroundingBuilder
{
    private const MAX_EXEMPLARS = 3;

    public function __construct(
        private readonly PythonService $python,
        private readonly QdrantClient $qdrant,
    ) {}

    /**
     * @param  Unit[]  $units  ordered target units (0–2); empty = whole course
     * @return array{text: string, notes: string[]}
     */
    public function for(Subject $subject, array $units, string $type, int $marks): array
    {
        $notes = [];

        // One embedding of the slot query serves both retrieval and exemplar
        // search. Null = RAG off or down; every consumer below falls back.
        $queryVector = $this->embedSlotQuery($subject, $units, $type, $marks, $notes);

        $parts = $this->contentParts($subject, $units, $queryVector, $notes);

        if (count($units) > 1) {
            $parts[] = '# Target: every question must span BOTH units above, integrating material from each.';
        }

        $exemplars = $this->exemplars($subject, $units, $type, $queryVector, $notes);
        if ($exemplars->isNotEmpty()) {
            $lines = $exemplars->values()->map(
                fn (Question $q, int $i) => ($i + 1).'. '.trim($q->text)
            )->implode("\n");
            $parts[] = "# Example {$type} questions (match this style and difficulty)\n{$lines}";
        } else {
            $notes[] = 'no exemplars available (thin unit)';
        }

        if (empty($parts)) {
            // Last resort: never send nothing. Give the model the bare target.
            $unitLabel = $units !== []
                ? implode(' and ', array_map(fn (Unit $u) => $u->name, $units))
                : 'the course';
            $parts[] = "Write {$marks}-mark {$type} questions for {$unitLabel} in {$subject->name}.";
            $notes[] = 'no syllabus, unit content, or exemplars — sent bare slot spec';
        }

        return ['text' => implode("\n\n", $parts), 'notes' => $notes];
    }

    /**
     * The course-material section of the block: whole content when it fits the
     * budget (or RAG can't help), top-k retrieved chunks when it doesn't.
     *
     * @param  Unit[]  $units
     * @param  array<int, float>|null  $queryVector
     * @param  string[]  $notes
     * @return string[]
     */
    private function contentParts(Subject $subject, array $units, ?array $queryVector, array &$notes): array
    {
        $full = $this->fullContentParts($subject, $units, $notes);

        $budget = (int) config('services.qdrant.grounding_budget_chars');
        $size = mb_strlen(implode("\n\n", $full));

        if ($size <= $budget || $queryVector === null) {
            return $full;
        }

        $retrieved = $this->retrievedContentParts($subject, $units, $queryVector, $notes);

        if ($retrieved === []) {
            // Nothing indexed for this subject yet (chunk jobs pending, or the
            // corpus predates M6): oversized full content still beats nothing.
            $notes[] = 'over budget but no chunks indexed — sent full content';

            return $full;
        }

        $notes[] = sprintf(
            'grounding over budget (%d > %d chars) — built from top-%d retrieved chunks',
            $size, $budget, count($retrieved),
        );

        return $retrieved;
    }

    /**
     * The M5 block: whole syllabus + each target unit's whole content body.
     *
     * @param  Unit[]  $units
     * @param  string[]  $notes
     * @return string[]
     */
    private function fullContentParts(Subject $subject, array $units, array &$notes): array
    {
        $parts = [];

        $syllabus = trim((string) $subject->syllabus);
        if ($syllabus !== '') {
            $parts[] = "# Course syllabus\n{$syllabus}";
        } else {
            $notes[] = 'subject syllabus is empty';
        }

        foreach ($units as $unit) {
            $content = trim((string) $unit->content);
            if ($content !== '') {
                $parts[] = "# Unit: {$unit->name}\n{$content}";
            } else {
                // A unit predating the M4.1 content backfill: keep the name as a hint
                // and lean on the syllabus rather than sending a bare slot spec.
                $parts[] = "# Unit: {$unit->name}\n(No detailed unit content on file — use the course syllabus above.)";
                $notes[] = "unit '{$unit->name}' has no content; grounded on syllabus only";
            }
        }

        return $parts;
    }

    /**
     * The retrieval path: top-k chunks nearest the slot query. Scoped to the
     * target units when the slot names some (their bodies are what the question
     * must come from), to the whole subject otherwise.
     *
     * @param  Unit[]  $units
     * @param  array<int, float>  $queryVector
     * @param  string[]  $notes
     * @return string[]
     */
    private function retrievedContentParts(Subject $subject, array $units, array $queryVector, array &$notes): array
    {
        $must = [['key' => 'subject_id', 'match' => ['value' => (int) $subject->id]]];
        if ($units !== []) {
            $must[] = ['key' => 'unit_id', 'match' => ['any' => array_map(fn (Unit $u) => (int) $u->id, $units)]];
        }

        try {
            $hits = $this->qdrant->search(
                QdrantClient::COLLECTION_CHUNKS,
                $queryVector,
                limit: (int) config('services.qdrant.grounding_top_k'),
                filter: ['must' => $must],
            );
        } catch (\Throwable $e) {
            Log::warning('GroundingBuilder: chunk retrieval failed, using full content', [
                'subject_id' => $subject->id, 'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($hits === []) {
            return [];
        }

        // Chunk text lives in MySQL (source of truth) — Qdrant only returned ids.
        $chunks = ContentChunk::whereIn('id', array_column($hits, 'id'))
            ->get()
            ->keyBy('id');

        $parts = [];
        foreach ($hits as $hit) { // best-first, as Qdrant scored them
            $chunk = $chunks->get($hit['id']);
            if ($chunk === null) {
                continue; // Stale point (row re-chunked away); reindex will heal it.
            }
            $heading = $chunk->heading !== null && $chunk->heading !== '' ? $chunk->heading : 'Course material';
            $parts[] = "# {$heading}\n{$chunk->text}";
        }

        return $parts;
    }

    /**
     * Up to three approved exemplars for style and difficulty. Semantic when a
     * query vector exists — the nearest same-type questions, so the model sees
     * the *most relevant* style guide, not the three oldest rows. SQL otherwise.
     * Scarcity is expected (the unit is thin — that's why we're expanding), so
     * 0–3 is normal; we never fabricate to pad.
     *
     * @param  Unit[]  $units
     * @param  array<int, float>|null  $queryVector
     * @param  string[]  $notes
     * @return \Illuminate\Support\Collection<int, Question>
     */
    private function exemplars(Subject $subject, array $units, string $type, ?array $queryVector, array &$notes)
    {
        $unitIds = array_map(fn (Unit $u) => (int) $u->id, $units);

        if ($queryVector !== null) {
            $must = [
                ['key' => 'subject_id', 'match' => ['value' => (int) $subject->id]],
                ['key' => 'type', 'match' => ['value' => $type]],
            ];
            if ($unitIds !== []) {
                // Tag-aware, like the SQL fallback: a multi-unit question is an
                // exemplar for every unit it spans.
                $must[] = ['key' => 'unit_ids', 'match' => ['any' => $unitIds]];
            }

            try {
                $hits = $this->qdrant->search(
                    QdrantClient::COLLECTION_QUESTIONS,
                    $queryVector,
                    limit: self::MAX_EXEMPLARS,
                    filter: ['must' => $must],
                );

                $byId = Question::whereIn('id', array_column($hits, 'id'))->get()->keyBy('id');

                // Preserve Qdrant's best-first order.
                return collect($hits)
                    ->map(fn (array $hit) => $byId->get($hit['id']))
                    ->filter()
                    ->values();
            } catch (\Throwable $e) {
                Log::warning('GroundingBuilder: semantic exemplar search failed, using SQL order', [
                    'subject_id' => $subject->id, 'error' => $e->getMessage(),
                ]);
                $notes[] = 'semantic exemplar search failed — used SQL order';
            }
        }

        return Question::query()
            ->where('subject_id', $subject->id)
            ->where('status', 'approved')
            ->where('type', $type)
            ->when($unitIds !== [], fn ($q) => $q->whereHas(
                'units',
                fn ($sub) => $sub->whereIn('units.id', $unitIds),
            ))
            ->orderBy('id')
            ->limit(self::MAX_EXEMPLARS)
            ->get();
    }

    /**
     * Embed a natural-language description of the slot — the "query" of the RAG
     * loop (guide §6). Null when RAG is disabled or the embed call fails; every
     * caller degrades to M5 behaviour.
     *
     * @param  Unit[]  $units
     * @param  string[]  $notes
     * @return array<int, float>|null
     */
    private function embedSlotQuery(Subject $subject, array $units, string $type, int $marks, array &$notes): ?array
    {
        if (! config('services.qdrant.enabled')) {
            return null;
        }

        $scope = $units !== []
            ? implode(' and ', array_map(fn (Unit $u) => (string) $u->name, $units))
            : 'the whole course';

        $query = "A {$marks}-mark {$type} exam question about {$scope} in {$subject->name}.";

        try {
            return $this->python->embed([$query])['embeddings'][0];
        } catch (\Throwable $e) {
            Log::warning('GroundingBuilder: slot-query embedding failed, degrading to M5 grounding', [
                'subject_id' => $subject->id, 'error' => $e->getMessage(),
            ]);
            $notes[] = 'RAG unavailable — full content and SQL exemplars';

            return null;
        }
    }
}
