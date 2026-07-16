<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExpandQuestionBank;
use App\Models\Blueprint;
use App\Services\PaperGeneration\PaperGenerator;
use Illuminate\Bus\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

/**
 * M5 — AI bank expansion. Blueprint-keyed (not paper-keyed): an infeasible
 * `generate` persists nothing, so there is no paper to target. The shortfall is
 * re-derived server-side by re-running the generator (side-effect-free), never
 * trusting client state, then handed to a queued, batched job.
 */
class BankExpansionController extends Controller
{
    public function __construct(private readonly PaperGenerator $generator)
    {
    }

    /**
     * Re-derive the blueprint's missing slots; if it is now satisfiable there is
     * nothing to expand (the teacher just regenerates). Otherwise dispatch
     * ExpandQuestionBank inside a batch and return its id for polling.
     */
    public function expand(Request $request, Blueprint $blueprint): JsonResponse
    {
        abort_unless($blueprint->owner_id === $request->user()->id, 403, 'This blueprint belongs to another user.');

        $result = $this->generator->generate($blueprint);

        if ($result->satisfiable) {
            return response()->json([
                'satisfiable' => true,
                'message' => 'The blueprint is already satisfiable — just generate.',
            ]);
        }

        // Never dispatch a futile job: when coverage needs more units than the paper
        // can ever reach (even with two-unit questions), or the per-unit maximums
        // can't fill the slots, no amount of bank expansion can help (AI adds
        // questions, not slots). Tell the teacher to fix the blueprint instead.
        if ($result->coverageStructurallyInfeasible()) {
            return response()->json([
                'satisfiable' => false,
                'expandable' => false,
                'shortfall_reason' => $result->coverageDeficitMessage(),
            ]);
        }

        $slots = array_map(fn ($slot) => [
            'section_label' => $slot->sectionLabel,
            'type' => $slot->type,
            'marks' => $slot->marks,
            'unit_ids' => $slot->unitIds,
            'need' => $slot->need,
        ], $result->missingSlots);

        // Owner id is baked into the batch name so the status endpoint can authorize
        // without a domain table (M5 adds none) — see jobStatus().
        //
        // One job PER SLOT (not one job for all): batch progress then ticks as
        // each slot completes, so the frontend's stall guard measures real
        // forward motion instead of timing out a healthy multi-slot run — on
        // CPU a full run can exceed any flat window. Failure isolation improves
        // too (allowFailures: one dead slot no longer takes the rest with it).
        // Known trade-off: the in-memory same-run dedup pool (M6) no longer
        // spans slots — cross-slot near-dupes still get caught later via the
        // indexed bank, just not within one batch; acceptable, since slots
        // differ by type/marks and rarely collide.
        $batch = Bus::batch(
            array_map(fn (array $slot) => new ExpandQuestionBank($blueprint->id, [$slot]), $slots)
        )
            ->name("expand-bank:{$blueprint->id}:{$request->user()->id}")
            ->allowFailures()
            ->dispatch();

        return response()->json([
            'satisfiable' => false,
            'jobId' => $batch->id,
            'slots' => $slots,
        ]);
    }

    /** Batch status for the frontend to poll, owner-scoped via the batch name. */
    public function jobStatus(Request $request, string $batchId): JsonResponse
    {
        $batch = Bus::findBatch($batchId);

        abort_if($batch === null, 404, 'Unknown job.');
        abort_unless($this->ownsBatch($request, $batch), 403, 'This job belongs to another user.');

        return response()->json([
            'id' => $batch->id,
            'status' => $this->statusOf($batch),
            'total' => $batch->totalJobs,
            'pending' => $batch->pendingJobs,
            'failed' => $batch->failedJobs,
            'progress' => $batch->progress(),
        ]);
    }

    private function ownsBatch(Request $request, Batch $batch): bool
    {
        $parts = explode(':', (string) $batch->name);

        return count($parts) === 3 && (int) $parts[2] === $request->user()->id;
    }

    private function statusOf(Batch $batch): string
    {
        if ($batch->cancelled()) {
            return 'cancelled';
        }
        if ($batch->finished()) {
            return $batch->hasFailures() ? 'failed' : 'finished';
        }

        return 'processing';
    }
}
