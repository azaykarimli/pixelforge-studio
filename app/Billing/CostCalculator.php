<?php

namespace App\Billing;

use App\Pixelforge\Data\Rates;
use App\Pixelforge\Data\TokenUsage;

final class CostCalculator
{
    /**
     * What Pixelforge bills us for a finished generation, in nanodollars.
     *
     * Pixelforge never returns a price, only token counts, so this has to
     * agree with its own arithmetic exactly: tokens times rate, summed.
     */
    public static function providerCost(TokenUsage $usage, Rates $rates): int
    {
        return $usage->inputText * $rates->inputText
            + $usage->inputImage * $rates->inputImage
            + $usage->output * $rates->output
            + $usage->reasoning * $rates->reasoning;
    }

    /**
     * What the user pays for a given provider cost: the cost plus our markup.
     *
     * The markup is in basis points (2500 = 25%). If the result isn't a whole
     * number of nanodollars it is rounded up, so we never charge less than
     * cost plus markup. At Pixelforge's current rates it is always whole.
     */
    public static function charge(int $providerCostNanos, int $markupBps): int
    {
        return intdiv($providerCostNanos * (10_000 + $markupBps) + 9_999, 10_000);
    }
}
