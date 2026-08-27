<?php

namespace App\Console\Commands;

use App\Services\RealEstateOpenDataImporter;
use Illuminate\Console\Command;

class ImportRealEstateOpenData extends Command
{
    protected $signature = 'real-estate:import
        {--url= : ZIP URL to download}
        {--path= : Local ZIP path to import}
        {--limit= : Stop after importing this many rows}
        {--fresh : Truncate existing records before import}';

    protected $description = 'Download and import Taiwan real-estate open data CSV ZIP.';

    public function handle(RealEstateOpenDataImporter $importer): int
    {
        $zipPath = $this->option('path');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if (! $zipPath) {
            $url = $this->option('url') ?: config('real_estate.open_data_url');

            $this->info("Downloading ZIP from: {$url}");
            $zipPath = $importer->download($url);
            $this->info("Saved ZIP to: {$zipPath}");
        }

        $this->info('Importing CSV rows...');

        $result = $importer->importZip(
            zipPath: $zipPath,
            limit: $limit,
            fresh: (bool) $this->option('fresh'),
        );

        $this->table(
            ['CSV files', 'Imported', 'Skipped', 'Repeats'],
            [[$result['files'], $result['imported'], $result['skipped'], $result['repeated']]],
        );

        return self::SUCCESS;
    }
}
