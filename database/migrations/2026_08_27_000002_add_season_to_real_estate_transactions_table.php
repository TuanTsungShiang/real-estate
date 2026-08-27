<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('real_estate_transactions', function (Blueprint $table): void {
            // Which quarterly export a row came from, so an incremental
            // multi-season import can report what is already loaded.
            $table->string('season', 8)->nullable()->after('source_file')->index();
        });
    }

    public function down(): void
    {
        Schema::table('real_estate_transactions', function (Blueprint $table): void {
            $table->dropIndex(['season']);
            $table->dropColumn('season');
        });
    }
};
