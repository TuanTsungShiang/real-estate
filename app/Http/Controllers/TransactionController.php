<?php

namespace App\Http\Controllers;

use App\Models\RealEstateTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $districts = RealEstateTransaction::query()
            ->select('district')
            ->distinct()
            ->orderBy('district')
            ->pluck('district');

        return view('transactions.index', [
            'transactions' => $transactions,
            'districts' => $districts,
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
            ->select('district')
            ->selectRaw('COUNT(*) as total_records')
            ->selectRaw('ROUND(AVG(unit_price_ping)) as avg_unit_price_ping')
            ->whereNotNull('unit_price_ping')
            ->groupBy('district')
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
