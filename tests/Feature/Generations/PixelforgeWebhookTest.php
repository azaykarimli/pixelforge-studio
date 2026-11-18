<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    public function test_a_signed_event_updates_the_generation_and_is_recorded(): void
    {
        $generation = Generation::factory()->queued()->create();

        $this->deliverWebhook('generation.processing', $this->providerGeneration($generation, 'processing'), 'evt_1')
            ->assertNoContent();

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        $this->assertNotNull($generation->started_at);

        $event = WebhookEvent::query()->sole();
        $this->assertSame('evt_1', $event->id);
        $this->assertSame('generation.processing', $event->type);
        $this->assertSame(1, $event->deliveries);
    }

    public function test_a_succeeded_event_charges_the_user(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->deliverWebhook('generation.succeeded', $this->providerGeneration($generation, 'succeeded'))
            ->assertNoContent();

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
        $this->assertSame(381_887_500, $generation->charge_nanos);
        $this->assertSame(-381_887_500, LedgerEntry::query()->sole()->amount_nanos);
    }

    public function test_an_event_delivered_twice_is_recorded_and_charged_once(): void
    {
        $generation = Generation::factory()->processing()->create();
        $succeeded = $this->providerGeneration($generation, 'succeeded');

        $this->deliverWebhook('generation.succeeded', $succeeded, 'evt_same')->assertNoContent();
        $this->deliverWebhook('generation.succeeded', $succeeded, 'evt_same')->assertNoContent();
        $this->deliverWebhook('generation.succeeded', $succeeded, 'evt_same')->assertNoContent();

        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(3, WebhookEvent::query()->sole()->deliveries);
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_events_arriving_out_of_order_still_end_in_the_right_state(): void
    {
        $generation = Generation::factory()->queued()->create();

        // A retried "processing" event overtaken by "succeeded".
        $this->deliverWebhook('generation.succeeded', $this->providerGeneration($generation, 'succeeded'))->assertNoContent();
        $this->deliverWebhook('generation.processing', $this->providerGeneration($generation, 'processing'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(381_887_500, $generation->charge_nanos);
        $this->assertSame(2, WebhookEvent::query()->count());
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_a_failed_event_marks_the_generation_failed_and_charges_nothing(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->deliverWebhook('generation.failed', $this->providerGeneration($generation, 'failed'))->assertNoContent();

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame('internal_error', $generation->failure_code);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_an_event_that_arrives_before_the_create_response_still_finds_its_generation(): void
    {
        // Pending: our request to Pixelforge hasn't returned yet, so we don't know its id.
        $generation = Generation::factory()->create();
        $this->assertNull($generation->provider_id);

        $this->deliverWebhook('generation.processing', $this->providerGeneration($generation, 'processing'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertNotNull($generation->provider_id);
    }

    public function test_an_unsigned_request_is_rejected_and_changes_nothing(): void
    {
        $generation = Generation::factory()->processing()->create();

        $body = (string) json_encode([
            'id' => 'evt_forged',
            'type' => 'generation.succeeded',
            'data' => ['object' => $this->providerGeneration($generation, 'succeeded')],
        ]);

        $this->call('POST', route('webhooks.pixelforge'), content: $body, server: ['CONTENT_TYPE' => 'application/json'])
            ->assertStatus(400);

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_a_request_signed_with_the_wrong_secret_is_rejected(): void
    {
        $this->postSignedWebhook('{"id":"evt_1","type":"ping"}', secret: 'whsec_not_ours')->assertStatus(400);

        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_an_old_signature_is_rejected_so_requests_cannot_be_replayed(): void
    {
        $tenMinutesAgo = now()->subMinutes(10)->getTimestamp();

        $this->postSignedWebhook('{"id":"evt_1","type":"ping"}', timestamp: $tenMinutesAgo)->assertStatus(400);

        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_a_ping_from_the_dashboard_is_acknowledged(): void
    {
        $this->postSignedWebhook('{"id":"evt_ping","object":"event","type":"ping","created_at":"2026-11-04T14:03:29Z","data":{}}')
            ->assertNoContent();

        $this->assertSame('ping', WebhookEvent::query()->sole()->type);
    }

    public function test_an_event_for_a_generation_we_do_not_have_is_acknowledged(): void
    {
        $unknown = Generation::factory()->make(['id' => '019a0000-0000-7000-8000-000000000000']);

        $this->deliverWebhook('generation.succeeded', $this->providerGeneration($unknown, 'succeeded'))->assertNoContent();

        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_if_an_event_cannot_be_applied_it_is_not_recorded_so_the_retry_is_handled_afresh(): void
    {
        $generation = Generation::factory()->processing()->create();

        // Broken: succeeded, but without the usage we need to work out the cost.
        $this->deliverWebhook('generation.succeeded', $this->providerGeneration($generation, 'succeeded', ['usage' => null]), 'evt_retry')
            ->assertStatus(500);

        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);

        // Pixelforge retries the same event, this time intact.
        $this->deliverWebhook('generation.succeeded', $this->providerGeneration($generation, 'succeeded'), 'evt_retry')
            ->assertNoContent();

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
        $this->assertSame(1, LedgerEntry::query()->count());
    }
}
