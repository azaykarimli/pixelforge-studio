<?php

namespace App\Billing;

/**
 * One row of a quote's breakdown: how many tokens of one kind a generation
 * will use, and what the user pays for them.
 */
final readonly class QuoteLine
{
    public function __construct(
        public Estimate $tokens,
        public Estimate $charge,
    ) {}

    /**
     * @return array{tokens: array{min: int, expected: int, max: int}, charge: array{min: string, expected: string, max: string}}
     */
    public function toArray(): array
    {
        return [
            'tokens' => [
                'min' => $this->tokens->min,
                'expected' => $this->tokens->expected,
                'max' => $this->tokens->max,
            ],
            'charge' => [
                'min' => Money::format($this->charge->min),
                'expected' => Money::format($this->charge->expected),
                'max' => Money::format($this->charge->max),
            ],
        ];
    }
}
