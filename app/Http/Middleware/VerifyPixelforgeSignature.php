<?php

namespace App\Http\Middleware;

use App\Pixelforge\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPixelforgeSignature
{
    /**
     * Anyone can POST to the webhook URL, so nothing gets past here unless
     * it carries a valid, recent Pixelforge signature.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $valid = WebhookSignature::isValid(
            header: (string) $request->header('Pixelforge-Signature'),
            payload: $request->getContent(),
            secret: (string) config('pixelforge.webhook_secret'),
            tolerance: config('pixelforge.webhook_tolerance'),
            now: now()->getTimestamp(),
        );

        abort_unless($valid, 400, 'Invalid Pixelforge signature.');

        return $next($request);
    }
}
