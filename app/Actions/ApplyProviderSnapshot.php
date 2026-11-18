<?php

namespace App\Actions;

use App\Billing\CostCalculator;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Pixelforge\Data\ProviderGeneration;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Brings a generation up to date with what Pixelforge says about it.
 *
 * Every update from Pixelforge goes through here, however it arrived: the
 * response to our create request, a webhook, or the polling fallback. Each
 * one carries a full snapshot of the generation, so one rule covers them
 * all: a snapshot is applied only if it moves the generation forward.
 * Duplicates and late arrivals change nothing.
 */
final class ApplyProviderSnapshot
{
    public function __invoke(Generation $generation, ProviderGeneration $snapshot): void
    {
        DB::transaction(function () use ($generation, $snapshot): void {
            // Several snapshots can arrive at the same moment: an event and
            // its duplicate, or a webhook and a poll. Locking the row makes
            // them take turns, and only the first finds anything left to do.
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if (! $generation->shouldAdvanceTo($snapshot->status)) {
                return;
            }

            $generation->fill([
                'provider_id' => $snapshot->id,
                'status' => $snapshot->status,
                'rates' => $snapshot->rates->perMillionTokens,
                'started_at' => $snapshot->startedAt,
                'completed_at' => $snapshot->completedAt,
                'failure_code' => $snapshot->errorCode,
                'failure_message' => $snapshot->errorMessage,
            ]);

            if ($snapshot->status === GenerationStatus::Succeeded) {
                $this->settle($generation, $snapshot);
            }

            $generation->save();
        });
    }

    /**
     * Record what a succeeded generation cost, and charge the user for it.
     *
     * This is the only place a user is ever charged. It runs inside the
     * transaction that marks the generation succeeded, which happens once,
     * and the ledger allows one entry per generation. A failed generation
     * never gets here, so it costs nothing.
     */
    private function settle(Generation $generation, ProviderGeneration $snapshot): void
    {
        $usage = $snapshot->usage
            ?? throw new UnexpectedValueException("Pixelforge reported [{$snapshot->id}] as succeeded without any usage.");

        // Pixelforge never sends a price. The cost is its token counts times
        // the rates fixed for this generation when it was created.
        $providerCost = CostCalculator::providerCost($usage, $snapshot->rates);
        $charge = CostCalculator::charge($providerCost, $generation->markup_bps);

        $generation->fill([
            'input_text_tokens' => $usage->inputText,
            'input_image_tokens' => $usage->inputImage,
            'output_tokens' => $usage->output,
            'reasoning_tokens' => $usage->reasoning,
            'provider_cost_nanos' => $providerCost,
            'charge_nanos' => $charge,
            'image_url' => $snapshot->imageUrl,
            'image_url_expires_at' => $snapshot->imageUrlExpiresAt,
        ]);

        LedgerEntry::query()->create([
            'user_id' => $generation->user_id,
            'type' => LedgerEntryType::GenerationCharge,
            'amount_nanos' => -$charge,
            'generation_id' => $generation->id,
        ]);
    }
}
