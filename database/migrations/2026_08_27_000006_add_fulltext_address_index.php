<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Address search is `like '%大安路%'`, which no b-tree can serve, so it
     * scans every row. MySQL's ngram parser tokenises CJK text by character
     * bigrams, which makes a FULLTEXT index usable for substring search in
     * Chinese - the one thing this dataset most needs and SQLite cannot do.
     *
     * Anything else keeps the LIKE scan; the query layer picks per driver.
     */
    public function up(): void
    {
        if (! $this->supportsNgram()) {
            return;
        }

        DB::statement(
            'alter table real_estate_transactions '
            .'add fulltext index ret_address_fulltext (address) with parser ngram'
        );
    }

    public function down(): void
    {
        if (! $this->supportsNgram()) {
            return;
        }

        DB::statement('alter table real_estate_transactions drop index ret_address_fulltext');
    }

    private function supportsNgram(): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        // MariaDB reports a version string of its own and has no ngram parser.
        $version = DB::selectOne('select version() as version')->version;

        if (str_contains(strtolower($version), 'mariadb')) {
            return false;
        }

        return DB::selectOne(
            "select count(*) as total from information_schema.plugins
             where plugin_name = 'ngram' and plugin_status = 'ACTIVE'"
        )->total > 0;
    }
};
