<?php

namespace Tests\Feature\Credits;

use App\Actions\ApplyProviderSnapshot;
use App\Actions\FailGeneration;
use App\Billing\Money;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Pixelforge\Data\ProviderGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

/**
 * The standard generation in these tests is an 80-character prompt at medium
 * quality, 1024x1024. It is estimated at 72,312,500 n$ and can cost at most
 * 86,393,750 n$.
 */
class CreditsTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private const ESTIMATE = 72_312_500;

    private const MOST_IT_COULD_COST = 86_393_750;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakePixelforge();
    }

    public function test_a_new_user_starts_with_no_credit(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('credits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('credits/Index')
                ->where('credits.balance', '0.000000000')
                ->where('credits.reserved', '0.000000000')
                ->where('credits.available', '0.000000000')
                ->where('credits.packs', [5, 10, 25])
                ->has('entries.data', 0),
            );
    }

    public function test_buying_a_pack_adds_that_much_credit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('generations.index'))
            ->post(route('credits.store'), ['dollars' => 10])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('generations.index'));

        $entry = LedgerEntry::query()->sole();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame(LedgerEntryType::Purchase, $entry->type);
        $this->assertSame(10 * Money::NANOS_PER_DOLLAR, $entry->amount_nanos);
        $this->assertNull($entry->generation_id);

        $this->assertSame(10_000_000_000, $user->creditBalanceNanos());
    }

    public function test_only_the_packs_on_offer_can_be_bought(): void
    {
        $user = User::factory()->create();

        foreach ([7, 0, -10, 1_000_000, '10.5', null] as $dollars) {
            $this->actingAs($user)
                ->post(route('credits.store'), ['dollars' => $dollars])
                ->assertSessionHasErrors('dollars');
        }

        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_guests_cannot_see_or_buy_credits(): void
    {
        $this->get(route('credits.index'))->assertRedirect(route('login'));
        $this->post(route('credits.store'), ['dollars' => 10])->assertRedirect(route('login'));

        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_generating_is_blocked_without_credit(): void
    {
        $user = User::factory()->create();

        $this->generate($user)->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_most_an_image_could_cost_must_be_covered_not_just_the_estimate(): void
    {
        $justShort = User::factory()->withCredit(self::MOST_IT_COULD_COST - 1)->create();
        $this->assertGreaterThan(self::ESTIMATE, $justShort->creditBalanceNanos());

        $this->generate($justShort)->assertSessionHasErrors('credits');
        $this->assertSame(0, Generation::query()->count());

        $exactlyEnough = User::factory()->withCredit(self::MOST_IT_COULD_COST)->create();

        $this->generate($exactlyEnough)->assertSessionHasNoErrors();
        $this->assertSame(1, Generation::query()->count());
    }

    public function test_credit_set_aside_for_an_image_in_progress_cannot_be_spent_again(): void
    {
        // Enough for one image, and for the estimate of a second, but not for two at their most expensive.
        $user = User::factory()->withCredit(self::MOST_IT_COULD_COST + self::ESTIMATE)->create();

        $this->generate($user)->assertSessionHasNoErrors();

        $this->assertSame(self::MOST_IT_COULD_COST, $user->reservedCreditNanos());
        $this->assertSame(self::ESTIMATE, $user->availableCreditNanos());

        $this->generate($user)->assertSessionHasErrors('credits');

        $this->assertSame(1, Generation::query()->count());
    }

    public function test_when_an_image_succeeds_the_actual_cost_is_charged_and_the_rest_is_released(): void
    {
        $user = User::factory()->withCredit()->create();
        $this->generate($user)->assertSessionHasNoErrors();

        $generation = Generation::query()->sole();

        // The docs' example usage, which costs the user 381,887,500 n$.
        app(ApplyProviderSnapshot::class)(
            $generation,
            ProviderGeneration::fromApi($this->providerGeneration($generation, 'succeeded')),
        );

        $this->assertSame(0, $user->reservedCreditNanos());
        $this->assertSame(10_000_000_000 - 381_887_500, $user->creditBalanceNanos());
        $this->assertSame(10_000_000_000 - 381_887_500, $user->availableCreditNanos());
    }

    public function test_when_an_image_fails_everything_set_aside_is_released_and_nothing_is_charged(): void
    {
        $user = User::factory()->withCredit(self::MOST_IT_COULD_COST)->create();
        $this->generate($user)->assertSessionHasNoErrors();

        $rejected = Generation::query()->sole();
        $this->assertSame(0, $user->availableCreditNanos());

        // Rejected before Pixelforge accepted it.
        app(FailGeneration::class)($rejected, 'content_policy_violation');

        $this->assertSame(self::MOST_IT_COULD_COST, $user->availableCreditNanos());

        // Accepted by Pixelforge, then failed there.
        $this->generate($user)->assertSessionHasNoErrors();
        $failed = Generation::query()->where('status', GenerationStatus::Pending)->sole();

        app(ApplyProviderSnapshot::class)(
            $failed,
            ProviderGeneration::fromApi($this->providerGeneration($failed, 'failed')),
        );

        $this->assertSame(self::MOST_IT_COULD_COST, $user->creditBalanceNanos());
        $this->assertSame(self::MOST_IT_COULD_COST, $user->availableCreditNanos());
        $this->assertSame(1, LedgerEntry::query()->count(), 'Only the purchase is in the ledger.');
    }

    public function test_the_quote_says_whether_the_user_can_afford_the_image(): void
    {
        $input = ['prompt' => str_repeat('a', 80), 'size' => '1024x1024', 'quality' => 'medium'];

        $this->actingAs(User::factory()->withCredit(self::MOST_IT_COULD_COST - 1)->create())
            ->postJson(route('generations.quote'), $input)
            ->assertOk()
            ->assertJsonPath('affordable', false)
            ->assertJsonPath('available', '0.086393749');

        $this->actingAs(User::factory()->withCredit(self::MOST_IT_COULD_COST)->create())
            ->postJson(route('generations.quote'), $input)
            ->assertOk()
            ->assertJsonPath('affordable', true)
            ->assertJsonPath('max', '0.086393750');
    }

    public function test_the_credits_page_lists_the_users_own_purchases_and_charges_newest_first(): void
    {
        $user = User::factory()->withCredit()->create();

        $this->travel(1)->minutes();
        Generation::factory()->for($user)->succeeded()->create(['prompt' => 'A red fox in the snow']);

        // Someone else's history must never show up.
        Generation::factory()->succeeded()->create();
        User::factory()->withCredit()->create();

        $this->actingAs($user)
            ->get(route('credits.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 2)
                ->where('entries.data.0.type', 'generation_charge')
                ->where('entries.data.0.amount', '-0.072312500')
                ->where('entries.data.0.prompt', 'A red fox in the snow')
                ->where('entries.data.1.type', 'purchase')
                ->where('entries.data.1.amount', '10.000000000')
                ->where('entries.data.1.prompt', null)
                ->where('credits.balance', '9.927687500'),
            );
    }

    public function test_every_page_carries_the_users_credit_for_the_header(): void
    {
        $user = User::factory()->withCredit()->create();
        Generation::factory()->for($user)->processing()->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('credits.balance', '10.000000000')
                ->where('credits.reserved', '0.086393750')
                ->where('credits.available', '9.913606250'),
            );
    }

    private function generate(User $user): TestResponse
    {
        return $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => str_repeat('a', 80),
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);
    }
}
