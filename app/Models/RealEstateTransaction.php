<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RealEstateTransaction extends Model
{
    /** Memoised per process; the index either exists in this schema or it does not. */
    private static ?bool $addressFulltext = null;

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

    /**
     * `like '%...%'` cannot use a b-tree, so on the full dataset it scans every
     * row. Where MySQL's ngram FULLTEXT index exists it narrows the candidates
     * first; the LIKE stays as the filter so results are identical either way.
     */
    protected static function matchAddress(Builder $query, string $keyword): Builder
    {
        $like = $query->where('address', 'like', '%'.$keyword.'%');

        // ngram_token_size is 2, so a single character indexes nothing.
        if (mb_strlen($keyword) < 2 || ! static::usesAddressFulltext()) {
            return $like;
        }

        // Quoted for a phrase match, with quotes stripped from the term so a
        // stray one cannot break boolean-mode syntax.
        return $like->whereRaw(
            'match(address) against (? in boolean mode)',
            ['"'.str_replace('"', '', $keyword).'"'],
        );
    }

    public static function usesAddressFulltext(): bool
    {
        if (static::$addressFulltext !== null) {
            return static::$addressFulltext;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return static::$addressFulltext = false;
        }

        return static::$addressFulltext = DB::selectOne(
            "select count(*) as total from information_schema.statistics
             where table_schema = database()
               and table_name = 'real_estate_transactions'
               and index_name = 'ret_address_fulltext'"
        )->total > 0;
    }

    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['city'] ?? null, fn (Builder $query, string $city) => $query->where('city', $city))
            ->when($filters['district'] ?? null, fn (Builder $query, string $district) => $query->where('district', $district))
            ->when($filters['keyword'] ?? null, fn (Builder $query, string $keyword) => static::matchAddress($query, $keyword))
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
