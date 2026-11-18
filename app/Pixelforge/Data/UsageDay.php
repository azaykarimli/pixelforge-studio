<?php

namespace App\Pixelforge\Data;

/**
 * What Pixelforge billed one API key on one UTC day, from GET /v1/usage.
 */
final readonly class UsageDay
{
    public function __construct(
        public string $date,
        public int $generations,
        public int $costNanos,
    ) {}
}
