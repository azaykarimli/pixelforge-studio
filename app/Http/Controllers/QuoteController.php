<?php

namespace App\Http\Controllers;

use App\Billing\QuoteCalculator;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Requests\QuoteGenerationRequest;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\JsonResponse;

class QuoteController extends Controller
{
    /**
     * Price a generation before it is submitted. The form calls this as the
     * user types. Quotes are worked out here on the server, by the same code
     * that prices the generation when it is submitted, so the two can't drift.
     */
    public function __invoke(QuoteGenerationRequest $request, QuoteCalculator $quotes): JsonResponse
    {
        try {
            $quote = $quotes->quote(
                prompt: (string) $request->validated('prompt'),
                size: ImageSize::from($request->validated('size')),
                quality: ImageQuality::from($request->validated('quality')),
                referenceImages: count(array_filter($request->validated('reference_images', []))),
            );
        } catch (PixelforgeException) {
            return response()->json(['message' => 'Prices are unavailable right now.'], 503);
        }

        return response()->json($quote->toArray());
    }
}
