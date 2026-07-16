<?php

namespace App\Services\Extraction;

use App\Models\DocumentUpload;
use App\Models\Question;
use Illuminate\Support\Facades\DB;

/**
 * Turns Python's stateless `/extract` output into `questions` rows.
 *
 * Everything lands as `source=extracted`, `status=pending`: nothing reaches the
 * generator until an admin approves it in the review queue.
 */
class CandidateImporter
{
    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{created:int, skipped:int, unlinked:int}
     */
    public function import(DocumentUpload $upload, array $candidates): array
    {
        if ($upload->subject_id === null || $candidates === []) {
            return ['created' => 0, 'skipped' => 0, 'unlinked' => 0];
        }

        $upload->loadMissing('subject.units');
        $resolver = new UnitResolver($upload->subject->units);

        // One query rather than one per candidate; papers repeat across years, and
        // re-importing the same PDF should not double the bank.
        $existing = Question::query()
            ->where('subject_id', $upload->subject_id)
            ->pluck('text')
            ->map(fn (string $text) => $this->fingerprint($text))
            ->flip();

        $created = 0;
        $skipped = 0;
        $unlinked = 0;

        DB::transaction(function () use ($upload, $candidates, $resolver, $existing, &$created, &$skipped, &$unlinked) {
            foreach ($candidates as $candidate) {
                $text = trim((string) ($candidate['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $fingerprint = $this->fingerprint($text);
                if ($existing->has($fingerprint)) {
                    $skipped++;

                    continue;
                }
                $existing[$fingerprint] = true;

                $unit = $resolver->resolve($candidate['unit_hint'] ?? null);
                if ($unit === null) {
                    $unlinked++;
                }

                $question = Question::create([
                    'subject_id' => $upload->subject_id,
                    'unit_id' => $unit?->id,
                    'type' => $candidate['type'] ?? 'short',
                    'marks' => $candidate['marks'] ?? null,
                    'text' => $text,
                    'source' => 'extracted',
                    'status' => 'pending',
                    'attributes' => array_filter([
                        'upload_id' => $upload->id,
                        'unit_hint' => $candidate['unit_hint'] ?? null,
                        'question_no' => $candidate['number'] ?? null,
                        'page' => $candidate['page'] ?? null,
                        'ocr' => $candidate['ocr'] ?? false,
                        'exam_year' => $upload->meta['exam_year'] ?? null,
                    ], fn ($value) => $value !== null),
                ]);

                // Mirror the primary unit into the question_unit pivot.
                $question->syncUnitLinks();

                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped, 'unlinked' => $unlinked];
    }

    /**
     * Collapse whitespace, punctuation and case so that OCR noise and reflowed
     * line breaks don't disguise a question we already hold.
     */
    private function fingerprint(string $text): string
    {
        $normalised = preg_replace('/[^a-z0-9]+/', ' ', strtolower($text));

        return md5(trim((string) $normalised));
    }
}
