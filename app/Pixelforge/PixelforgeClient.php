<?php

namespace App\Pixelforge;

use App\Pixelforge\Data\Pricing;
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
