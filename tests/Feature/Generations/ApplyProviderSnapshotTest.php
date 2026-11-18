<?php

namespace Tests\Feature\Generations;

use App\Actions\ApplyProviderSnapshot;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Pixelforge\Data\ProviderGeneration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;
use UnexpectedValueException;

class ApplyProviderSnapshotTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    public function test_pixelforge_accepting_a_generation_links_it_to_pixelforges_id(): void
    {
        $generation = Generation::factory()->create();

        $this->apply($generation, 'queued');

        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertStringStartsWith('gen_', (string) $generation->provider_id);
        $this->assertSame('40.00', $generation->rates['output_tokens'] ?? null);
    }

    public function test_a_succeeded_generation_is_recorded_at_exactly_the_right_cost(): void
    {
        $generation = Generation::factory()->processing()->create();

        // The docs' example: 12 text, 765 image, 6,450 output and 3,184 reasoning tokens.
        $this->apply($generation, 'succeeded');

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(12, $generation->input_text_tokens);
        $this->assertSame(765, $generation->input_image_tokens);
        $this->assertSame(6450, $generation->output_tokens);
        $this->assertSame(3184, $generation->reasoning_tokens);
        $this->assertSame(305_510_000, $generation->provider_cost_nanos);   // $0.305510000
        $this->assertSame(381_887_500, $generation->charge_nanos);          // plus 25%
        $this->assertNotNull($generation->image_url);
        $this->assertSame('2026-11-05T14:03:29+00:00', $generation->image_url_expires_at?->toIso8601String());
        $this->assertSame('2026-11-04T14:03:29+00:00', $generation->completed_at?->toIso8601String());
    }

    public function test_the_user_is_charged_through_the_ledger(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->apply($generation, 'succeeded');

        $entry = LedgerEntry::query()->sole();
        $this->assertSame($generation->user_id, $entry->user_id);
        $this->assertSame($generation->id, $entry->generation_id);
        $this->assertSame(LedgerEntryType::GenerationCharge, $entry->type);
        $this->assertSame(-381_887_500, $entry->amount_nanos);
    }

    public function test_the_same_result_arriving_again_never_charges_twice(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->apply($generation, 'succeeded');
        $this->apply($generation, 'succeeded');
        $this->apply($generation, 'succeeded');

        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertSame(381_887_500, $generation->refresh()->charge_nanos);
    }

    public function test_the_database_itself_refuses_a_second_charge_for_a_generation(): void
    {
        $generation = Generation::factory()->succeeded()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        LedgerEntry::query()->create([
            'user_id' => $generation->user_id,
            'type' => LedgerEntryType::GenerationCharge,
            'amount_nanos' => -1,
            'generation_id' => $generation->id,
        ]);
    }

    public function test_a_late_processing_update_does_not_move_a_finished_generation_back(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->apply($generation, 'succeeded');
        $this->apply($generation, 'processing');
        $this->apply($generation, 'queued');

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(381_887_500, $generation->charge_nanos);
        $this->assertNotNull($generation->completed_at);
    }

    public function test_updates_can_skip_steps_when_earlier_ones_never_arrived(): void
    {
        // Still pending: neither the create response nor the "processing" event got through.
        $generation = Generation::factory()->create();

        $this->apply($generation, 'succeeded');

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertNotNull($generation->provider_id);
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_a_failed_generation_costs_nothing(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->apply($generation, 'failed');

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('internal_error', $generation->failure_code);
        $this->assertNotNull($generation->failure_message);
        $this->assertNull($generation->charge_nanos);
        $this->assertNull($generation->provider_cost_nanos);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_a_failed_generation_cannot_later_become_succeeded_or_the_other_way_round(): void
    {
        $failed = Generation::factory()->processing()->create();
        $this->apply($failed, 'failed');
        $this->apply($failed, 'succeeded');

        $succeeded = Generation::factory()->processing()->create();
        $this->apply($succeeded, 'succeeded');
        $this->apply($succeeded, 'failed');

        $this->assertSame(GenerationStatus::Failed, $failed->refresh()->status);
        $this->assertSame(GenerationStatus::Succeeded, $succeeded->refresh()->status);
        $this->assertNull($succeeded->failure_code);
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_the_cost_uses_the_rates_fixed_on_the_generation_not_todays_prices(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->apply($generation, 'succeeded', ['rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '80.00',   // twice the usual rate
            'reasoning_tokens' => '12.50',
        ]]);

        // 12 x 5,000 + 765 x 10,000 + 6,450 x 80,000 + 3,184 x 12,500 nanodollars.
        $this->assertSame(563_510_000, $generation->refresh()->provider_cost_nanos);
        $this->assertSame('80.00', $generation->rates['output_tokens'] ?? null);
    }

    public function test_the_markup_is_the_one_in_force_when_the_generation_was_requested(): void
    {
        $generation = Generation::factory()->processing()->create(['markup_bps' => 1000]);

        config(['billing.markup_bps' => 5000]);
        $this->apply($generation, 'succeeded');

        $this->assertSame(336_061_000, $generation->refresh()->charge_nanos);   // cost plus 10%
    }

    public function test_a_failure_we_declared_ourselves_yields_if_pixelforge_did_create_the_generation(): void
    {
        // We gave up submitting (say, every attempt timed out), so no provider id was ever stored.
        $generation = Generation::factory()->create([
            'status' => GenerationStatus::Failed,
            'failure_code' => 'unavailable',
        ]);

        // It turns out one attempt did get through: Pixelforge generated the image and billed us for it.
        $this->apply($generation, 'succeeded');

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertNull($generation->failure_code);
        $this->assertSame(305_510_000, $generation->provider_cost_nanos);
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_a_success_without_usage_is_refused_rather_than_recorded_as_free(): void
    {
        $generation = Generation::factory()->processing()->create();

        try {
            $this->apply($generation, 'succeeded', ['usage' => null]);
            $this->fail('A succeeded generation without usage should not be accepted.');
        } catch (UnexpectedValueException) {
            // Expected.
        }

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_pixelforges_timestamps_are_stored_as_utc_whatever_offset_they_arrive_with(): void
    {
        $generation = Generation::factory()->processing()->create();

        // 01:30 on the 5th at +02:00 is 23:30 on the 4th in UTC, the day Pixelforge bills it on.
        $this->apply($generation, 'succeeded', ['completed_at' => '2026-11-05T01:30:00+02:00']);

        $this->assertSame('2026-11-04 23:30:00', $generation->refresh()->getRawOriginal('completed_at'));
    }

    public function test_costs_are_still_recorded_after_the_user_deleted_their_account(): void
    {
        $generation = Generation::factory()->processing()->create();
        $generation->user?->delete();

        $this->apply($generation, 'succeeded');

        $generation->refresh();
        $this->assertNull($generation->user_id);
        $this->assertSame(305_510_000, $generation->provider_cost_nanos);
        $this->assertNull(LedgerEntry::query()->sole()->user_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function apply(Generation $generation, string $status, array $overrides = []): void
    {
        app(ApplyProviderSnapshot::class)(
            $generation,
            ProviderGeneration::fromApi($this->providerGeneration($generation, $status, $overrides)),
        );
    }
}
