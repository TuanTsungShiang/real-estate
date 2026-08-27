<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('real_estate_transactions', function (Blueprint $table): void {
            // Grouping by strftime() over the date forces a temp b-tree on every
            // trend query. Storing the month makes it an index scan.
            $table->char('transaction_month', 7)->nullable()->after('transaction_date');
        });

        DB::statement(
            'update real_estate_transactions set transaction_month = '.$this->monthExpression()
            .' where transaction_date is not null'
        );

        Schema::table('real_estate_transactions', function (Blueprint $table): void {
            // Covering indexes, and they only pay off while they stay covering:
            // leaving total_price out of the trend index sent every grouped
            // query back to 2M+ rows in a multi-gigabyte table, which cost 174s
            // rather than 0.2s.
            $table->index(['transaction_month', 'unit_price_ping', 'total_price']);
            $table->index(['city', 'transaction_month', 'unit_price_ping', 'total_price']);
            $table->index(['city', 'district', 'unit_price_ping']);
            $table->index(['unit_price_ping', 'total_price']);

            // Keyword search is `like '%...%'`, and a leading wildcard cannot
            // use a b-tree, so this index only ever cost space and write time.
            $table->dropIndex(['address']);

            // Left-hand prefixes of the covering indexes above.
            $table->dropIndex(['city']);
            $table->dropIndex(['city', 'district']);
            $table->dropIndex(['unit_price_ping']);
        });

        // Without stats the planner picks badly on a table this size.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('ANALYZE');
        }
    }

    public function down(): void
    {
        Schema::table('real_estate_transactions', function (Blueprint $table): void {
            $table->index(['address']);
            $table->index(['city']);
            $table->index(['city', 'district']);
            $table->index(['unit_price_ping']);

            $table->dropIndex(['transaction_month', 'unit_price_ping', 'total_price']);
            $table->dropIndex(['city', 'transaction_month', 'unit_price_ping', 'total_price']);
            $table->dropIndex(['city', 'district', 'unit_price_ping']);
            $table->dropIndex(['unit_price_ping', 'total_price']);

            $table->dropColumn('transaction_month');
        });
    }

    private function monthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', transaction_date)",
            'pgsql' => "to_char(transaction_date, 'YYYY-MM')",
            'sqlsrv' => "FORMAT(transaction_date, 'yyyy-MM')",
            default => "DATE_FORMAT(transaction_date, '%Y-%m')",
        };
    }
};
