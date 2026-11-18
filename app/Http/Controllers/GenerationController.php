<?php

namespace App\Http\Controllers;

use App\Actions\StartGeneration;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * The studio: the form for a new image, and the user's own generations
     * with their status and cost. The page polls this for live updates,
     * which only ever reads our database, never Pixelforge.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('generations/Index', [
            'generations' => fn () => GenerationResource::collection(
                $request->user()->generations()->latest()->latest('id')->paginate(12),
            ),
        ]);
    }

    /**
     * Accept a new generation. It is only queued here: the image and its
     * final cost arrive later, and the page shows them when they do.
     */
    public function store(StoreGenerationRequest $request, StartGeneration $start): RedirectResponse
    {
        try {
            $start(
                $request->user(),
                prompt: $request->validated('prompt'),
                size: ImageSize::from($request->validated('size')),
                quality: ImageQuality::from($request->validated('quality')),
                referenceImages: $request->validated('reference_images', []),
            );
        } catch (PixelforgeException) {
            // Without current prices we can't say what this would cost,
            // so nothing is submitted rather than submitted unpriced.
            throw ValidationException::withMessages([
                'prompt' => 'We couldn\'t get current prices from Pixelforge, so nothing was submitted. Please try again in a moment.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your image is on its way.']);

        return to_route('generations.index');
    }
}
