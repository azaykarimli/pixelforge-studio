<?php

namespace App\Http\Controllers;

use App\Actions\HandleWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    /**
     * Receive a webhook from Pixelforge. The signature has already been
     * checked by VerifyPixelforgeSignature.
     *
     * Handling an event is a single short database transaction with no calls
     * to other services, so it is done here rather than on the queue. That
     * way a 2xx means the event really was applied, and if anything goes
     * wrong Pixelforge's own retries deliver it again.
     */
    public function __invoke(Request $request, HandleWebhookEvent $handle): Response
    {
        $handle($request->json()->all());

        return response()->noContent();
    }
}
