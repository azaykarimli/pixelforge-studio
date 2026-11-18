<?php

namespace App\Pixelforge;

final class WebhookSignature
{
    /**
     * Whether a webhook really comes from Pixelforge.
     *
     * The Pixelforge-Signature header reads "t=<unix time>,v1=<signature>",
     * where the signature is the hex HMAC-SHA256 of "<t>.<raw request body>",
     * keyed with our API key's signing secret.
     *
     * @param  string  $payload  The request body exactly as received, before any JSON decoding.
     * @param  int  $tolerance  How many seconds old a signature may be.
     * @param  int  $now  The current unix time.
     */
    public static function isValid(string $header, string $payload, string $secret, int $tolerance, int $now): bool
    {
        // Without a secret, an HMAC proves nothing: anyone can sign with an
        // empty key. Refuse everything until one is configured.
        if ($secret === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[$name] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp)) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        // hash_equals compares in constant time, so the response time gives
        // nothing away about how much of a forged signature was right. The
        // age check stops a captured request from being replayed later.
        return hash_equals($expected, $signature)
            && abs($now - (int) $timestamp) <= $tolerance;
    }
}
