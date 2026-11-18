<?php

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\Data\TokenUsage;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class TokenUsageTest extends TestCase
{
    public function test_it_reads_the_usage_object_of_a_succeeded_generation(): void
    {
        $usage = TokenUsage::fromApi([
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ]);

        $this->assertSame(12, $usage->inputText);
        $this->assertSame(765, $usage->inputImage);
        $this->assertSame(6450, $usage->output);
        $this->assertSame(3184, $usage->reasoning);
        $this->assertSame(10411, $usage->total());
    }

    public function test_it_stops_when_the_total_includes_tokens_we_do_not_price(): void
    {
        $this->expectException(UnexpectedValueException::class);

        TokenUsage::fromApi([
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10911, // 500 tokens of some new kind
        ]);
    }
}
