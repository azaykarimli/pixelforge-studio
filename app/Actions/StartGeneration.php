<?php

namespace App\Actions;

use App\Billing\InsufficientCredit;
use App\Billing\QuoteCalculator;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Accepts a user's request for an image and hands it to the queue.
 */
final class StartGeneration
{
    public function __construct(private readonly QuoteCalculator $quotes) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws InsufficientCredit
     */
    public function __invoke(User $user, string $prompt, ImageSize $size, ImageQuality $quality, array $referenceImages): Generation
    {
        $quote = $this->quotes->quote($prompt, $size, $quality, count($referenceImages));

        $generation = DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $quote): Generation {
            // One request at a time per user. Without this lock, two requests
            // arriving together could each see enough credit for itself and
            // between them spend more than the user has.
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            // The final cost isn't known yet, so the user must be able to
            // afford the most it could be. That amount stays set aside (see
            // User::reservedCreditNanos) until the generation finishes.
            $available = $user->availableCreditNanos();

            if ($available < $quote->charge->max) {
                throw new InsufficientCredit(neededNanos: $quote->charge->max, availableNanos: $available);
            }

            return $user->generations()->create([
                'status' => GenerationStatus::Pending,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'estimated_charge_nanos' => $quote->charge->expected,
                'max_charge_nanos' => $quote->charge->max,
                'markup_bps' => $quote->markupBps,
            ]);
        });

        // The row is committed before Pixelforge hears about it, so a webhook
        // that arrives before our own create request returns still finds it.
        SubmitGeneration::dispatch($generation);

        return $generation;
    }
}
