<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SQLite does not enforce declared column types, so these were never a
     * problem there - it stored 4444444 in a TINYINT quite happily. MySQL runs
     * with STRICT_TRANS_TABLES and rejects the row, which is what surfaced two
     * real limits across the 20-season dataset:
     *
     * - A whole building sold as one record legitimately reports a few hundred
     *   rooms (269, 322, 323 all appear), past TINYINT's 255. Values above
     *   SMALLINT are the junk ones - 4444444 bathrooms, 2123122122 rooms - and
     *   the importer drops those.
     * - Five addresses run to 446 characters because they list every parcel in
     *   the sale. Truncating them would lose data, so address becomes TEXT.
     */
    public function up(): void
    {
        Schema::table('real_estate_transactions', function (Blueprint $table): void {
            $table->text('address')->nullable()->change();
            $table->unsignedSmallInteger('room_count')->nullable()->change();
            $table->unsignedSmallInteger('hall_count')->nullable()->change();
            $table->unsignedSmallInteger('bathroom_count')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('real_estate_transactions', function (Blueprint $table): void {
            $table->string('address')->nullable()->change();
            $table->unsignedTinyInteger('room_count')->nullable()->change();
            $table->unsignedTinyInteger('hall_count')->nullable()->change();
            $table->unsignedTinyInteger('bathroom_count')->nullable()->change();
        });
    }
};
