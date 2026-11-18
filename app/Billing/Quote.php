<?php

namespace App\Billing;

/**
 * What a generation will cost the user, worked out before it is submitted.
 *
 * Part of the cost is only known once the image is done, so a quote is an
 * estimate: the charge we expect, and the least and most it can turn out to be.
 */
final readonly class Quote
{
    /**
     * @param  Estimate  $charge  What the user pays, in nanodollars.
     * @param  int  $markupBps  The markup this quote was priced with.
     * @param  array<string, QuoteLine>  $lines  The breakdown, keyed by "image", "reasoning", "prompt" and "reference_images".
     */
    public function __construct(
        public Estimate $charge,
        public int $markupBps,
        public array $lines,
    ) {}

    /**
     * @return array{estimated: string, min: string, max: string, lines: array<string, array{tokens: array{min: int, expected: int, max: int}, charge: array{min: string, expected: string, max: string}}>}
     */
    public function toArray(): array
    {
        return [
            'estimated' => Money::format($this->charge->expected),
            'min' => Money::format($this->charge->min),
            'max' => Money::format($this->charge->max),
            'lines' => array_map(fn (QuoteLine $line) => $line->toArray(), $this->lines),
        ];
    }
}
