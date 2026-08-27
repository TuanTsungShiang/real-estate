<?php

namespace App\Console\Commands;

use App\Services\RealEstateOpenDataImporter;
use Illuminate\Console\Command;

class ImportRealEstateOpenData extends Command
{
    protected $signature = 'real-estate:import
        {--url= : ZIP URL to download}
        {--path= : Local ZIP path to import}
        {--season=* : Quarterly export to download, e.g. 114S1, a list 114S1,114S2 or a range 113S1-115S2}
        {--limit= : Stop after importing this many rows (per season)}
        {--fresh : Truncate existing records before import}';

    protected $description = 'Download and import Taiwan real-estate open data CSV ZIP.';

    public function handle(RealEstateOpenDataImporter $importer): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $fresh = (bool) $this->option('fresh');
        $seasons = RealEstateOpenDataImporter::expandSeasons((array) $this->option('season'));

        $rows = [];
        $totals = ['files' => 0, 'imported' => 0, 'skipped' => 0, 'fallback' => 0];

        if ($seasons === []) {
            $rows[] = $this->importOne($importer, null, $limit, $fresh, $totals);
        } else {
            $this->info(sprintf('%d season(s) to import: %s', count($seasons), implode(', ', $seasons)));

            foreach ($seasons as $index => $season) {
                // Only the first season may clear the table, or each one would
                // wipe what the previous just imported.
                $rows[] = $this->importOne($importer, $season, $limit, $fresh && $index === 0, $totals);
            }
        }

        if (count($rows) > 1) {
            $rows[] = ['TOTAL', $totals['files'], $totals['imported'], $totals['skipped'], $totals['fallback']];
        }

        $this->newLine();
        $this->table(['Season', 'CSV files', 'Imported', 'Skipped', 'Fallback keys'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param array<string, int> $totals
     * @return array<int, string|int>
     */
    private function importOne(
        RealEstateOpenDataImporter $importer,
        ?string $season,
        ?int $limit,
        bool $fresh,
        array &$totals,
    ): array {
        $zipPath = $this->option('path');

        if ($season !== null) {
            $this->line("  [{$season}] downloading...");
            $zipPath = $importer->downloadSeason($season);
        } elseif (! $zipPath) {
            $url = $this->option('url') ?: config('real_estate.open_data_url');

            $this->line("  downloading {$url}");
            $zipPath = $importer->download($url);
        }

        $this->line('  '.($season === null ? '' : "[{$season}] ").'importing...');

        $result = $importer->importZip($zipPath, $limit, $fresh, $season);

        foreach ($totals as $key => $value) {
            $totals[$key] = $value + $result[$key];
        }

        return [
            $season ?? 'current',
            $result['files'],
            $result['imported'],
            $result['skipped'],
            $result['fallback'],
        ];
    }
}
