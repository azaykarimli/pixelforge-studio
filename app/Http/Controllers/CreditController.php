<?php

namespace App\Http\Controllers;

use App\Billing\Money;
use App\Enums\LedgerEntryType;
use App\Http\Resources\LedgerEntryResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /**
     * Every purchase and charge behind the user's balance. The balance itself
     * is shared with every page (see HandleInertiaRequests).
     */
    public function index(Request $request): Response
    {
        return Inertia::render('credits/Index', [
            'entries' => fn () => LedgerEntryResource::collection(
                $request->user()->ledgerEntries()->with('generation')->latest('id')->paginate(20),
            ),
        ]);
    }

    /**
     * Buy a pack of credits. This is simulated: no payment is taken, the
     * credit is simply added to the user's ledger.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dollars' => ['required', 'integer', Rule::in(config('billing.credit_packs'))],
        ]);

        $request->user()->ledgerEntries()->create([
            'type' => LedgerEntryType::Purchase,
            'amount_nanos' => $validated['dollars'] * Money::NANOS_PER_DOLLAR,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "\${$validated['dollars']} of credit added."]);

        return back();
    }
}
