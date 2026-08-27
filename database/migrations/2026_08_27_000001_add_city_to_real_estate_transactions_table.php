<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('real_estate_transactions', function (Blueprint $table): void {
            // Derived from the source file's county code, because the CSV has
            // no city column and district names repeat across counties.
            $table->string('city')->nullable()->after('source_file')->index();
            $table->index(['city', 'district']);
        });
    }

    public function down(): void
    {
        Schema::table('real_estate_transactions', function (Blueprint $table): void {
            $table->dropIndex(['city', 'district']);
            $table->dropIndex(['city']);
            $table->dropColumn('city');
        });
    }
};
