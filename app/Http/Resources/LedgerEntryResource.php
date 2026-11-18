<?php

namespace App\Http\Resources;

use App\Billing\Money;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            // Signed: negative for a charge, positive for a purchase.
            'amount' => Money::format($this->amount_nanos),
            'prompt' => $this->generation?->prompt,
            'created_at' => $this->created_at,
        ];
    }
}
