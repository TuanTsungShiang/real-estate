<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A date-range filter reads transaction_date, which the month indexes from
     * 000003 do not carry, so grouping the trend by month meant following the
     * index back to two million rows: 46s for the trend and 13s for the summary
     * on the 20-season dataset, both past the request timeout.
     *
     * With the date inside the index the same queries are index-only - EXPLAIN
     * goes from `Using where` to `Using where; Using index` - and run in 1.1s
     * and 0.8s.
     *
     * The narrower 000003 indexes stay: they are cheaper to scan when no date
     * filter is present, and the planner picks between them.
     */
    public function up(): void
    {
        Schema::table('real_estate_transactions', function (Blueprint $table): void {
            $table->index(
                ['transaction_month', 'transaction_date', 'unit_price_ping', 'total_price'],
                'ret_month_date_unit_total_index',
            );
            $table->index(
                ['city', 'transaction_month', 'transaction_date', 'unit_price_ping', 'total_price'],
                'ret_city_month_date_unit_total_index',
            );
        });

        match (DB::connection()->getDriverName()) {
            'sqlite' => DB::statement('ANALYZE'),
            'mysql' => DB::statement('ANALYZE TABLE real_estate_transactions'),
            default => null,
        };
    }

    public function down(): void
    {
        Schema::table('real_estate_transactions', function (Blueprint $table): void {
            $table->dropIndex('ret_month_date_unit_total_index');
            $table->dropIndex('ret_city_month_date_unit_total_index');
        });
    }
};
