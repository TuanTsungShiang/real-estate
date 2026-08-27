<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('real_estate_transactions', function (Blueprint $table): void {
            $table->id();
            // Identity digest of the transaction, so re-running an import
            // updates rows in place instead of duplicating the whole dataset.
            $table->char('row_hash', 40)->unique();
            $table->string('source_file')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('district')->index();
            $table->string('address')->nullable()->index();
            $table->date('transaction_date')->nullable()->index();
            $table->string('transaction_date_raw')->nullable();
            $table->string('building_type')->nullable()->index();
            $table->string('main_use')->nullable();
            $table->decimal('land_area_sqm', 12, 2)->nullable();
            $table->decimal('building_area_sqm', 12, 2)->nullable();
            $table->unsignedBigInteger('total_price')->nullable()->index();
            $table->unsignedInteger('unit_price_sqm')->nullable();
            $table->unsignedInteger('unit_price_ping')->nullable()->index();
            $table->unsignedBigInteger('parking_price')->nullable();
            $table->unsignedTinyInteger('room_count')->nullable();
            $table->unsignedTinyInteger('hall_count')->nullable();
            $table->unsignedTinyInteger('bathroom_count')->nullable();
            $table->boolean('has_elevator')->nullable();
            $table->json('raw_payload');
            $table->timestamps();

            $table->index(['district', 'transaction_date']);
            $table->index(['district', 'unit_price_ping']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('real_estate_transactions');
    }
};
