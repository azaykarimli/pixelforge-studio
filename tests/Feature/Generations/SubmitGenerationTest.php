<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    public function test_it_sends_the_generation_to_pixelforge_and_records_that_it_was_accepted(): void
    {
        $generation = Generation::factory()->create([
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
        ]);

        $this->fakePixelforge([
            '*/v1/generations' => Http::response($this->providerGeneration($generation, 'queued'), 202),
        ]);

        $job = $this->runJob($generation);

        $job->assertNotReleased();
        $job->assertNotFailed();

        Http::assertSent(function (Request $request) use ($generation) {
            return $request->method() === 'POST'
                && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
                && $request->hasHeader('Authorization', 'Bearer pf_test_key')
                && $request->hasHeader('Idempotency-Key', $generation->id)
                && $request->data() === [
                    'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
                    'size' => '1536x1024',
                    'quality' => 'high',
                    'metadata' => ['generation_id' => $generation->id],
                    'reference_images' => ['https://example.com/style.png'],
                ];
        });

        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertNotNull($generation->provider_id);
    }

    public function test_it_leaves_reference_images_out_when_there_are_none(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            '*/v1/generations' => Http::response($this->providerGeneration($generation, 'queued'), 202),
        ]);

        $this->runJob($generation);

        // Pixelforge rejects parameters it doesn't expect, so send only what is needed.
        Http::assertSent(fn (Request $request) => array_keys($request->data()) === ['prompt', 'size', 'quality', 'metadata']);
    }

    public function test_it_waits_without_calling_pixelforge_while_all_our_slots_are_taken(): void
    {
        $this->fakePixelforge();

        Generation::factory()->queued()->create();
        Generation::factory()->processing()->count(2)->create();
        $generation = Generation::factory()->create();

        $job = $this->runJob($generation);

        $job->assertReleased(delay: 5);
        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
    }

    public function test_finished_generations_do_not_take_up_a_slot(): void
    {
        $generation = Generation::factory()->create();
        Generation::factory()->succeeded()->count(3)->create();
        Generation::factory()->failed()->count(2)->create();

        $this->fakePixelforge([
            '*/v1/generations' => Http::response($this->providerGeneration($generation, 'queued'), 202),
        ]);

        $this->runJob($generation)->assertNotReleased();

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
    }

    public function test_a_concurrency_limit_means_waiting_as_long_as_pixelforge_says(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            '*/v1/generations' => $this->pixelforgeError(429, 'rate_limit_error', 'concurrency_limit_exceeded', ['Retry-After' => '5']),
        ]);

        $job = $this->runJob($generation);

        $job->assertReleased(delay: 5);
        $generation->refresh();
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(0, $generation->submission_attempts, 'Waiting for a slot is not a failed attempt.');
    }

    public function test_a_rate_limit_means_waiting_as_long_as_pixelforge_says(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            '*/v1/generations' => $this->pixelforgeError(429, 'rate_limit_error', 'rate_limit_exceeded', ['Retry-After' => '17']),
        ]);

        $this->runJob($generation)->assertReleased(delay: 17);

        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
    }

    public function test_an_overloaded_pixelforge_is_retried_with_growing_delays(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            '*/v1/generations' => $this->pixelforgeError(503, 'api_error', 'overloaded', ['Retry-After' => '2']),
        ]);

        $this->runJob($generation)->assertReleased(delay: 2);
        $this->runJob($generation)->assertReleased(delay: 4);
        $this->runJob($generation)->assertReleased(delay: 8);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(3, $generation->submission_attempts);
    }

    public function test_it_gives_up_after_a_few_overloads_and_the_user_pays_nothing(): void
    {
        $generation = Generation::factory()->create(['submission_attempts' => 3]);

        $this->fakePixelforge([
            '*/v1/generations' => $this->pixelforgeError(503, 'api_error', 'overloaded', ['Retry-After' => '2']),
        ]);

        $job = $this->runJob($generation);

        $job->assertNotReleased();
        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('overloaded', $generation->failure_code);
        $this->assertNull($generation->charge_nanos);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_a_timeout_is_retried_with_the_same_idempotency_key(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            '*/v1/generations' => Http::failedConnection('cURL error 28: Operation timed out'),
        ]);

        $this->runJob($generation)->assertReleased(delay: 2);
        $this->runJob($generation)->assertReleased(delay: 4);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->id));
        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
    }

    public function test_a_rejected_prompt_fails_straight_away_without_retrying(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            '*/v1/generations' => $this->pixelforgeError(400, 'invalid_request_error', 'content_policy_violation'),
        ]);

        $job = $this->runJob($generation);

        $job->assertNotReleased();
        Http::assertSentCount(1);
        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->failure_code);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_the_daily_limit_fails_straight_away_because_waiting_will_not_help(): void
    {
        $generation = Generation::factory()->create();

        $this->fakePixelforge([
            '*/v1/generations' => $this->pixelforgeError(429, 'rate_limit_error', 'daily_limit_exceeded'),
        ]);

        $this->runJob($generation)->assertNotReleased();

        $this->assertSame('daily_limit_exceeded', $generation->refresh()->failure_code);
    }

    public function test_nothing_is_sent_when_a_webhook_already_told_us_about_the_generation(): void
    {
        $this->fakePixelforge();

        $generation = Generation::factory()->processing()->create();

        $this->runJob($generation)->assertNotReleased();

        Http::assertNothingSent();
    }

    public function test_a_replayed_response_that_is_already_finished_is_settled(): void
    {
        $generation = Generation::factory()->create();

        // Our first attempt got through but its response was lost. The retry gets the
        // generation back "as it is now", which by this time is succeeded.
        $this->fakePixelforge([
            '*/v1/generations' => Http::response(
                $this->providerGeneration($generation, 'succeeded'), 202, ['Idempotent-Replayed' => 'true'],
            ),
        ]);

        $this->runJob($generation);

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
        $this->assertSame(-381_887_500, LedgerEntry::query()->sole()->amount_nanos);
    }

    public function test_when_the_deadline_passes_the_generation_fails_instead_of_waiting_forever(): void
    {
        $generation = Generation::factory()->create();

        (new SubmitGeneration($generation))->failed(new MaxAttemptsExceededException('Too long.'));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('timed_out', $generation->failure_code);
    }

    public function test_a_crashing_job_fails_the_generation_but_never_one_pixelforge_already_accepted(): void
    {
        $pending = Generation::factory()->create();
        $accepted = Generation::factory()->processing()->create();

        (new SubmitGeneration($pending))->failed(new RuntimeException('Boom.'));
        (new SubmitGeneration($accepted))->failed(new RuntimeException('Boom.'));

        $this->assertSame('unavailable', $pending->refresh()->failure_code);
        $this->assertSame(GenerationStatus::Processing, $accepted->refresh()->status);
    }

    /**
     * Run the job the way a queue worker would, recording whether it asked to be retried.
     */
    private function runJob(Generation $generation): SubmitGeneration
    {
        $job = (new SubmitGeneration($generation->fresh() ?? $generation))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        return $job;
    }

    /**
     * An error response in Pixelforge's documented shape.
     *
     * @param  array<string, string>  $headers
     */
    private function pixelforgeError(int $status, string $type, string $code, array $headers = []): mixed
    {
        return Http::response([
            'error' => ['type' => $type, 'code' => $code, 'message' => "Simulated {$code}."],
        ], $status, $headers);
    }
}
