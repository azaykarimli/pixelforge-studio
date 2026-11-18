<?php

namespace App\Http\Middleware;

use App\Billing\Money;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'credits' => fn () => $this->credits($request->user()),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The signed-in user's credit, shown in the header of every page: what
     * they have, how much of it is set aside for generations in progress,
     * and what that leaves them to spend.
     *
     * @return array{balance: string, reserved: string, available: string, packs: list<int>}|null
     */
    private function credits(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        // Set-aside first, then balance: see User::availableCreditNanos().
        $reserved = $user->reservedCreditNanos();
        $balance = $user->creditBalanceNanos();

        return [
            'balance' => Money::format($balance),
            'reserved' => Money::format($reserved),
            'available' => Money::format($balance - $reserved),
            'packs' => config('billing.credit_packs'),
        ];
    }
}
