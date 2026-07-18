<?php

namespace App\Services\Rag;

use App\Models\ContentChunk;
use App\Models\Subject;
use App\Models\Unit;
use App\Services\PythonService;
use Illuminate\Support\Facades\DB;

/**
 * (Re)builds one subject's retrieval corpus: chunk → MySQL rows → embed →
 * Qdrant `chunks` collection (M6 Phase 2 — docs/RAG-GUIDE.md §5–6).
 *
 * Wholesale-replace, per subject: chunks are derived data with no stable
 * identity across edits, so patching them in place buys nothing. Delete the
 * subject's rows and points, re-chunk everything (each unit's `content` +
 * the subject-level `syllabus`), insert, embed in one batch, upsert. Called
 * from the queued SyncSubjectChunks job and the reindex command — never
 * inline in a request.
 */
class ContentIndexer
{
    public function __construct(
        private readonly ContentChunker $chunker,
        private readonly PythonService $python,
        private readonly QdrantClient $qdrant,
    ) {}

    /** @return int chunks indexed */
    public function sync(Subject $subject): int
    {
        $subject->loadMissing('units');

        // Rebuild MySQL first — it is the source of truth; Qdrant follows.
        $chunks = DB::transaction(function () use ($subject) {
            ContentChunk::where('subject_id', $subject->id)->delete();

            $rows = [];
            $position = 0;

            // Subject-level corpus: the whole-course syllabus (unit_id null).
            foreach ($this->chunker->chunk((string) $subject->syllabus, "{$subject->code} syllabus") as $piece) {
                $rows[] = $this->row($subject, null, $position++, $piece);
            }

            // Per-unit corpus: each unit's own content body.
            foreach ($subject->units->sortBy('position') as $unit) {
                $position = 0;
                foreach ($this->chunker->chunk((string) $unit->content, "{$subject->code} > {$unit->name}") as $piece) {
                    $rows[] = $this->row($subject, $unit, $position++, $piece);
                }
            }

            return array_map(fn (array $row) => ContentChunk::create($row), $rows);
        });

        // Qdrant follows: clear the subject's old points (ids didn't survive the
        // re-chunk), then embed the new texts in one batch and upsert.
        $this->qdrant->deleteByFilter(QdrantClient::COLLECTION_CHUNKS, [
            'must' => [['key' => 'subject_id', 'match' => ['value' => (int) $subject->id]]],
        ]);

        if ($chunks === []) {
            return 0;
        }

        $embedded = $this->python->embed(
            array_map(fn (ContentChunk $c) => $c->embeddingText(), $chunks),
            'document',
        );

        $points = [];
        foreach ($chunks as $i => $chunk) {
            $points[] = [
                'id' => (int) $chunk->id,
                'vector' => $embedded['embeddings'][$i],
                'payload' => [
                    'subject_id' => (int) $chunk->subject_id,
                    'unit_id' => $chunk->unit_id !== null ? (int) $chunk->unit_id : null,
                    'embedding_model' => $embedded['model'],
                ],
            ];
        }

        $this->qdrant->upsert(QdrantClient::COLLECTION_CHUNKS, $points);

        return count($points);
    }

    /** @return array<string, mixed> */
    private function row(Subject $subject, ?Unit $unit, int $position, array $piece): array
    {
        return [
            'subject_id' => $subject->id,
            'unit_id' => $unit?->id,
            'position' => $position,
            'heading' => $piece['heading'],
            'text' => $piece['text'],
        ];
    }
}
