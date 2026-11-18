<?php

namespace App\Reports;

use App\Billing\Money;

/**
 * One line of the spend report: how many generations succeeded, what
 * Pixelforge charged us for them, and what we charged users.
 */
final readonly class SpendTotals
{
    public function __construct(
        public int $generations = 0,
        public int $providerCostNanos = 0,
        public int $spendNanos = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->generations + $other->generations,
            $this->providerCostNanos + $other->providerCostNanos,
            $this->spendNanos + $other->spendNanos,
        );
    }

    /**
     * @return array{generations: int, spend: string, provider_cost: string, profit: string}
     */
    public function toArray(): array
    {
        return [
            'generations' => $this->generations,
            'spend' => Money::format($this->spendNanos),
            'provider_cost' => Money::format($this->providerCostNanos),
            'profit' => Money::format($this->spendNanos - $this->providerCostNanos),
        ];
    }
}
