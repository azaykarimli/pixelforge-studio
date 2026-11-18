<?php

namespace App\Http\Resources;

use App\Billing\CostCalculator;
use App\Billing\Money;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Data\Rates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as its owner sees it. Money goes to the browser as exact
 * decimal strings, and only what the user pays is included: Pixelforge's
 * cost and our markup stay on the server.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_charge' => Money::format($this->estimated_charge_nanos),
            'max_charge' => Money::format($this->max_charge_nanos),
            'charge' => $this->charge_nanos === null ? null : Money::format($this->charge_nanos),
            'breakdown' => $this->breakdown(),
            'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
        ];
    }

    /**
     * How the final charge splits across the kinds of token, once known.
     *
     * @return array<string, array{tokens: int, charge: string}>|null
     */
    private function breakdown(): ?array
    {
        if ($this->status !== GenerationStatus::Succeeded || $this->rates === null) {
            return null;
        }

        $rates = new Rates($this->rates);

        $line = fn (?int $tokens, int $nanosPerToken): array => [
            'tokens' => (int) $tokens,
            'charge' => Money::format(CostCalculator::charge((int) $tokens * $nanosPerToken, $this->markup_bps)),
        ];

        return [
            'image' => $line($this->output_tokens, $rates->output),
            'reasoning' => $line($this->reasoning_tokens, $rates->reasoning),
            'prompt' => $line($this->input_text_tokens, $rates->inputText),
            'reference_images' => $line($this->input_image_tokens, $rates->inputImage),
        ];
    }
}
