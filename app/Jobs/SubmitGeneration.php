<?php

namespace App\Jobs;

use App\Actions\ApplyProviderSnapshot;
use App\Actions\FailGeneration;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one generation to Pixelforge, waiting its turn while Pixelforge is busy.
 */
final class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds to wait before looking for a free slot again.
     */
    private const WAIT_FOR_SLOT = 5;

    /**
     * A job that keeps throwing has hit a bug, not a busy provider.
     */
    public int $maxExceptions = 3;

    public function __construct(public Generation $generation) {}

    /**
     * Waiting for a slot isn't a failure, so this job has a deadline instead
     * of a number of attempts: it can go back on the queue as often as it
     * needs to until then.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(config('pixelforge.submission_deadline_minutes'));
    }

    public function handle(PixelforgeClient $pixelforge, ApplyProviderSnapshot $apply, FailGeneration $fail): void
    {
        $generation = $this->generation;

        // A webhook may already have told us about this generation, or we
        // may already have given up on it. Either way there's nothing to send.
        if ($generation->status !== GenerationStatus::Pending) {
            return;
        }

        // Pixelforge only runs a few generations per account at once. While
        // our slots are taken, wait here rather than spend rate limit on a
        // request that would only be turned away.
        if (Generation::query()->atProvider()->count() >= config('pixelforge.max_concurrent_generations')) {
            $this->release(self::WAIT_FOR_SLOT);

            return;
        }

        try {
            $snapshot = $pixelforge->createGeneration($generation);
        } catch (PixelforgeException $exception) {
            $this->handleRejection($generation, $exception, $fail);

            return;
        }

        $apply($generation, $snapshot);
    }

    /**
     * Pixelforge didn't accept the request. Decide whether to wait, to try
     * again, or to give up. In every case nothing was created, so nothing
     * is billed.
     */
    private function handleRejection(Generation $generation, PixelforgeException $exception, FailGeneration $fail): void
    {
        // Rate or concurrency limit: nothing is wrong, it just isn't our
        // turn yet. Come back when Pixelforge says to.
        if ($exception->isCapacityLimit()) {
            $this->release($exception->retryAfter ?? self::WAIT_FOR_SLOT);

            return;
        }

        // Overloaded, an outage or a timeout: worth a few more tries. Wait
        // longer each time, and never less than Pixelforge asked for. The
        // idempotency key makes a retry safe even if the first attempt did
        // get through and only its response was lost.
        if ($exception->isTransient()) {
            $generation->increment('submission_attempts');

            if ($generation->submission_attempts < config('pixelforge.max_submission_attempts')) {
                $this->release(max($exception->retryAfter ?? 0, 2 ** $generation->submission_attempts));

                return;
            }
        }

        // A definite no (a rejected prompt, the daily limit, a bad request),
        // or one transient failure too many.
        Log::warning('Pixelforge did not accept a generation.', [
            'generation' => $generation->id,
            'status' => $exception->status,
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
        ]);

        $fail($generation, $exception->errorCode ?? 'unavailable', $exception->getMessage());
    }

    /**
     * The deadline passed while waiting for a slot, or the job kept
     * throwing. Don't leave the generation waiting forever.
     */
    public function failed(?Throwable $exception): void
    {
        app(FailGeneration::class)(
            $this->generation,
            $exception instanceof MaxAttemptsExceededException ? 'timed_out' : 'unavailable',
            $exception?->getMessage(),
        );
    }
}
