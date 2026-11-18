<?php

namespace App\Billing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Pixelforge\Data\Pricing;
use App\Pixelforge\PixelforgeClient;

/**
 * Works out what a generation will cost the user before it is submitted.
 *
 * The same quote is shown on the form and recorded on the generation, so the
 * number a user sees is the number we store.
 */
final class QuoteCalculator
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    public function quote(string $prompt, ImageSize $size, ImageQuality $quality, int $referenceImages): Quote
    {
        $pricing = $this->pixelforge->pricing();
        $markupBps = (int) config('billing.markup_bps');

        // Each kind of token: how many to expect, and Pixelforge's price for one.
        $usage = [
            'image' => [Estimate::exact($pricing->outputTokens[$quality->value][$size->value]), $pricing->rates->output],
            'reasoning' => [$this->reasoningTokens($pricing, $quality), $pricing->rates->reasoning],
            'prompt' => [$this->promptTokens($pricing, $prompt), $pricing->rates->inputText],
            'reference_images' => [Estimate::exact($referenceImages * $pricing->tokensPerReferenceImage), $pricing->rates->inputImage],
        ];

        $charge = fn (int $providerCost): int => CostCalculator::charge($providerCost, $markupBps);

        $providerCost = Estimate::exact(0);
        $lines = [];

        foreach ($usage as $name => [$tokens, $nanosPerToken]) {
            $cost = $tokens->map(fn (int $count): int => $count * $nanosPerToken);

            $providerCost = $providerCost->plus($cost);
            $lines[$name] = new QuoteLine($tokens, $cost->map($charge));
        }

        // The markup goes on the total, exactly as it will on the real cost.
        return new Quote($providerCost->map($charge), $markupBps, $lines);
    }

    /**
     * Pixelforge counts about one token per 4 characters (Unicode characters,
     * not bytes), and the real count lands within 15% of ceil(characters / 4).
     */
    private function promptTokens(Pricing $pricing, string $prompt): Estimate
    {
        $perToken = $pricing->charactersPerTextToken;
        $variance = $pricing->textTokenVarianceBps;

        $expected = intdiv(mb_strlen($prompt) + $perToken - 1, $perToken);

        return new Estimate(
            min: intdiv($expected * (10_000 - $variance), 10_000),
            expected: $expected,
            max: intdiv($expected * (10_000 + $variance) + 9_999, 10_000),
        );
    }

    /**
     * The model "thinks" before it draws. How much is only known afterwards,
     * but it always falls in a range set by quality. With nothing else to go
     * on, we expect the middle of that range.
     */
    private function reasoningTokens(Pricing $pricing, ImageQuality $quality): Estimate
    {
        ['min' => $min, 'max' => $max] = $pricing->reasoningTokens[$quality->value];

        return new Estimate($min, intdiv($min + $max, 2), $max);
    }
}
