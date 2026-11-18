<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class SyncGenerationsTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    public function test_a_generation_whose_webhook_never_arrived_is_fetched_and_settled(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->fakePixelforge([
            "*/v1/generations/{$generation->provider_id}" => Http::response($this->providerGeneration($generation, 'succeeded')),
        ]);

        // Nothing has been heard about it for two minutes.
        $this->travel(2)->minutes();
        $this->artisan('pixelforge:sync')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer pf_test_key'));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(381_887_500, $generation->charge_nanos);
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_a_failure_we_missed_is_picked_up_too(): void
    {
        $generation = Generation::factory()->queued()->create();

        $this->fakePixelforge([
            "*/v1/generations/{$generation->provider_id}" => Http::response($this->providerGeneration($generation, 'failed')),
        ]);

        $this->travel(2)->minutes();
        $this->artisan('pixelforge:sync')->assertSuccessful();

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_it_leaves_alone_what_does_not_need_checking(): void
    {
        $this->fakePixelforge();

        // Updated moments ago: its webhooks are probably just on their way.
        Generation::factory()->processing()->create();

        $this->travel(30)->seconds();

        // Finished: there is nothing more to learn.
        Generation::factory()->succeeded()->create();
        Generation::factory()->failed()->create();

        $this->artisan('pixelforge:sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_when_pixelforge_cannot_be_asked_the_rest_waits_for_the_next_run(): void
    {
        [$first, $second] = Generation::factory()->processing()->count(2)->create();

        $this->fakePixelforge([
            '*/v1/generations/*' => Http::response([
                'error' => ['type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded', 'message' => 'Too many requests.'],
            ], 429, ['Retry-After' => '30']),
        ]);

        $this->travel(2)->minutes();
        $this->artisan('pixelforge:sync')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(GenerationStatus::Processing, $first->refresh()->status);
        $this->assertSame(GenerationStatus::Processing, $second->refresh()->status);
    }

    public function test_one_generation_that_cannot_be_applied_does_not_hold_up_the_others(): void
    {
        [$broken, $fine] = Generation::factory()->processing()->count(2)->create();

        $this->fakePixelforge([
            // Succeeded, but without the usage needed to work out its cost.
            "*/v1/generations/{$broken->provider_id}" => Http::response($this->providerGeneration($broken, 'succeeded', ['usage' => null])),
            "*/v1/generations/{$fine->provider_id}" => Http::response($this->providerGeneration($fine, 'succeeded')),
        ]);

        $this->travel(2)->minutes();
        $this->artisan('pixelforge:sync')->assertSuccessful();

        $this->assertSame(GenerationStatus::Processing, $broken->refresh()->status);
        $this->assertSame(GenerationStatus::Succeeded, $fine->refresh()->status);
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_a_generation_that_was_never_submitted_is_failed_once_its_job_is_clearly_lost(): void
    {
        $this->fakePixelforge();

        $lost = Generation::factory()->create();

        $this->travel(21)->minutes();

        $waiting = Generation::factory()->create();

        $this->artisan('pixelforge:sync')->assertSuccessful();

        $lost->refresh();
        $this->assertSame(GenerationStatus::Failed, $lost->status);
        $this->assertSame('timed_out', $lost->failure_code);
        $this->assertSame(GenerationStatus::Pending, $waiting->refresh()->status);
    }
}
