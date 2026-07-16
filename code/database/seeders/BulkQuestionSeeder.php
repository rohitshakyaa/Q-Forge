<?php

namespace Database\Seeders;

use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Deepens the question bank for the synthetic demo subjects with a realistic
 * spread of types and marks, so the generation engine has a large candidate
 * pool to exercise (unit balancing, LRU, backtracking) against.
 *
 * Only the SUBJECT_CODES demo subjects get filler — real subjects seeded from
 * actual syllabi/past papers (e.g. TuPastPaperSeeder) must stay clean, so this
 * seeder never touches them.
 *
 * Idempotent: it removes ALL previously-seeded bulk questions (tagged via
 * attributes->bulk = true) before re-inserting — including any left on
 * subjects that are no longer bulk targets — so re-running won't pile up
 * duplicates and also cleans up strays.
 *
 * Tune the volume with PER_UNIT (questions added per unit). With 5 units that
 * is PER_UNIT * 5 per subject — e.g. 40 => 200 per subject.
 */
class BulkQuestionSeeder extends Seeder
{
    /** Demo subjects that receive bulk filler (never real, imported subjects). */
    private const SUBJECT_CODES = ['CS301', 'CS303'];

    /** Questions to add per unit. */
    private const PER_UNIT = 40;

    /**
     * (type, marks) combinations with a weight = how many of each to create
     * per unit. Weighted toward the short/4 and long/10 combos the seeded
     * blueprints rely on, with mcq/short/long variety for breadth.
     *
     * @var array<int, array{0:string,1:int,2:int}>  [type, marks, weight]
     */
    private const MIX = [
        ['mcq', 2, 6],
        ['short', 4, 12],
        ['short', 5, 5],
        ['short', 6, 3],
        ['long', 8, 4],
        ['long', 10, 10],
    ];

    public function run(): void
    {
        // Idempotent + cleanup: drop every bulk question wherever it lives,
        // including on subjects that are no longer bulk targets.
        Question::whereJsonContains('attributes->bulk', true)->delete();

        $subjects = Subject::with('units')->whereIn('code', self::SUBJECT_CODES)->get();

        if ($subjects->isEmpty()) {
            $this->command?->warn('No demo subjects found — run QForgeDemoSeeder first.');

            return;
        }

        foreach ($subjects as $subject) {
            foreach ($subject->units as $unit) {
                $this->seedUnit($subject, $unit);
            }
        }

        $this->command?->info(
            'Bulk-seeded '.(Question::whereJsonContains('attributes->bulk', true)->count())
            .' questions across '.$subjects->count().' subjects.'
        );
    }

    private function seedUnit(Subject $subject, Unit $unit): void
    {
        $plan = $this->expandMix(self::PER_UNIT);

        foreach ($plan as $n => [$type, $marks]) {
            Question::factory()->create([
                'subject_id' => $subject->id,
                'unit_id' => $unit->id,
                'type' => $type,
                'marks' => $marks,
                'text' => $this->questionText($subject, $unit, $type, $marks, $n + 1),
                'source' => 'manual',
                'status' => 'approved',
                'used_count' => 0,
                'attributes' => ['bulk' => true],
            ]);
        }
    }

    /**
     * Expand the weighted MIX into an ordered list of [type, marks] of length
     * $total (cycling through the weighted combos).
     *
     * @return array<int, array{0:string,1:int}>
     */
    private function expandMix(int $total): array
    {
        $pool = [];
        foreach (self::MIX as [$type, $marks, $weight]) {
            for ($i = 0; $i < $weight; $i++) {
                $pool[] = [$type, $marks];
            }
        }

        $out = [];
        for ($i = 0; $i < $total; $i++) {
            $out[] = $pool[$i % count($pool)];
        }

        return $out;
    }

    private function questionText(Subject $subject, Unit $unit, string $type, int $marks, int $n): string
    {
        $verb = match ($type) {
            'mcq' => 'Select the correct statement about',
            'long' => 'Explain in detail, with examples,',
            default => 'Briefly describe',
        };

        return "[{$subject->code} · {$unit->name}] Q{$n} ({$marks}M): {$verb} a key concept from "
            ."\"{$unit->name}\".".($type === 'mcq' ? ' (a) … (b) … (c) … (d) …' : '');
    }
}
