<?php

namespace Tests\Feature\Admin;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

/**
 * A generation from the factory costs us 57,850,000 n$ and the user
 * 72,312,500 n$, unless a test says otherwise.
 */
class SpendReportTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-05 12:00:00');
    }

    public function test_only_admins_can_see_the_report(): void
    {
        $this->fakeUsage([]);

        $this->get(route('admin.spend'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route('admin.spend'))->assertForbidden();

        $this->actingAs($this->admin())->get(route('admin.spend'))->assertOk();
    }

    public function test_it_shows_spend_provider_cost_and_profit_for_each_day_and_user(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        Generation::factory()->for($alice)->succeeded()->count(2)->create(['completed_at' => '2026-11-04 09:00:00']);
        Generation::factory()->for($alice)->succeeded()->create(['completed_at' => '2026-11-03 18:30:00']);
        Generation::factory()->for($bob)->succeeded()->create([
            'completed_at' => '2026-11-04 23:59:59',
            'provider_cost_nanos' => 305_510_000,
            'charge_nanos' => 381_887_500,
        ]);

        // Neither of these cost anyone anything.
        Generation::factory()->for($alice)->failed()->create(['completed_at' => '2026-11-04 10:00:00']);
        Generation::factory()->for($bob)->processing()->create();

        $this->fakeUsage([
            '2026-11-03' => ['generations' => 1, 'cost_usd' => '0.057850000'],
            '2026-11-04' => ['generations' => 3, 'cost_usd' => '0.421210000'],
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.spend'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/SpendReport')
                ->where('from', '2026-10-07')
                ->where('to', '2026-11-05')
                ->where('compared', true)
                ->where('inFlight', 1)
                ->has('report.days', 2)

                // Newest day first: two of Alice's images and one of Bob's.
                ->where('report.days.0.date', '2026-11-04')
                ->where('report.days.0.generations', 3)
                ->where('report.days.0.provider_cost', '0.421210000')
                ->where('report.days.0.spend', '0.526512500')
                ->where('report.days.0.profit', '0.105302500')
                ->where('report.days.0.pixelforge_cost', '0.421210000')
                ->where('report.days.0.matches', true)
                ->has('report.days.0.users', 2)
                ->where('report.days.0.users.0.user.name', 'Bob')
                ->where('report.days.0.users.0.spend', '0.381887500')
                ->where('report.days.0.users.0.provider_cost', '0.305510000')
                ->where('report.days.0.users.0.profit', '0.076377500')
                ->where('report.days.0.users.1.user.name', 'Alice')
                ->where('report.days.0.users.1.generations', 2)
                ->where('report.days.0.users.1.spend', '0.144625000')

                ->where('report.days.1.date', '2026-11-03')
                ->where('report.days.1.generations', 1)
                ->where('report.days.1.provider_cost', '0.057850000')
                ->where('report.days.1.spend', '0.072312500')
                ->where('report.days.1.profit', '0.014462500')
                ->where('report.days.1.matches', true)

                // Per user over the whole range, biggest spender first.
                ->has('report.users', 2)
                ->where('report.users.0.user.name', 'Bob')
                ->where('report.users.0.generations', 1)
                ->where('report.users.1.user.name', 'Alice')
                ->where('report.users.1.generations', 3)
                ->where('report.users.1.spend', '0.216937500')

                ->where('report.totals.generations', 4)
                ->where('report.totals.provider_cost', '0.479060000')
                ->where('report.totals.spend', '0.598825000')
                ->where('report.totals.profit', '0.119765000'),
            );
    }

    public function test_an_image_counts_on_the_utc_day_it_completed_not_the_day_it_was_requested(): void
    {
        Generation::factory()->succeeded()->create([
            'created_at' => '2026-11-03 23:59:50',
            'completed_at' => '2026-11-04 00:00:10',
        ]);

        $this->fakeUsage(['2026-11-04' => ['generations' => 1, 'cost_usd' => '0.057850000']]);

        $this->actingAs($this->admin())
            ->get(route('admin.spend'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('report.days', 1)
                ->where('report.days.0.date', '2026-11-04')
                ->where('report.days.0.matches', true),
            );
    }

    public function test_a_day_that_differs_from_pixelforges_record_is_flagged(): void
    {
        Generation::factory()->succeeded()->create(['completed_at' => '2026-11-04 09:00:00']);

        // One nanodollar more than we recorded.
        $this->fakeUsage(['2026-11-04' => ['generations' => 1, 'cost_usd' => '0.057850001']]);

        $this->actingAs($this->admin())
            ->get(route('admin.spend'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.days.0.provider_cost', '0.057850000')
                ->where('report.days.0.pixelforge_cost', '0.057850001')
                ->where('report.days.0.matches', false),
            );
    }

    public function test_a_day_pixelforge_billed_that_we_have_no_record_of_still_shows_up(): void
    {
        $this->fakeUsage(['2026-11-04' => ['generations' => 1, 'cost_usd' => '0.057850000']]);

        $this->actingAs($this->admin())
            ->get(route('admin.spend'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('report.days', 1)
                ->where('report.days.0.date', '2026-11-04')
                ->where('report.days.0.generations', 0)
                ->where('report.days.0.provider_cost', '0.000000000')
                ->where('report.days.0.pixelforge_cost', '0.057850000')
                ->where('report.days.0.matches', false),
            );
    }

    public function test_it_asks_pixelforge_about_the_same_days_using_our_api_key(): void
    {
        $this->fakeUsage([]);

        $this->actingAs($this->admin())->get(route('admin.spend', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://pixelforge.promptedit.dev/v1/usage?')
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer pf_test_key')
            && $request->data() === ['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    }

    public function test_our_own_numbers_are_still_shown_when_pixelforge_cannot_be_reached(): void
    {
        Generation::factory()->succeeded()->create(['completed_at' => '2026-11-04 09:00:00']);

        $this->fakePixelforge(['*/v1/usage*' => Http::response('Bad gateway', 502)]);

        $this->actingAs($this->admin())
            ->get(route('admin.spend'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('compared', false)
                ->where('report.days.0.provider_cost', '0.057850000')
                ->where('report.days.0.pixelforge_cost', null)
                ->where('report.days.0.matches', null),
            );
    }

    public function test_spending_by_a_since_deleted_account_is_still_counted(): void
    {
        $leaver = User::factory()->create();
        Generation::factory()->for($leaver)->succeeded()->create(['completed_at' => '2026-11-04 09:00:00']);
        $leaver->delete();

        $this->fakeUsage(['2026-11-04' => ['generations' => 1, 'cost_usd' => '0.057850000']]);

        $this->actingAs($this->admin())
            ->get(route('admin.spend'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.days.0.matches', true)
                ->where('report.days.0.users.0.user', null)
                ->where('report.days.0.users.0.spend', '0.072312500'),
            );
    }

    public function test_the_range_is_limited_to_what_pixelforge_can_report(): void
    {
        $this->fakeUsage([]);
        $admin = $this->admin();

        // 92 days is the most Pixelforge's usage report covers.
        $this->actingAs($admin)->get(route('admin.spend', ['from' => '2026-08-06', 'to' => '2026-11-05']))->assertOk();
        $this->actingAs($admin)->get(route('admin.spend', ['from' => '2026-08-05', 'to' => '2026-11-05']))->assertSessionHasErrors('from');
        $this->actingAs($admin)->get(route('admin.spend', ['from' => '2026-11-05', 'to' => '2026-11-01']))->assertSessionHasErrors('from');
        $this->actingAs($admin)->get(route('admin.spend', ['from' => 'last week']))->assertSessionHasErrors('from');
    }

    public function test_an_operator_makes_an_account_an_admin_from_the_command_line(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.com']);
        $this->assertFalse($user->is_admin);

        $this->artisan('app:make-admin', ['email' => 'Operator@Example.com'])->assertSuccessful();
        $this->assertTrue($user->refresh()->is_admin);

        $this->artisan('app:make-admin', ['email' => 'nobody@example.com'])->assertFailed();
    }

    public function test_nobody_can_make_themselves_an_admin_by_registering(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_admin' => true,
        ]);

        $this->assertFalse(User::query()->where('email', 'mallory@example.com')->sole()->is_admin);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    /**
     * Fake GET /v1/usage in Pixelforge's documented shape.
     *
     * @param  array<string, array{generations: int, cost_usd: string}>  $days
     */
    private function fakeUsage(array $days): void
    {
        $data = [];

        foreach ($days as $date => $day) {
            $data[] = [
                'date' => $date,
                'generations' => $day['generations'],
                'input_text_tokens' => 0,
                'input_image_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'cost_usd' => $day['cost_usd'],
            ];
        }

        $this->fakePixelforge([
            '*/v1/usage*' => Http::response(['object' => 'usage', 'data' => $data]),
        ]);
    }
}
