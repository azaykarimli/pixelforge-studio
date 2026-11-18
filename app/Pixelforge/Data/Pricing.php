<?php

namespace App\Pixelforge\Data;

/**
 * Everything GET /v1/pricing publishes: the current rates, plus the rules for
 * how many tokens a generation will use. Estimates are built from this, so
 * none of these numbers are hardcoded in the app.
 */
final readonly class Pricing
{
    /**
     * @param  int  $textTokenVarianceBps  How far the real text token count can stray from the estimate, in basis points (1500 = 15%).
     * @param  array<string, array<string, int>>  $outputTokens  Exact output tokens, as [quality][size].
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  Possible reasoning tokens, by quality.
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBps,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array{rates: array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}, tokens: array{characters_per_text_token: int, text_token_variance: float, per_reference_image: int, output: array<string, array<string, int>>, reasoning: array<string, array{min: int, max: int}>}}  $pricing
     */
    public static function fromApi(array $pricing): self
    {
        $tokens = $pricing['tokens'];

        return new self(
            rates: new Rates($pricing['rates']),
            charactersPerTextToken: $tokens['characters_per_text_token'],
            // The one float Pixelforge sends (0.15). Turned into a whole number
            // straight away so estimates use integer arithmetic like the rest.
            textTokenVarianceBps: (int) round($tokens['text_token_variance'] * 10_000),
            tokensPerReferenceImage: $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }
}
