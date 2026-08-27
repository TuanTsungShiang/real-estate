<?php

namespace App\Http\Controllers;

use App\Models\RealEstateTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

class TransactionController extends Controller
{
    private const DEFAULT_PER_PAGE = 30;

    private const MAX_PER_PAGE = 100;

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
