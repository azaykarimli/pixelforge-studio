<?php

namespace App\Billing;

use RuntimeException;

/**
 * The user can't afford a generation: the most it could cost is more than
 * the credit they have available.
 */
final class InsufficientCredit extends RuntimeException
{
    public function __construct(
        public readonly int $neededNanos,
        public readonly int $availableNanos,
    ) {
        parent::__construct('Not enough credit for this generation.');
    }
}
