<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

trait FakesPixelforge
{
    /**
     * Fake the Pixelforge API. Pricing answers with Pixelforge's real
     * published response unless overridden, and any call that isn't faked
     * fails the test instead of reaching the network.
     *
     * @param  array<string, mixed>  $responses  URL pattern => response, as for Http::fake().
     */
    protected function fakePixelforge(array $responses = []): void
    {
        Http::preventStrayRequests();

        Http::fake($responses + [
            '*/v1/pricing' => Http::response(self::pricingFixture()),
        ]);
    }

    /**
     * The response of GET /v1/pricing, captured from the live API.
     *
     * @return array<string, mixed>
     */
    protected static function pricingFixture(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../Fixtures/pixelforge/pricing.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
