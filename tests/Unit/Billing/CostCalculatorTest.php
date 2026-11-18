<?php

namespace Tests\Unit\Billing;

use App\Billing\CostCalculator;
use App\Billing\Money;
use App\Pixelforge\Data\Rates;
use App\Pixelforge\Data\TokenUsage;
use PHPUnit\Framework\TestCase;

/**
 * The expected numbers come straight from the worked examples in the
 * Pixelforge docs, so these tests pin our arithmetic to theirs.
 */
class CostCalculatorTest extends TestCase
{
    private Rates $rates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rates = new Rates([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
    }

    public function test_provider_cost_matches_the_usage_example_in_the_docs(): void
    {
        // GET /v1/usage example: 41 text + 3,300 output + 2,950 reasoning tokens = "0.169080000".
        $usage = new TokenUsage(inputText: 41, inputImage: 0, output: 3300, reasoning: 2950);

        $this->assertSame('0.169080000', Money::format(CostCalculator::providerCost($usage, $this->rates)));
    }

    public function test_provider_cost_matches_the_succeeded_generation_example_in_the_docs(): void
    {
        $usage = new TokenUsage(inputText: 12, inputImage: 765, output: 6450, reasoning: 3184);

        // 12 x 5,000 + 765 x 10,000 + 6,450 x 40,000 + 3,184 x 12,500 nanodollars.
        $this->assertSame(305_510_000, CostCalculator::providerCost($usage, $this->rates));
    }

    public function test_the_most_expensive_generation_in_the_docs(): void
    {
        $usage = new TokenUsage(inputText: 115, inputImage: 1530, output: 6450, reasoning: 8000);

        $this->assertSame('0.373875000', Money::format(CostCalculator::providerCost($usage, $this->rates)));
    }

    public function test_the_user_pays_cost_plus_a_quarter(): void
    {
        $this->assertSame(381_887_500, CostCalculator::charge(305_510_000, markupBps: 2500));
        $this->assertSame(0, CostCalculator::charge(0, markupBps: 2500));
    }

    public function test_a_fraction_of_a_nanodollar_is_rounded_up_never_down(): void
    {
        $this->assertSame(5, CostCalculator::charge(4, markupBps: 2500));  // exactly 5
        $this->assertSame(2, CostCalculator::charge(1, markupBps: 2500));  // 1.25 rounds up
        $this->assertSame(4, CostCalculator::charge(3, markupBps: 2500));  // 3.75 rounds up
    }

    public function test_no_markup_means_the_charge_equals_the_cost(): void
    {
        $this->assertSame(305_510_000, CostCalculator::charge(305_510_000, markupBps: 0));
    }
}
