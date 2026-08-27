<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RealEstateTransaction extends Model
{
    protected $fillable = [
        'row_hash',
        'source_file',
        'season',
        'city',
        'transaction_type',
        'district',
        'address',
        'transaction_date',
        'transaction_month',
        'transaction_date_raw',
        'building_type',
        'main_use',
        'land_area_sqm',
        'building_area_sqm',
        'total_price',
        'unit_price_sqm',
        'unit_price_ping',
        'parking_price',
        'room_count',
        'hall_count',
        'bathroom_count',
        'has_elevator',
        'raw_payload',
    ];

    protected $casts = [
        // Y-m-d, not the default full ISO timestamp: the column holds a date,
        // and serializing it as a UTC instant shifted every Asia/Taipei date
        // back by one day in the JSON API.
        'transaction_date' => 'date:Y-m-d',
        'land_area_sqm' => 'decimal:2',
        'building_area_sqm' => 'decimal:2',
        'total_price' => 'integer',
        'unit_price_sqm' => 'integer',
        'unit_price_ping' => 'integer',
        'parking_price' => 'integer',
        'room_count' => 'integer',
        'hall_count' => 'integer',
        'bathroom_count' => 'integer',
        'has_elevator' => 'boolean',
        'raw_payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $transaction): void {
            // transaction_month is denormalised from transaction_date so the
            // aggregate indexes can cover their queries. Deriving it here means
            // no write path can leave a row invisible to the trend or to a date
            // filter by forgetting to set it. (The importer upserts through the
            // query builder, which skips this, so it sets the column itself.)
            $transaction->transaction_month = $transaction->transaction_date?->format('Y-m');
        });
    }

    /**
     * Columns refreshed when an import re-encounters a known row_hash.
     *
     * @return array<int, string>
     */
    public static function upsertColumns(): array
    {
        return array_values(array_diff((new static)->getFillable(), ['row_hash']));
    }

    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['city'] ?? null, fn (Builder $query, string $city) => $query->where('city', $city))
            ->when($filters['district'] ?? null, fn (Builder $query, string $district) => $query->where('district', $district))
            ->when($filters['keyword'] ?? null, fn (Builder $query, string $keyword) => $query->where('address', 'like', "%{$keyword}%"))
            ->when($filters['building_type'] ?? null, fn (Builder $query, string $type) => $query->where('building_type', 'like', "%{$type}%"))
            ->when($filters['min_total_price'] ?? null, fn (Builder $query, int $price) => $query->where('total_price', '>=', $price))
            ->when($filters['max_total_price'] ?? null, fn (Builder $query, int $price) => $query->where('total_price', '<=', $price))
            // Plain comparisons, not whereDate(): the column is a DATE, and
            // wrapping it in date() makes its index unusable, which turned a
            // date-range page into a full scan of every row.
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('transaction_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('transaction_date', '<=', $date));
    }
}
