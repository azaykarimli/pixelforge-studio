<?php

namespace App\Pixelforge\Data;

use UnexpectedValueException;

/**
 * The tokens a succeeded generation actually used.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /**
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int}  $usage
     */
    public static function fromApi(array $usage): self
    {
        $tokens = new self(
            inputText: $usage['input_text_tokens'],
            inputImage: $usage['input_image_tokens'],
            output: $usage['output_tokens'],
            reasoning: $usage['reasoning_tokens'],
        );

        // If Pixelforge ever bills a kind of token we don't know about, the
        // total stops adding up. Better to stop than to record too low a cost.
        if ($tokens->total() !== $usage['total_tokens']) {
            throw new UnexpectedValueException(
                "Pixelforge reported {$usage['total_tokens']} total tokens, but the token types we price add up to {$tokens->total()}.",
            );
        }

        return $tokens;
    }

    public function total(): int
    {
        return $this->inputText + $this->inputImage + $this->output + $this->reasoning;
    }
}
