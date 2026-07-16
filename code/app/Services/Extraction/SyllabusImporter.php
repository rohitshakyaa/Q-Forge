<?php

namespace App\Services\Extraction;

use App\Models\DocumentUpload;
use App\Models\Subject;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Turns an admin-confirmed syllabus proposal into a subject and its units.
 *
 * Additive by construction: an existing unit is never deleted, renamed, reordered
 * or rewritten. `questions.unit_id` cascades on delete, so removing a unit would
 * take its whole question bank with it — the import must never be able to do that,
 * however the payload is shaped.
 */
class SyllabusImporter
{
    /**
     * @param  array{subject: array<string, mixed>, units: array<int, array<string, mixed>>, update_existing?: bool}  $payload
     * @return array{subject_id:int, units_created:int, units_skipped:int, created_subject:bool}
     */
    public function import(DocumentUpload $upload, array $payload): array
    {
        return DB::transaction(function () use ($upload, $payload) {
            $subject = $this->resolveSubject($payload);
            $created = $subject->wasRecentlyCreated;

            $counts = $this->syncUnits($subject, $payload['units']);

            $upload->update([
                'subject_id' => $subject->id,
                'meta' => [...$upload->meta ?? [], 'imported_subject_id' => $subject->id],
            ]);

            return [
                'subject_id' => $subject->id,
                'created_subject' => $created,
                ...$counts,
            ];
        });
    }

    /**
     * Collapse case, punctuation and whitespace so that "Arrays & Linked Lists" and
     * "arrays and linked lists" are not imported as two units. Shares its intent with
     * CandidateImporter::fingerprint().
     */
    public function fingerprint(string $name): string
    {
        $normalised = preg_replace('/[^a-z0-9]+/', ' ', strtolower($name));

        return trim((string) $normalised);
    }

    /**
     * @param  array{subject: array<string, mixed>, update_existing?: bool}  $payload
     */
    private function resolveSubject(array $payload): Subject
    {
        $data = $payload['subject'];
        $subject = Subject::where('code', $data['code'])->first();

        if ($subject === null) {
            return Subject::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'syllabus' => $data['syllabus'] ?? null,
            ]);
        }

        // The uploaded document *is* the syllabus corpus, so refresh it on every import —
        // that is the whole point of uploading a syllabus into an existing subject.
        $updates = [];
        if (($data['syllabus'] ?? null) !== null) {
            $updates['syllabus'] = $data['syllabus'];
        }

        // Name and description are the teacher's catalog identity, not the document's, so
        // they change only when the admin explicitly opts in.
        if ($payload['update_existing'] ?? false) {
            foreach (['name', 'description'] as $field) {
                if (($data[$field] ?? null) !== null) {
                    $updates[$field] = $data[$field];
                }
            }
        }

        if ($updates !== []) {
            $subject->update($updates);
        }

        return $subject;
    }

    /**
     * Create the units the subject does not already have, matched by normalized name.
     *
     * A brand-new subject takes its positions from the syllabus numbering. An existing
     * one appends after its last unit, so the order a teacher already relies on — and
     * which the M4 unit-hint resolver reads — is left undisturbed.
     *
     * @param  array<int, array<string, mixed>>  $units
     * @return array{units_created:int, units_skipped:int}
     */
    private function syncUnits(Subject $subject, array $units): array
    {
        $existing = $subject->units()
            ->pluck('name')
            ->map(fn (string $name) => $this->fingerprint($name))
            ->flip();

        $isNewSubject = $existing->isEmpty();
        $nextPosition = ((int) $subject->units()->max('position')) + 1;

        $created = 0;
        $skipped = 0;

        foreach ($units as $unit) {
            $fingerprint = $this->fingerprint($unit['name']);

            if ($existing->has($fingerprint)) {
                // Deliberately no update: an existing unit's name, hours and content
                // are the teacher's, not the document's.
                $skipped++;

                continue;
            }
            $existing[$fingerprint] = true;

            Unit::create([
                'subject_id' => $subject->id,
                'name' => $unit['name'],
                'position' => $isNewSubject ? ($unit['number'] ?? $nextPosition) : $nextPosition,
                'hours' => $unit['hours'] ?? null,
                'content' => $unit['content'] ?? null,
            ]);

            $nextPosition++;
            $created++;
        }

        return ['units_created' => $created, 'units_skipped' => $skipped];
    }
}
