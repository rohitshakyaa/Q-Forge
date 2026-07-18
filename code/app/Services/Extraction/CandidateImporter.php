<?php

namespace App\Services\Extraction;

use App\Models\DocumentUpload;
use App\Models\Question;
use App\Services\Rag\SimilarQuestionFinder;
use Illuminate\Support\Facades\DB;

/**
 * Turns Python's stateless `/extract` output into `questions` rows.
 *
 * Everything lands as `source=extracted`, `status=pending`: nothing reaches the
 * generator until an admin approves it in the review queue.
 */
class CandidateImporter
{
    public function __construct(private readonly SimilarQuestionFinder $similar) {}

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{created:int, skipped:int, duplicate:int, unlinked:int}
     */
    public function import(DocumentUpload $upload, array $candidates): array
    {
        if ($upload->subject_id === null || $candidates === []) {
            return ['created' => 0, 'skipped' => 0, 'duplicate' => 0, 'unlinked' => 0];
        }

        $upload->loadMissing('subject.units');
        $resolver = new UnitResolver($upload->subject->units);

        // One query rather than one per candidate. An exact match no longer drops
        // the candidate — it imports and gets flagged so the reviewer decides
        // (docs/RAG-GUIDE.md Phase 1). Rejected rows are excluded from the pool:
        // a candidate that only matches something already turned down comes in
        // clean, as a fresh re-review. Fingerprint => [{question_id, status}, ...]
        // because the same text can sit in the bank several times over.
        $existing = Question::query()
            ->where('subject_id', $upload->subject_id)
            ->whereIn('status', ['approved', 'pending'])
            ->get(['id', 'status', 'text'])
            ->reduce(function (array $map, Question $q) {
                $map[$this->fingerprint($q->text)][] = ['question_id' => $q->id, 'status' => $q->status];

                return $map;
            }, []);

        $created = 0;
        $skipped = 0;
        $duplicate = 0;
        $createdQuestions = [];
        // Exact repeats *within this one upload* are parser noise (a paper printing
        // the same question twice), not a re-review case — collapse them.
        $seenThisBatch = [];

        DB::transaction(function () use ($upload, $candidates, $resolver, $existing, &$created, &$skipped, &$duplicate, &$createdQuestions, &$seenThisBatch) {
            foreach ($candidates as $candidate) {
                $text = trim((string) ($candidate['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $fingerprint = $this->fingerprint($text);
                if (isset($seenThisBatch[$fingerprint])) {
                    $skipped++;

                    continue;
                }
                $seenThisBatch[$fingerprint] = true;

                // Matches against the existing approved/pending bank become a flag,
                // never a skip. Null when the candidate is fresh.
                $duplicateOf = $existing[$fingerprint] ?? null;

                $unit = $resolver->resolve($candidate['unit_hint'] ?? null);

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
                        'duplicate_of' => $duplicateOf,
                    ], fn ($value) => $value !== null),
                ]);

                // Mirror the primary unit into the question_unit pivot.
                $question->syncUnitLinks();

                $createdQuestions[] = $question;
                $created++;
                if ($duplicateOf !== null) {
                    $duplicate++;
                }
            }
        });

        // M6 Phase 1: flag candidates that paraphrase an approved bank question
        // ("similar to Q#123 (0.93)") so the reviewer decides — never auto-drop
        // here. Phase 3b may also pre-tag untagged candidates from their unit
        // suggestions. After the transaction: annotation is best-effort and
        // must not roll back the import.
        $this->similar->annotate($createdQuestions);

        // Counted after annotation on purpose: auto-assignment may have tagged
        // some of what the heading resolver could not.
        $unlinked = count(array_filter($createdQuestions, fn (Question $q) => $q->unit_id === null));

        return ['created' => $created, 'skipped' => $skipped, 'duplicate' => $duplicate, 'unlinked' => $unlinked];
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
