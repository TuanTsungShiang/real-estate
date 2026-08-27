<?php

namespace App\Http\Controllers;

use App\Models\RealEstateTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class TransactionController extends Controller
{
    private const DEFAULT_PER_PAGE = 30;

    private const MAX_PER_PAGE = 100;

    /** Above this many priced rows, per-month lookups beat reading them all. */
    private const MEDIAN_IN_MEMORY_ROWS = 200000;



    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $transactions = RealEstateTransaction::query()
            ->filtered($filters)
            ->latest('transaction_date')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return view('transactions.index', [
            'transactions' => $transactions,
            'cities' => $this->cities(),
            'districts' => $this->districts($filters['city']),
            'filters' => $filters,
            'summary' => $this->summaryQuery($filters),
            'trend' => $this->trendQuery($filters),
        ]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        $transactions = RealEstateTransaction::query()
            ->filtered($filters)
            ->latest('transaction_date')
            ->paginate($this->perPage($request));

        return response()->json($transactions);
    }

    public function summary(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json($this->summaryQuery($filters) + [
            'districts' => $this->districtBreakdown($filters),
        ]);
    }

    public function trend(Request $request): JsonResponse
    {
        return response()->json([
            'months' => $this->trendQuery($this->filters($request)),
        ]);
    }

    /**
     * Monthly volume and average price for whatever the current filters select,
     * which is what turns a district or a street into a trend line.
     *
     * @param array<string, mixed> $filters
     * @return Collection<int, object>
     */
    private function trendQuery(array $filters): Collection
    {
        $base = RealEstateTransaction::query()
            ->filtered($filters)
            ->whereNotNull('transaction_month');

        // The chart and the table both stop at MAX_TREND_MONTHS, so there is no
        // point aggregating - or running a window function over - every month
        // back to 2010 just to throw the result away.
        $latest = (clone $base)->max('transaction_month');

        if ($latest === null) {
            return collect();
        }

        $base->where(
            'transaction_month',
            '>=',
            Carbon::createFromFormat('Y-m', $latest)->startOfMonth()->subMonths($this->trendMonths() - 1)->format('Y-m'),
        );

        $volume = (clone $base)
            ->select('transaction_month as month')
            ->selectRaw('COUNT(*) as total_records')
            // COUNT of a column skips nulls, so land rows with no unit price
            // still count toward volume but not toward the price series.
            ->selectRaw('COUNT(unit_price_ping) as priced_records')
            ->selectRaw('ROUND(AVG(unit_price_ping)) as avg_unit_price_ping')
            ->selectRaw('ROUND(AVG(total_price)) as avg_total_price')
            ->groupBy('transaction_month')
            ->orderBy('transaction_month')
            ->get()
            ->keyBy('month');

        if ($volume->isEmpty()) {
            return collect();
        }

        return $this->fillMonths(
            $volume,
            $this->medianByMonth($base, $volume->map(fn ($row) => (int) $row->priced_records)->all()),
        );
    }

    /**
     * Median rather than mean: a single 4 億 luxury sale drags a month's average
     * far enough to flatten the whole line.
     *
     * One indexed lookup per month rather than a window function. The
     * (transaction_month, unit_price_ping, ...) indexes already hold the rows in
     * exactly this order, so each month is a short range scan - and unlike
     * ROW_NUMBER() this also runs on MySQL 5.7, which has no window functions.
     *
     * @param array<string, int> $pricedCounts priced rows per month
     * @return array<string, int|null>
     */
    private function medianByMonth(Builder $base, array $pricedCounts): array
    {
        $pricedCounts = array_filter($pricedCounts, fn (int $count) => $count > 0);
        $total = array_sum($pricedCounts);

        if ($total === 0) {
            return [];
        }

        // One query per month is cheap when the filter is index-served, but a
        // filter the index cannot narrow - a fulltext address match, say - pays
        // its cost on every one of them. Below this many rows it is cheaper to
        // pay it once and pick the middles in PHP.
        return $total <= self::MEDIAN_IN_MEMORY_ROWS
            ? $this->mediansFromRows($base, $pricedCounts)
            : $this->mediansByLookup($base, $pricedCounts);
    }

    /**
     * @param array<string, int> $pricedCounts
     * @return array<string, int|null>
     */
    private function mediansFromRows(Builder $base, array $pricedCounts): array
    {
        $values = [];

        // chunkById, not chunk: paging over a non-unique order skips and
        // repeats rows. The per-month sort happens here instead.
        (clone $base)
            ->whereNotNull('unit_price_ping')
            ->select(['id', 'transaction_month', 'unit_price_ping'])
            ->chunkById(50000, function ($rows) use (&$values): void {
                foreach ($rows as $row) {
                    $values[$row->transaction_month][] = (int) $row->unit_price_ping;
                }
            });

        $medians = [];

        foreach ($pricedCounts as $month => $count) {
            if (! isset($values[$month])) {
                $medians[$month] = null;

                continue;
            }

            sort($values[$month]);
            $medians[$month] = $this->middleOf($values[$month]);
        }

        return $medians;
    }

    /**
     * @param array<string, int> $pricedCounts
     * @return array<string, int|null>
     */
    private function mediansByLookup(Builder $base, array $pricedCounts): array
    {
        $medians = [];

        foreach ($pricedCounts as $month => $count) {
            $middle = (clone $base)
                ->where('transaction_month', $month)
                ->whereNotNull('unit_price_ping')
                ->orderBy('unit_price_ping')
                // The middle value, or both middle values when the count is even.
                ->skip(intdiv($count - 1, 2))
                ->take($count % 2 === 0 ? 2 : 1)
                ->pluck('unit_price_ping');

            $medians[$month] = $middle->isEmpty() ? null : (int) round($middle->avg());
        }

        return $medians;
    }

    /**
     * @param array<int, int> $sorted ascending
     */
    private function middleOf(array $sorted): int
    {
        $count = count($sorted);
        $at = intdiv($count - 1, 2);

        return $count % 2 === 0
            ? (int) round(($sorted[$at] + $sorted[$at + 1]) / 2)
            : $sorted[$at];
    }

    private function trendMonths(): int
    {
        return max(1, (int) config('real_estate.trend_months', 60));
    }

    /**
     * Emit every month between the first and the last, so the x axis is a time
     * axis rather than a list of months that happen to have rows.
     *
     * @param Collection<string, object> $volume
     * @param array<string, int|null> $medians
     * @return Collection<int, object>
     */
    private function fillMonths(Collection $volume, array $medians): Collection
    {
        $cursor = Carbon::createFromFormat('Y-m', $volume->keys()->first())->startOfMonth();
        $end = Carbon::createFromFormat('Y-m', $volume->keys()->last())->startOfMonth();

        // Anchor the window to the newest month, not the oldest: one row with a
        // mistyped year would otherwise consume the whole cap and push every
        // real month out of the series.
        $earliest = (clone $end)->subMonths($this->trendMonths() - 1);

        if ($cursor < $earliest) {
            $cursor = $earliest;
        }

        $months = collect();

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m');
            $row = $volume->get($key);

            $months->push((object) [
                'month' => $key,
                'total_records' => (int) ($row->total_records ?? 0),
                'priced_records' => (int) ($row->priced_records ?? 0),
                'median_unit_price_ping' => $medians[$key] ?? null,
                'avg_unit_price_ping' => $this->roundNullable($row->avg_unit_price_ping ?? null),
                'avg_total_price' => $this->roundNullable($row->avg_total_price ?? null),
            ]);

            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * Ordered north to south, following the county list in config, rather than
     * by the byte order of the Chinese names.
     *
     * @return Collection<int, string>
     */
    private function cities(): Collection
    {
        $order = array_values(config('real_estate.county_codes'));

        return RealEstateTransaction::query()
            ->whereNotNull('city')
            ->distinct()
            ->pluck('city')
            ->sortBy(function (string $city) use ($order) {
                $position = array_search($city, $order, true);

                return $position === false ? PHP_INT_MAX : $position;
            })
            ->values();
    }

    /**
     * District names repeat across counties (中正區 is both 臺北市 and 基隆市),
     * so narrow the list once a city is chosen.
     *
     * @return Collection<int, string>
     */
    private function districts(?string $city): Collection
    {
        return RealEstateTransaction::query()
            ->when($city, fn ($query, string $city) => $query->where('city', $city))
            ->select('district')
            ->distinct()
            ->orderBy('district')
            ->pluck('district');
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'city' => $request->string('city')->trim()->toString() ?: null,
            'district' => $request->string('district')->trim()->toString() ?: null,
            'keyword' => $request->string('keyword')->trim()->toString() ?: null,
            'building_type' => $request->string('building_type')->trim()->toString() ?: null,
            'min_total_price' => $request->integer('min_total_price') ?: null,
            'max_total_price' => $request->integer('max_total_price') ?: null,
            'date_from' => $this->dateOrNull($request, 'date_from'),
            'date_to' => $this->dateOrNull($request, 'date_to'),
        ];
    }

    /**
     * A malformed ?date_from= is a bad filter, not a server error, so ignore it
     * instead of letting the parser throw a 500.
     */
    private function dateOrNull(Request $request, string $key): ?string
    {
        $value = $request->string($key)->trim()->toString();

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function summaryQuery(array $filters): array
    {
        $query = RealEstateTransaction::query()->filtered($filters);

        $aggregate = (clone $query)
            ->selectRaw('COUNT(*) as total_records')
            ->selectRaw('AVG(total_price) as avg_total_price')
            ->selectRaw('AVG(unit_price_ping) as avg_unit_price_ping')
            ->selectRaw('MIN(unit_price_ping) as min_unit_price_ping')
            ->selectRaw('MAX(unit_price_ping) as max_unit_price_ping')
            ->first();

        return [
            'total_records' => (int) ($aggregate->total_records ?? 0),
            'avg_total_price' => $this->roundNullable($aggregate->avg_total_price ?? null),
            'avg_unit_price_ping' => $this->roundNullable($aggregate->avg_unit_price_ping ?? null),
            'min_unit_price_ping' => $this->roundNullable($aggregate->min_unit_price_ping ?? null),
            'max_unit_price_ping' => $this->roundNullable($aggregate->max_unit_price_ping ?? null),
            'latest_transaction_date' => (clone $query)->max('transaction_date'),
            'oldest_transaction_date' => (clone $query)->min('transaction_date'),
        ];
    }

    /**
     * Only the JSON summary shows this. The index page never rendered it, so
     * computing it there spent a full grouped scan on a discarded result.
     *
     * @param array<string, mixed> $filters
     * @return Collection<int, object>
     */
    private function districtBreakdown(array $filters): Collection
    {
        return RealEstateTransaction::query()
            ->filtered($filters)
            ->select('city', 'district')
            ->selectRaw('COUNT(*) as total_records')
            ->selectRaw('ROUND(AVG(unit_price_ping)) as avg_unit_price_ping')
            ->whereNotNull('unit_price_ping')
            ->groupBy('city', 'district')
            ->orderByDesc('total_records')
            ->limit(12)
            ->get();
    }

    private function roundNullable(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
