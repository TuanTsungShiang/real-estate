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

    private const MAX_TREND_MONTHS = 600;

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
        return response()->json($this->summaryQuery($this->filters($request)));
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
        $month = $this->monthExpression();

        $base = RealEstateTransaction::query()
            ->filtered($filters)
            ->whereNotNull('transaction_date');

        $volume = (clone $base)
            ->selectRaw("{$month} as month")
            ->selectRaw('COUNT(*) as total_records')
            // COUNT of a column skips nulls, so land rows with no unit price
            // still count toward volume but not toward the price series.
            ->selectRaw('COUNT(unit_price_ping) as priced_records')
            ->selectRaw('ROUND(AVG(unit_price_ping)) as avg_unit_price_ping')
            ->selectRaw('ROUND(AVG(total_price)) as avg_total_price')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        if ($volume->isEmpty()) {
            return collect();
        }

        return $this->fillMonths($volume, $this->medianByMonth($base, $month));
    }

    /**
     * Median rather than mean: a single 4 億 luxury sale drags a month's average
     * far enough to flatten the whole line.
     *
     * @return Collection<string, object>
     */
    private function medianByMonth(Builder $base, string $month): Collection
    {
        $ranked = (clone $base)
            ->whereNotNull('unit_price_ping')
            ->selectRaw("{$month} as month")
            ->selectRaw('unit_price_ping')
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$month} ORDER BY unit_price_ping) as rn")
            ->selectRaw("COUNT(*) OVER (PARTITION BY {$month}) as cnt");

        return DB::query()
            ->fromSub($ranked, 'ranked')
            ->select('month')
            ->selectRaw('ROUND(AVG(unit_price_ping)) as median_unit_price_ping')
            // The middle row, or the mean of the two middle rows when even.
            ->whereRaw(sprintf('rn in (%s, %s)', $this->halveRounded('cnt + 1'), $this->halveRounded('cnt + 2')))
            ->groupBy('month')
            ->get()
            ->keyBy('month');
    }

    /**
     * Integer halving. SQLite and Postgres already truncate integer division;
     * MySQL returns a decimal, and PHP's bundled SQLite has no FLOOR() to lean
     * on, so each driver gets the form that works there.
     */
    private function halveRounded(string $expression): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite', 'pgsql' => "({$expression}) / 2",
            default => "FLOOR(({$expression}) / 2)",
        };
    }

    /**
     * Emit every month between the first and the last, so the x axis is a time
     * axis rather than a list of months that happen to have rows.
     *
     * @param Collection<string, object> $volume
     * @param Collection<string, object> $medians
     * @return Collection<int, object>
     */
    private function fillMonths(Collection $volume, Collection $medians): Collection
    {
        $cursor = Carbon::createFromFormat('Y-m', $volume->keys()->first())->startOfMonth();
        $end = Carbon::createFromFormat('Y-m', $volume->keys()->last())->startOfMonth();

        // Anchor the window to the newest month, not the oldest: one row with a
        // mistyped year would otherwise consume the whole cap and push every
        // real month out of the series.
        $earliest = (clone $end)->subMonths(self::MAX_TREND_MONTHS - 1);

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
                'median_unit_price_ping' => $this->roundNullable($medians->get($key)?->median_unit_price_ping),
                'avg_unit_price_ping' => $this->roundNullable($row->avg_unit_price_ping ?? null),
                'avg_total_price' => $this->roundNullable($row->avg_total_price ?? null),
            ]);

            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * No portable SQL for "year and month", so pick per driver.
     */
    private function monthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', transaction_date)",
            'pgsql' => "to_char(transaction_date, 'YYYY-MM')",
            'sqlsrv' => "FORMAT(transaction_date, 'yyyy-MM')",
            default => "DATE_FORMAT(transaction_date, '%Y-%m')",
        };
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

        $districts = (clone $query)
            ->select('city', 'district')
            ->selectRaw('COUNT(*) as total_records')
            ->selectRaw('ROUND(AVG(unit_price_ping)) as avg_unit_price_ping')
            ->whereNotNull('unit_price_ping')
            ->groupBy('city', 'district')
            ->orderByDesc('total_records')
            ->limit(12)
            ->get();

        return [
            'total_records' => (int) ($aggregate->total_records ?? 0),
            'avg_total_price' => $this->roundNullable($aggregate->avg_total_price ?? null),
            'avg_unit_price_ping' => $this->roundNullable($aggregate->avg_unit_price_ping ?? null),
            'min_unit_price_ping' => $this->roundNullable($aggregate->min_unit_price_ping ?? null),
            'max_unit_price_ping' => $this->roundNullable($aggregate->max_unit_price_ping ?? null),
            'districts' => $districts,
            'latest_transaction_date' => (clone $query)->max('transaction_date'),
            'oldest_transaction_date' => (clone $query)->min('transaction_date'),
        ];
    }

    private function roundNullable(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
