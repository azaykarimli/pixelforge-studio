<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Reports\SpendReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SpendReportController extends Controller
{
    /**
     * Pixelforge's usage report covers at most this many days per request.
     */
    private const MAX_DAYS = 92;

    /**
     * For operators: what users were charged, what Pixelforge charged us and
     * the difference, per day and per user, with each day's provider cost
     * set against Pixelforge's own usage report.
     */
    public function __invoke(Request $request, SpendReport $report, PixelforgeClient $pixelforge): Response
    {
        [$from, $to] = $this->range($request);

        try {
            $usage = $pixelforge->usage($from, $to);
        } catch (PixelforgeException $exception) {
            // Our own numbers are still worth showing without the comparison.
            Log::warning('Could not fetch the usage report from Pixelforge.', ['message' => $exception->getMessage()]);

            $usage = null;
        }

        return Inertia::render('admin/SpendReport', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'report' => $report->build($from, $to, $usage),
            // Whether Pixelforge's own figures could be fetched to compare with.
            'compared' => $usage !== null,
            // Generations Pixelforge may already have billed that we haven't
            // recorded yet: the usual reason for a day not to match.
            'inFlight' => Generation::query()->inFlight()->count(),
        ]);
    }

    /**
     * The days to report on: UTC days, both ends included, and the last 30
     * unless asked otherwise. The same conventions as Pixelforge's report.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $to = CarbonImmutable::parse($validated['to'] ?? 'today');
        $from = CarbonImmutable::parse($validated['from'] ?? $to->subDays(29));

        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'The first day must not be after the last day.']);
        }

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'The report can cover '.self::MAX_DAYS.' days at most.']);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }
}
