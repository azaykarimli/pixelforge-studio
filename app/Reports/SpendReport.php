<?php

namespace App\Reports;

use App\Billing\Money;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Data\UsageDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Credit spend, provider cost and profit, for each day and each user.
 *
 * Days are UTC days, and a generation counts on the day it completed, not
 * the day it was requested. That is how Pixelforge bills, so each day's
 * provider cost here can be compared directly with GET /v1/usage.
 */
final class SpendReport
{
    /**
     * @param  array<string, UsageDay>|null  $usage  Pixelforge's own figures for the same range, or null if they couldn't be fetched.
     * @return array{days: list<array<string, mixed>>, users: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, ?array $usage): array
    {
        $lines = $this->lines($from, $to);
        $users = User::query()->findMany($lines->pluck('user_id')->filter()->unique())->keyBy('id');

        // Every day either side has something for. A day Pixelforge billed
        // that we have no record of must show up too: it is the kind of
        // difference this report exists to catch.
        $billedByPixelforge = collect($usage ?? [])
            ->filter(fn (UsageDay $day) => $day->generations > 0 || $day->costNanos > 0)
            ->keys();

        $days = [];

        // Newest day first.
        foreach ($lines->pluck('date')->merge($billedByPixelforge)->unique()->sortDesc() as $date) {
            $ofDay = $lines->where('date', $date);
            $total = $this->sum($ofDay);
            $reported = $usage === null ? null : ($usage[$date]->costNanos ?? 0);

            $days[] = [
                'date' => $date,
                ...$total->toArray(),
                'pixelforge_cost' => $reported === null ? null : Money::format($reported),
                'matches' => $reported === null ? null : $reported === $total->providerCostNanos,
                'users' => $this->perUser($ofDay, $users),
            ];
        }

        return [
            'days' => $days,
            'users' => $this->perUser($lines, $users),
            'totals' => $this->sum($lines)->toArray(),
        ];
    }

    /**
     * One line per day and user, straight from the database.
     *
     * @return Collection<int, array{date: string, user_id: int|null, totals: SpendTotals}>
     */
    private function lines(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('DATE(completed_at) as billed_on, user_id, COUNT(*) as generations, SUM(provider_cost_nanos) as provider_cost, SUM(charge_nanos) as spend')
            ->groupByRaw('DATE(completed_at), user_id')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'date' => (string) $row->billed_on,
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                // Sums of bigint columns come back as strings on PostgreSQL.
                'totals' => new SpendTotals((int) $row->generations, (int) $row->provider_cost, (int) $row->spend),
            ]);
    }

    /**
     * @param  Collection<int, array{date: string, user_id: int|null, totals: SpendTotals}>  $lines
     */
    private function sum(Collection $lines): SpendTotals
    {
        return $lines->reduce(
            fn (SpendTotals $sum, array $line) => $sum->plus($line['totals']),
            new SpendTotals,
        );
    }

    /**
     * Totals per user, biggest spender first. A user who has since deleted
     * their account is still counted, just without a name.
     *
     * @param  Collection<int, array{date: string, user_id: int|null, totals: SpendTotals}>  $lines
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    private function perUser(Collection $lines, Collection $users): array
    {
        $totalsByUser = $lines
            ->groupBy(fn (array $line) => $line['user_id'] ?? 'deleted')
            ->map(fn (Collection $ofUser) => $this->sum($ofUser))
            ->sortByDesc(fn (SpendTotals $totals) => $totals->spendNanos);

        $rows = [];

        foreach ($totalsByUser as $userId => $totals) {
            $user = $users->get($userId);

            $rows[] = [
                'user' => $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                ...$totals->toArray(),
            ];
        }

        return $rows;
    }
}
