<?php

namespace Tests\Concerns;

use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

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

    /**
     * Pixelforge's generation object for one of our generations, in the given
     * status. The shape and the example numbers follow the Pixelforge docs.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function providerGeneration(Generation $generation, string $status, array $overrides = []): array
    {
        $providerId = $generation->provider_id ?? 'gen_'.Str::upper(str_replace('-', '', $generation->id));

        $forStatus = match ($status) {
            'processing' => [
                'started_at' => '2026-11-04T14:03:13Z',
            ],
            'succeeded' => [
                'image_url' => "https://pixelforge.promptedit.dev/images/{$providerId}.png?expires=1793887409&signature=abc",
                'image_url_expires_at' => '2026-11-05T14:03:29Z',
                'usage' => [
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 765,
                    'output_tokens' => 6450,
                    'reasoning_tokens' => 3184,
                    'total_tokens' => 10411,
                ],
                'started_at' => '2026-11-04T14:03:13Z',
                'completed_at' => '2026-11-04T14:03:29Z',
            ],
            'failed' => [
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Something went wrong on our side while generating. You were not charged; please retry.',
                ],
                'started_at' => '2026-11-04T14:03:13Z',
                'completed_at' => '2026-11-04T14:03:29Z',
            ],
            default => [],
        };

        return array_replace([
            'id' => $providerId,
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_id' => $generation->id],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => null,
            'completed_at' => null,
        ], $forStatus, $overrides);
    }

    /**
     * Deliver a webhook event about a generation, signed the way Pixelforge
     * signs it. Pass an event id to deliver the same event again.
     *
     * @param  array<string, mixed>  $providerGeneration
     */
    protected function deliverWebhook(string $type, array $providerGeneration, ?string $eventId = null): TestResponse
    {
        return $this->postSignedWebhook((string) json_encode([
            'id' => $eventId ?? 'evt_'.Str::upper(Str::random(26)),
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => ['object' => $providerGeneration],
        ]));
    }

    /**
     * POST a raw body to the webhook endpoint with a Pixelforge-Signature
     * header computed for it.
     */
    protected function postSignedWebhook(string $body, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret ?? config('pixelforge.webhook_secret'));

        return $this->call('POST', route('webhooks.pixelforge'), content: $body, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ]);
    }
}
