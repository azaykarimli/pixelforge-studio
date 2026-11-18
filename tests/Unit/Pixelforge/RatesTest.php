<?php

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\Data\Rates;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RatesTest extends TestCase
{
    public function test_dollars_per_million_tokens_become_nanodollars_per_token(): void
    {
        $rates = new Rates([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);

        $this->assertSame(5_000, $rates->inputText);
        $this->assertSame(10_000, $rates->inputImage);
        $this->assertSame(40_000, $rates->output);
        $this->assertSame(12_500, $rates->reasoning);
    }

    public function test_the_smallest_rate_that_still_bills_exactly_is_accepted(): void
    {
        $rates = new Rates([
            'input_text_tokens' => '0.001',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);

        $this->assertSame(1, $rates->inputText);
    }

    public function test_a_rate_too_fine_to_bill_exactly_is_refused_rather_than_rounded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Rates([
            'input_text_tokens' => '0.0005',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
    }
}
