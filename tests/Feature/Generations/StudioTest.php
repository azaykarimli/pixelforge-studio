<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class StudioTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('home'))->assertRedirect(route('generations.index'));
        $this->get(route('generations.index'))->assertRedirect(route('login'));
        $this->post(route('generations.store'))->assertRedirect(route('login'));
        $this->postJson(route('generations.quote'))->assertUnauthorized();
    }

    public function test_users_see_their_own_generations_newest_first_and_nobody_elses(): void
    {
        $user = User::factory()->create();

        $older = Generation::factory()->for($user)->succeeded()->create();
        $this->travel(1)->minutes();
        $newer = Generation::factory()->for($user)->processing()->create();

        Generation::factory()->succeeded()->create(); // someone else's

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Index')
                ->has('generations.data', 2)
                ->where('generations.data.0.id', $newer->id)
                ->where('generations.data.0.status', 'processing')
                ->where('generations.data.0.charge', null)
                ->where('generations.data.1.id', $older->id)
                ->where('generations.data.1.status', 'succeeded'),
            );
    }

    public function test_money_reaches_the_page_as_exact_decimal_strings(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.estimated_charge', '0.072312500')
                ->where('generations.data.0.max_charge', '0.086393750')
                ->where('generations.data.0.charge', '0.072312500')
                // 1,100 output tokens at $40 per 1M, plus 25%.
                ->where('generations.data.0.breakdown.image', ['tokens' => 1100, 'charge' => '0.055000000'])
                ->where('generations.data.0.breakdown.reasoning', ['tokens' => 1100, 'charge' => '0.017187500'])
                ->where('generations.data.0.breakdown.prompt', ['tokens' => 20, 'charge' => '0.000125000'])
                // What Pixelforge charged us and our markup are not the user's business.
                ->missing('generations.data.0.provider_cost_nanos')
                ->missing('generations.data.0.markup_bps'),
            );
    }

    public function test_submitting_the_form_saves_the_generation_and_queues_it(): void
    {
        Queue::fake();
        $this->fakePixelforge();

        $user = User::factory()->withCredit()->create();

        $this->actingAs($user)
            ->post(route('generations.store'), [
                'prompt' => '  A lighthouse on a cliff at dawn  ',
                'size' => '1536x1024',
                'quality' => 'high',
                'reference_images' => ['https://example.com/style.png'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('generations.index'));

        $generation = Generation::query()->sole();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame('A lighthouse on a cliff at dawn', $generation->prompt);
        $this->assertSame('1536x1024', $generation->size->value);
        $this->assertSame('high', $generation->quality->value);
        $this->assertSame(['https://example.com/style.png'], $generation->reference_images);

        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_invalid_requests_are_rejected_before_anything_is_saved_or_sent(): void
    {
        Queue::fake();
        $this->fakePixelforge();

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), [
                'prompt' => str_repeat('a', 4001),
                'size' => '2048x2048',
                'quality' => 'ultra',
                'reference_images' => ['ftp://example.com/a.png', 'not a link'],
            ])
            ->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images.0', 'reference_images.1']);

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), [
                'prompt' => '   ',
                'size' => '1024x1024',
                'quality' => 'low',
                'reference_images' => array_fill(0, 5, 'https://example.com/a.png'),
            ])
            ->assertSessionHasErrors(['prompt', 'reference_images']);

        $this->assertSame(0, Generation::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_nothing_is_submitted_when_current_prices_cannot_be_fetched(): void
    {
        Queue::fake();
        $this->fakePixelforge(['*/v1/pricing' => Http::response('Bad gateway', 502)]);

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), [
                'prompt' => 'A lighthouse',
                'size' => '1024x1024',
                'quality' => 'low',
            ])
            ->assertSessionHasErrors('prompt');

        $this->assertSame(0, Generation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_form_can_ask_what_a_generation_would_cost(): void
    {
        $this->fakePixelforge();

        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.quote'), [
                'prompt' => str_repeat('a', 80),
                'size' => '1024x1024',
                'quality' => 'medium',
                'reference_images' => [],
            ])
            ->assertOk()
            ->assertJson([
                'estimated' => '0.072312500',
                'min' => '0.058231250',
                'max' => '0.086393750',
                'lines' => [
                    'image' => ['tokens' => ['min' => 1100, 'expected' => 1100, 'max' => 1100]],
                    'reasoning' => ['tokens' => ['min' => 200, 'expected' => 1100, 'max' => 2000]],
                    'prompt' => ['tokens' => ['min' => 17, 'expected' => 20, 'max' => 23]],
                ],
            ]);
    }

    public function test_the_quote_is_the_same_number_that_gets_recorded(): void
    {
        Queue::fake();
        $this->fakePixelforge();

        $input = [
            'prompt' => 'A red fox in the snow, watercolour 🦊',
            'size' => '1024x1536',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ];

        $user = User::factory()->withCredit()->create();

        $quote = $this->actingAs($user)->postJson(route('generations.quote'), $input)->assertOk()->json();
        $this->actingAs($user)->post(route('generations.store'), $input)->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.estimated_charge', $quote['estimated'])
                ->where('generations.data.0.max_charge', $quote['max']),
            );
    }

    public function test_a_quote_can_be_given_while_the_form_is_still_incomplete(): void
    {
        $this->fakePixelforge();

        // No prompt yet, and one reference image link only half typed.
        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.quote'), [
                'prompt' => '',
                'size' => '1024x1024',
                'quality' => 'low',
                'reference_images' => ['https://exa', ''],
            ])
            ->assertOk()
            ->assertJsonPath('lines.prompt.tokens.max', 0)
            ->assertJsonPath('lines.reference_images.tokens.expected', 765);
    }

    public function test_the_quote_says_so_when_prices_are_unavailable(): void
    {
        $this->fakePixelforge(['*/v1/pricing' => Http::response('Bad gateway', 502)]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.quote'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertStatus(503);
    }
}
