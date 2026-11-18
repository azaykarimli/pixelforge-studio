<?php

namespace Tests\Feature\Billing;

use App\Billing\Money;
use App\Billing\QuoteCalculator;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class QuoteCalculatorTest extends TestCase
{
    use FakesPixelforge;

    public function test_the_most_a_generation_can_cost_matches_the_worked_example_in_the_docs(): void
    {
        $this->fakePixelforge();

        // The docs' example is Pixelforge's own cost, so price it without our markup.
        config(['billing.markup_bps' => 0]);

        $quote = app(QuoteCalculator::class)->quote(
            prompt: str_repeat('a', 400),
            size: ImageSize::Landscape,
            quality: ImageQuality::High,
            referenceImages: 2,
        );

        $this->assertSame('0.373875000', Money::format($quote->charge->max));
        $this->assertSame(115, $quote->lines['prompt']->tokens->max);
        $this->assertSame(1530, $quote->lines['reference_images']->tokens->max);
        $this->assertSame(6450, $quote->lines['image']->tokens->max);
        $this->assertSame(8000, $quote->lines['reasoning']->tokens->max);
    }

    public function test_a_quote_gives_the_expected_charge_and_its_range_including_markup(): void
    {
        $this->fakePixelforge();

        $quote = app(QuoteCalculator::class)->quote(
            prompt: str_repeat('a', 80),
            size: ImageSize::Square,
            quality: ImageQuality::Medium,
            referenceImages: 0,
        );

        // 20 text tokens (17 to 23), 1,100 output tokens, 200 to 2,000 reasoning tokens (expect 1,100), plus 25%.
        $this->assertSame(58_231_250, $quote->charge->min);
        $this->assertSame(72_312_500, $quote->charge->expected);
        $this->assertSame(86_393_750, $quote->charge->max);
        $this->assertSame(2500, $quote->markupBps);
    }

    public function test_the_breakdown_adds_up_to_the_total(): void
    {
        $this->fakePixelforge();

        $quote = app(QuoteCalculator::class)->quote('A lighthouse on a cliff at dawn', ImageSize::Portrait, ImageQuality::High, 3);

        foreach (['min', 'expected', 'max'] as $figure) {
            $this->assertSame(
                $quote->charge->{$figure},
                array_sum(array_map(fn ($line) => $line->charge->{$figure}, $quote->lines)),
            );
        }
    }

    public function test_prompt_tokens_count_unicode_characters_not_bytes(): void
    {
        $this->fakePixelforge();

        // Four emoji are four characters (one token), although they are sixteen bytes.
        $quote = app(QuoteCalculator::class)->quote('🎨🎨🎨🎨', ImageSize::Square, ImageQuality::Low, 0);

        $this->assertSame(1, $quote->lines['prompt']->tokens->expected);
    }

    public function test_an_empty_prompt_still_gets_a_price_for_the_image_itself(): void
    {
        $this->fakePixelforge();

        $quote = app(QuoteCalculator::class)->quote('', ImageSize::Square, ImageQuality::Low, 0);

        $this->assertSame(0, $quote->lines['prompt']->tokens->max);
        // 280 output tokens at $40 per 1M, plus 25%; reasoning can be zero at low quality.
        $this->assertSame(14_000_000, $quote->charge->min);
    }

    public function test_pricing_is_read_from_pixelforge_and_cached(): void
    {
        $this->fakePixelforge();

        app(QuoteCalculator::class)->quote('one', ImageSize::Square, ImageQuality::Low, 0);
        app(QuoteCalculator::class)->quote('two', ImageSize::Square, ImageQuality::Low, 0);

        Http::assertSentCount(1);
    }

    public function test_a_price_change_at_pixelforge_changes_the_quote(): void
    {
        $pricing = self::pricingFixture();
        $pricing['rates']['output_tokens'] = '80.00';

        $this->fakePixelforge(['*/v1/pricing' => Http::response($pricing)]);

        $quote = app(QuoteCalculator::class)->quote('', ImageSize::Square, ImageQuality::Low, 0);

        // Twice the output rate: 280 tokens at $80 per 1M, plus 25%.
        $this->assertSame(28_000_000, $quote->charge->min);
    }
}
