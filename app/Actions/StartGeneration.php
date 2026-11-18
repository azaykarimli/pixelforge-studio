<?php

namespace App\Actions;

use App\Billing\QuoteCalculator;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;

/**
 * Accepts a user's request for an image and hands it to the queue.
 */
final class StartGeneration
{
    public function __construct(private readonly QuoteCalculator $quotes) {}

    /**
     * @param  list<string>  $referenceImages
     */
    public function __invoke(User $user, string $prompt, ImageSize $size, ImageQuality $quality, array $referenceImages): Generation
    {
        $quote = $this->quotes->quote($prompt, $size, $quality, count($referenceImages));

        $generation = $user->generations()->create([
            'status' => GenerationStatus::Pending,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'estimated_charge_nanos' => $quote->charge->expected,
            'max_charge_nanos' => $quote->charge->max,
            'markup_bps' => $quote->markupBps,
        ]);

        // The row is saved before Pixelforge hears about it, so a webhook
        // that arrives before our own create request returns still finds it.
        SubmitGeneration::dispatch($generation);

        return $generation;
    }
}
