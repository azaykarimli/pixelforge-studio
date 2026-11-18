<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;

/**
 * Gives up on a generation that Pixelforge never accepted.
 *
 * Nothing was created at Pixelforge, so nothing is billed and the user pays
 * nothing. Failures that happen after Pixelforge accepted a generation are
 * reported by Pixelforge and handled by ApplyProviderSnapshot instead.
 */
final class FailGeneration
{
    public function __invoke(Generation $generation, string $code, ?string $message = null): void
    {
        DB::transaction(function () use ($generation, $code, $message): void {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            // Only a generation that is still waiting can be given up on. If
            // a webhook got here first, Pixelforge did accept it after all,
            // and whatever it reported stands.
            if ($generation->status !== GenerationStatus::Pending) {
                return;
            }

            $generation->update([
                'status' => GenerationStatus::Failed,
                'failure_code' => $code,
                'failure_message' => $message,
            ]);
        });
    }
}
