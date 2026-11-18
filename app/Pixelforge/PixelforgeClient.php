<?php

namespace App\Pixelforge;

use App\Billing\Money;
use App\Models\Generation;
use App\Pixelforge\Data\Pricing;
use App\Pixelforge\Data\ProviderGeneration;
use App\Pixelforge\Data\UsageDay;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The only place that talks HTTP to Pixelforge.
 *
 * Every failure surfaces as a PixelforgeException. Retrying is left to the
 * caller, which knows whether waiting is worthwhile.
 */
final class PixelforgeClient
{
    /**
     * Ask Pixelforge to start a generation. It answers straight away with
     * the generation in the "queued" status; the result comes later.
     *
     * @throws PixelforgeException
     */
    public function createGeneration(Generation $generation): ProviderGeneration
    {
        $parameters = [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            // Pixelforge echoes metadata back on every webhook. Sending our
            // own id lets us match an event to its row even when the event
            // arrives before this very request has returned.
            'metadata' => ['generation_id' => $generation->id],
        ];

        if ($generation->reference_images !== []) {
            $parameters['reference_images'] = $generation->reference_images;
        }

        $response = $this->send('POST', 'generations', $parameters, headers: [
            // The same key on every attempt: however often this request is
            // retried, Pixelforge creates (and bills) one generation at most.
            'Idempotency-Key' => $generation->id,
        ]);

        return ProviderGeneration::fromApi($response->json());
    }

    /**
     * @throws PixelforgeException
     */
    public function getGeneration(string $providerId): ProviderGeneration
    {
        return ProviderGeneration::fromApi($this->send('GET', "generations/{$providerId}")->json());
    }

    /**
     * What Pixelforge billed this API key on each UTC day in a range (both
     * ends included, 92 days at most). This is Pixelforge's own record of
     * our costs, and the one ours has to agree with.
     *
     * @return array<string, UsageDay> Keyed by date, "2026-11-04".
     *
     * @throws PixelforgeException
     */
    public function usage(CarbonInterface $from, CarbonInterface $to): array
    {
        $response = $this->send('GET', 'usage', [
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
        ]);

        $days = [];

        foreach ($response->json('data') as $day) {
            $days[$day['date']] = new UsageDay($day['date'], $day['generations'], Money::parse($day['cost_usd']));
        }

        return $days;
    }

    /**
     * The current rates and the rules for counting tokens.
     *
     * Rates can change, so they are read from Pixelforge rather than
     * hardcoded. The answer is fresh for a minute; after that the cached copy
     * keeps being served while it is refreshed in the background.
     */
    public function pricing(): Pricing
    {
        return Pricing::fromApi(Cache::flexible(
            'pixelforge.pricing',
            [60, 86400],
            fn () => $this->send('GET', 'pricing', authenticated: false)->json(),
        ));
    }

    /**
     * @param  array<string, mixed>  $data  Sent as the query string for GET and as JSON otherwise.
     * @param  array<string, string>  $headers
     *
     * @throws PixelforgeException
     */
    private function send(string $method, string $path, array $data = [], array $headers = [], bool $authenticated = true): Response
    {
        $request = Http::baseUrl(config('pixelforge.base_url'))
            ->acceptJson()
            ->withHeaders($headers)
            ->connectTimeout(config('pixelforge.connect_timeout'))
            ->timeout(config('pixelforge.timeout'));

        if ($authenticated) {
            $request->withToken(config('pixelforge.api_key'));
        }

        try {
            $response = $request->send($method, $path, [$method === 'GET' ? 'query' : 'json' => $data]);
        } catch (ConnectionException $exception) {
            throw PixelforgeException::unreachable($exception);
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response;
    }
}
