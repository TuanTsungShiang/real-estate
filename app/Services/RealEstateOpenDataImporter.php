<?php

namespace App\Services;

use App\Models\RealEstateTransaction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class RealEstateOpenDataImporter
{
    private const PING_PER_SQM = 0.3025;

    /**
     * Rows buffered before each upsert. Official ZIPs hold hundreds of
     * thousands of rows, so one INSERT per row is far too slow.
     */
    private const BATCH_SIZE = 500;

    /**
     * Fallback identity for rows with no 編號, hashed together with an
     * occurrence counter so two genuinely identical transactions still stay
     * two rows. Deliberately excludes source_file so re-downloads update in
     * place.
     */
    private const IDENTITY_FIELDS = [
        'transaction_type',
        'city',
        'district',
        'address',
        'transaction_date_raw',
        'building_type',
        'land_area_sqm',
        'building_area_sqm',
        'total_price',
        'unit_price_sqm',
    ];

    /**
     * Chinese CSV headers used by MOI real-price registration open data.
     * Keep aliases here so importer changes are isolated when the schema moves.
     */
    private const FIELD_ALIASES = [
        // MOI's own case identifier, unique per registered transaction.
        'serial' => ['編號'],
        'transaction_type' => ['交易標的'],
        'district' => ['鄉鎮市區'],
        'address' => ['土地位置建物門牌'],
        'transaction_date_raw' => ['交易年月日'],
        'building_type' => ['建物型態'],
        'main_use' => ['主要用途'],
        'land_area_sqm' => ['土地移轉總面積平方公尺'],
        'building_area_sqm' => ['建物移轉總面積平方公尺'],
        'total_price' => ['總價元'],
        'unit_price_sqm' => ['單價元平方公尺'],
        'parking_price' => ['車位總價元'],
        'room_count' => ['建物現況格局-房'],
        'hall_count' => ['建物現況格局-廳'],
        'bathroom_count' => ['建物現況格局-衛'],
        'has_elevator' => ['電梯'],
    ];

    public function download(string $url): string
    {
        $target = storage_path('app/real-estate/source/lvr_landcsv.zip');
        File::ensureDirectoryExists(dirname($target));

        $response = Http::timeout(120)->retry(3, 1000)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Download failed with HTTP {$response->status()}.");
        }

        file_put_contents($target, $response->body());

        return $target;
    }

    /**
     * @return array{imported:int, skipped:int, fallback:int, files:int}
     */
    public function importZip(string $zipPath, ?int $limit = null, bool $fresh = false): array
    {
        if (! is_file($zipPath)) {
            throw new RuntimeException("ZIP file not found: {$zipPath}");
        }

        if ($fresh) {
            RealEstateTransaction::query()->truncate();
        }

        $extractPath = storage_path('app/real-estate/extracted/'.now()->format('Ymd_His'));
        File::ensureDirectoryExists($extractPath);

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            File::deleteDirectory($extractPath);

            throw new RuntimeException("Unable to open ZIP file: {$zipPath}");
        }

        $zip->extractTo($extractPath);
        $zip->close();

        $imported = 0;
        $skipped = 0;
        $fallback = 0;
        $files = 0;

        /** @var array<int, array<string, mixed>> $batch */
        $batch = [];

        /**
         * Fallback identity hash => times seen in this run, used only for rows
         * with no 編號. Numbering repeats rather than dropping them keeps
         * identical-but-real transactions apart while still producing the same
         * row_hash on a re-import.
         *
         * @var array<string, int> $occurrences
         */
        $occurrences = [];

        try {
            foreach ($this->candidateCsvFiles($extractPath) as $csvFile) {
                $files++;

                foreach ($this->readCsvAssoc($csvFile) as $row) {
                    if ($limit !== null && $imported >= $limit) {
                        break 2;
                    }

                    $payload = $this->normalizeRow($row, basename($csvFile));

                    if ($payload === null) {
                        $skipped++;

                        continue;
                    }

                    // Rows normally key off 編號. Only pre-編號 or reshaped
                    // exports fall through to the content-based identity, so
                    // the occurrence map usually stays empty.
                    if ($payload['row_hash'] === null) {
                        $identity = $this->identityHash($payload);
                        $occurrence = $occurrences[$identity] = ($occurrences[$identity] ?? 0) + 1;

                        $payload['row_hash'] = sha1($identity.'#'.$occurrence);
                        $fallback++;
                    }

                    $batch[] = $payload;
                    $imported++;

                    if (count($batch) >= self::BATCH_SIZE) {
                        $this->flush($batch);
                        $batch = [];
                    }
                }
            }

            $this->flush($batch);
        } finally {
            File::deleteDirectory($extractPath);
        }

        return compact('imported', 'skipped', 'fallback', 'files');
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function flush(array $batch): void
    {
        if ($batch === []) {
            return;
        }

        RealEstateTransaction::query()->upsert(
            $batch,
            ['row_hash'],
            RealEstateTransaction::upsertColumns(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function candidateCsvFiles(string $extractPath): array
    {
        return collect(File::allFiles($extractPath))
            ->map(fn ($file) => $file->getPathname())
            ->filter(fn (string $path) => str_ends_with(strtolower($path), '.csv'))
            ->reject(fn (string $path) => str_contains(strtolower(basename($path)), 'schema'))
            ->reject(fn (string $path) => str_contains(strtolower(basename($path)), 'manifest'))
            ->values()
            ->all();
    }

    /**
     * @return iterable<array<string, string|null>>
     */
    private function readCsvAssoc(string $path): iterable
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        $headers = null;

        try {
            while (($columns = fgetcsv($handle)) !== false) {
                $columns = array_map(fn ($value) => $this->toUtf8((string) $value), $columns);

                if ($headers === null) {
                    $headers = array_map(fn (string $value) => trim($value), $columns);

                    // MOI files ship with a UTF-8 BOM; left in place it corrupts
                    // the first header and every row loses its 鄉鎮市區 value.
                    if (isset($headers[0])) {
                        $headers[0] = ltrim($headers[0], "\u{FEFF}");
                    }

                    continue;
                }

                if ($this->isNoiseRow($columns)) {
                    continue;
                }

                $row = [];
                foreach ($headers as $index => $header) {
                    $row[$header] = isset($columns[$index]) ? trim((string) $columns[$index]) : null;
                }

                yield $row;
            }
        } finally {
            // Also runs when the consumer breaks out early, which is how
            // --limit stops the import.
            fclose($handle);
        }
    }

    /**
     * @param array<string, string|null> $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row, string $sourceFile): ?array
    {
        $district = $this->pick($row, 'district');
        $address = $this->pick($row, 'address');
        $totalPrice = $this->unsignedInteger($this->pick($row, 'total_price'));

        if ($district === null || $address === null || $totalPrice === null) {
            return null;
        }

        $unitPriceSqm = $this->unsignedInteger($this->pick($row, 'unit_price_sqm'));
        $transactionDateRaw = $this->pick($row, 'transaction_date_raw');

        $payload = [
            // Null when the export predates 編號; importZip then derives a
            // content-based key instead.
            'row_hash' => $this->officialHash($row),
            'source_file' => $sourceFile,
            'city' => $this->city($sourceFile),
            'transaction_type' => $this->pick($row, 'transaction_type'),
            'district' => $district,
            'address' => $address,
            'transaction_date' => $this->rocDate($transactionDateRaw),
            'transaction_date_raw' => $transactionDateRaw,
            'building_type' => $this->pick($row, 'building_type'),
            'main_use' => $this->pick($row, 'main_use'),
            'land_area_sqm' => $this->decimal($this->pick($row, 'land_area_sqm')),
            'building_area_sqm' => $this->decimal($this->pick($row, 'building_area_sqm')),
            'total_price' => $totalPrice,
            'unit_price_sqm' => $unitPriceSqm,
            'unit_price_ping' => $unitPriceSqm ? (int) round($unitPriceSqm / self::PING_PER_SQM) : null,
            'parking_price' => $this->unsignedInteger($this->pick($row, 'parking_price')),
            'room_count' => $this->unsignedInteger($this->pick($row, 'room_count')),
            'hall_count' => $this->unsignedInteger($this->pick($row, 'hall_count')),
            'bathroom_count' => $this->unsignedInteger($this->pick($row, 'bathroom_count')),
            'has_elevator' => $this->boolean($this->pick($row, 'has_elevator')),
        ];

        // Upserts bypass Eloquent casts, so encode the raw row by hand.
        $payload['raw_payload'] = json_encode($row, JSON_UNESCAPED_UNICODE);

        return $payload;
    }

    /**
     * The CSV carries no city column, so it comes from the county letter that
     * prefixes the file name: a_lvr_land_a.csv -> 臺北市.
     */
    private function city(string $sourceFile): ?string
    {
        $code = strtolower(explode('_', $sourceFile, 2)[0]);

        return config('real_estate.county_codes')[$code] ?? null;
    }

    /**
     * MOI stamps every registered case with a unique 編號, so prefer it over
     * anything we could derive from the row's contents.
     *
     * @param array<string, string|null> $row
     */
    private function officialHash(array $row): ?string
    {
        $serial = $this->pick($row, 'serial');

        return $serial === null ? null : sha1('serial:'.$serial);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function identityHash(array $payload): string
    {
        $parts = array_map(
            fn (string $field) => (string) ($payload[$field] ?? ''),
            self::IDENTITY_FIELDS,
        );

        return sha1(implode('|', $parts));
    }

    /**
     * @param array<string, string|null> $row
     */
    private function pick(array $row, string $key): ?string
    {
        foreach (self::FIELD_ALIASES[$key] ?? [] as $header) {
            $value = $row[$header] ?? null;

            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function rocDate(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === '' || strlen($digits) < 6) {
            return null;
        }

        $day = (int) substr($digits, -2);
        $month = (int) substr($digits, -4, 2);
        $rocYear = (int) substr($digits, 0, -4);
        $year = $rocYear + 1911;

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function decimal(?string $value): ?float
    {
        $number = $this->numberString($value);

        return $number === null ? null : (float) $number;
    }

    private function integer(?string $value): ?int
    {
        $number = $this->numberString($value);

        return $number === null ? null : (int) round((float) $number);
    }

    /**
     * Price and count columns are unsigned in the schema, so a negative value
     * is bad data rather than a small number.
     */
    private function unsignedInteger(?string $value): ?int
    {
        $number = $this->integer($value);

        return $number === null || $number < 0 ? null : $number;
    }

    private function numberString(?string $value): ?string
    {
        $number = preg_replace('/[^0-9.\-]+/', '', (string) $value);

        return is_numeric($number) ? $number : null;
    }

    private function boolean(?string $value): ?bool
    {
        return match (trim((string) $value)) {
            '有', '是', 'Y', 'y', '1' => true,
            '無', '否', 'N', 'n', '0' => false,
            default => null,
        };
    }

    private function toUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'CP950,BIG5,UTF-8');
    }

    /**
     * @param array<int, string> $columns
     */
    private function isNoiseRow(array $columns): bool
    {
        $joined = implode('', $columns);

        return trim($joined) === '' || str_contains($joined, 'The downloaded data');
    }
}
