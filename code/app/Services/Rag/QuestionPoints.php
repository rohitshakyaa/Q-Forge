<?php

namespace App\Services\Rag;

use App\Models\Question;

/**
 * Builds the Qdrant point for a question — the single definition of what gets
 * indexed, shared by the live sync (SyncQuestionEmbedding) and the bulk rebuild
 * (qforge:rag:reindex) so the two can never drift apart.
 */
final class QuestionPoints
{
    /**
     * @param  array<int, float>  $vector
     * @return array{id: int, vector: array<int, float>, payload: array<string, mixed>}
     */
    public static function make(Question $question, array $vector, string $embeddingModel): array
    {
        return [
            'id' => (int) $question->id, // MySQL row id — the link back to the source of truth
            'vector' => $vector,
            'payload' => [
                'subject_id' => (int) $question->subject_id,
                'unit_ids' => $question->taggedUnitIds(),
                'type' => (string) $question->type,
                'marks' => $question->marks,
                'source' => (string) $question->source,
                // Stamped so a model swap makes stale vectors detectable (guide §6).
                'embedding_model' => $embeddingModel,
            ],
        ];
    }
}
