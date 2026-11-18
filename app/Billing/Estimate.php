<?php

namespace App\Billing;

use Closure;

/**
 * A quantity that isn't known exactly until a generation finishes: the
 * least it can be, what we expect, and the most it can be. Used for both
 * token counts and nanodollar amounts.
 */
final readonly class Estimate
{
    public function __construct(
        public int $min,
        public int $expected,
        public int $max,
    ) {}

    public static function exact(int $value): self
    {
        return new self($value, $value, $value);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->min + $other->min,
            $this->expected + $other->expected,
            $this->max + $other->max,
        );
    }

    /**
     * Apply the same calculation to the least, expected and most.
     *
     * @param  Closure(int): int  $calculate
     */
    public function map(Closure $calculate): self
    {
        return new self(
            $calculate($this->min),
            $calculate($this->expected),
            $calculate($this->max),
        );
    }
}
