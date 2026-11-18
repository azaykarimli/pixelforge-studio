<?php

namespace App\Actions;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\Data\ProviderGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Records a webhook event from Pixelforge and acts on it, exactly once.
 */
final class HandleWebhookEvent
{
    public function __construct(private readonly ApplyProviderSnapshot $apply) {}

    /**
     * @param  array<string, mixed>  $event  The decoded webhook body.
     */
    public function __invoke(array $event): void
    {
        // Recording the event and acting on it share one transaction. If
        // acting on it fails, the record is rolled back too, so when
        // Pixelforge retries the delivery we handle it as if for the first time.
        DB::transaction(function () use ($event): void {
            $record = WebhookEvent::query()->firstOrCreate(
                ['id' => $event['id']],
                ['type' => $event['type'], 'payload' => $event],
            );

            // Pixelforge delivers at least once, so the same event can turn
            // up again. Its id is our primary key: a repeat finds the row
            // already there, and we stop before doing anything twice.
            if (! $record->wasRecentlyCreated) {
                $record->increment('deliveries');

                return;
            }

            // A "ping" from the dashboard, or an event type added later.
            if (! str_starts_with($event['type'], 'generation.')) {
                return;
            }

            $snapshot = ProviderGeneration::fromApi($event['data']['object']);
            $generation = $this->generationFor($snapshot);

            if ($generation === null) {
                Log::warning('Received a Pixelforge event for a generation we do not have.', [
                    'event' => $event['id'],
                    'provider_id' => $snapshot->id,
                ]);

                return;
            }

            ($this->apply)($generation, $snapshot);
        });
    }

    /**
     * Find our row for a Pixelforge generation by our own id, which we sent
     * as metadata and Pixelforge echoes back. Unlike Pixelforge's id, we know
     * it from the start, so this works even for a webhook that arrives before
     * the response to our create request does.
     */
    private function generationFor(ProviderGeneration $snapshot): ?Generation
    {
        return Str::isUuid($snapshot->localId)
            ? Generation::query()->find($snapshot->localId)
            : null;
    }
}
