<?php

namespace App\Console\Commands;

use App\Actions\ApplyProviderSnapshot;
use App\Actions\FailGeneration;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncGenerations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pixelforge:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Catch up on generations whose webhooks never arrived';

    /**
     * Webhooks are the fast path, but Pixelforge doesn't guarantee delivery.
     * This is the safety net, run every minute by the scheduler: anything
     * that has been quiet for too long is looked up directly.
     */
    public function handle(PixelforgeClient $pixelforge, ApplyProviderSnapshot $apply, FailGeneration $fail): int
    {
        $quietSince = now()->subSeconds(config('pixelforge.stale_after_seconds'));

        // Accepted by Pixelforge, but we haven't heard anything for a while.
        // At most a handful, since Pixelforge only runs a few at a time.
        $quiet = Generation::query()->atProvider()->where('updated_at', '<', $quietSince)->oldest('updated_at')->get();

        foreach ($quiet as $generation) {
            try {
                $apply($generation, $pixelforge->getGeneration((string) $generation->provider_id));
            } catch (PixelforgeException $exception) {
                Log::warning('Could not fetch a generation from Pixelforge.', [
                    'generation' => $generation->id,
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ]);

                // Pixelforge is limiting or unreachable. Leave the rest for
                // the next run instead of piling on more requests.
                break;
            }
        }

        // Still not accepted by Pixelforge well after the submit job's own
        // deadline: that job has been lost. Don't leave the user waiting.
        $deadline = now()->subMinutes(config('pixelforge.submission_deadline_minutes') + 5);

        Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('created_at', '<', $deadline)
            ->get()
            ->each(fn (Generation $generation) => $fail($generation, 'timed_out'));

        return self::SUCCESS;
    }
}
