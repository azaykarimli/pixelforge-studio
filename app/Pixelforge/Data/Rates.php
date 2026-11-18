<?php

namespace App\Pixelforge\Data;

use App\Billing\Money;
use InvalidArgumentException;

/**
 * Pixelforge's price for each kind of token, in nanodollars per single token.
 */
final readonly class Rates
{
    public int $inputText;

    public int $inputImage;

    public int $output;

    public int $reasoning;

    /**
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $perMillionTokens  Dollars per 1M tokens, exactly as Pixelforge reports them.
     */
    public function __construct(public array $perMillionTokens)
    {
        $this->inputText = self::nanosPerToken($perMillionTokens['input_text_tokens']);
        $this->inputImage = self::nanosPerToken($perMillionTokens['input_image_tokens']);
        $this->output = self::nanosPerToken($perMillionTokens['output_tokens']);
        $this->reasoning = self::nanosPerToken($perMillionTokens['reasoning_tokens']);
    }

    /**
     * "12.50" dollars per 1M tokens is 12,500,000,000 n$ per 1M tokens,
     * which is exactly 12,500 n$ per token.
     */
    private static function nanosPerToken(string $dollarsPerMillionTokens): int
    {
        $nanosPerMillionTokens = Money::parse($dollarsPerMillionTokens);

        // A finer rate couldn't be billed exactly in nanodollars. Stopping
        // here is better than recording costs that are slightly wrong.
        if ($nanosPerMillionTokens % 1_000_000 !== 0) {
            throw new InvalidArgumentException(
                "The rate [{$dollarsPerMillionTokens}] per 1M tokens is not a whole number of nanodollars per token.",
            );
        }

        return intdiv($nanosPerMillionTokens, 1_000_000);
    }
}
