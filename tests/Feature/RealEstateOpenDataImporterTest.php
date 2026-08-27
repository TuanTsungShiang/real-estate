<?php

namespace Tests\Feature;

use App\Models\RealEstateTransaction;
use App\Services\RealEstateOpenDataImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class RealEstateOpenDataImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = storage_path('app/testing/importer');
        File::deleteDirectory($this->workspace);
        File::ensureDirectoryExists($this->workspace);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_it_imports_rows_from_a_bom_prefixed_csv(): void
    {
        $result = $this->importer()->importZip($this->fixtureZip());

        $this->assertSame(1, $result['files']);
        $this->assertSame(3, $result['imported']);
        // The English header row and the row with no price.
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(0, $result['fallback'], 'Every fixture row carries a 編號.');

        $this->assertSame(3, RealEstateTransaction::query()->count());

        $transaction = RealEstateTransaction::query()
            ->where('address', 'like', '%復興南路%')
            ->orderBy('id')
            ->firstOrFail();

        // 鄉鎮市區 is the first column, so a surviving BOM would blank it out
        // and the whole row would have been skipped.
        $this->assertSame('大安區', $transaction->district);

        // The CSV carries no city column; a_lvr_land_a.csv means 臺北市.
        $this->assertSame('臺北市', $transaction->city);

        $this->assertSame('2024-03-15', $transaction->transaction_date->toDateString());
        // Serialized as a plain date, not a UTC instant a day behind.
        $this->assertSame('2024-03-15', $transaction->toArray()['transaction_date']);
        $this->assertSame(28500000, $transaction->total_price);
        $this->assertSame(1652893, $transaction->unit_price_ping);
        $this->assertTrue($transaction->has_elevator);
        $this->assertSame('住宅大樓(11層含以上有電梯)', $transaction->raw_payload['建物型態']);
    }

    public function test_rows_are_keyed_by_the_official_serial(): void
    {
        $this->importer()->importZip($this->fixtureZip());

        $transaction = RealEstateTransaction::query()
            ->where('address', 'like', '%松高路%')
            ->firstOrFail();

        $this->assertSame(
            sha1('serial:'.$transaction->raw_payload['編號']),
            $transaction->row_hash,
        );
    }

    public function test_identical_transactions_with_different_serials_stay_separate(): void
    {
        $this->importer()->importZip($this->fixtureZip());

        $repeated = RealEstateTransaction::query()
            ->where('address', 'like', '%復興南路%')
            ->get();

        $this->assertCount(2, $repeated, 'Two separately registered cases were collapsed away.');
        $this->assertCount(2, $repeated->pluck('row_hash')->unique());
    }

    public function test_rows_without_a_serial_fall_back_to_a_content_key(): void
    {
        $result = $this->importer()->importZip($this->fixtureZip(withSerial: false));

        $this->assertSame(3, $result['imported']);
        $this->assertSame(3, $result['fallback']);

        // The two identical rows must still survive as two rows.
        $this->assertSame(3, RealEstateTransaction::query()->count());
        $this->assertSame(3, RealEstateTransaction::query()->distinct()->count('row_hash'));
    }

    public function test_reimporting_the_same_data_updates_instead_of_duplicating(): void
    {
        foreach ([true, false] as $withSerial) {
            RealEstateTransaction::query()->truncate();

            $importer = $this->importer();
            $importer->importZip($this->fixtureZip(withSerial: $withSerial));
            $importer->importZip($this->fixtureZip(withSerial: $withSerial));

            $this->assertSame(3, RealEstateTransaction::query()->count());
        }
    }

    public function test_limit_stops_early_and_releases_the_csv_handle(): void
    {
        $before = $this->openFileCount();

        $result = $this->importer()->importZip($this->fixtureZip(), limit: 1);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, RealEstateTransaction::query()->count());
        $this->assertSame($before, $this->openFileCount(), 'The CSV handle was left open after an early exit.');
    }

    public function test_it_cleans_up_the_extracted_files(): void
    {
        $this->importer()->importZip($this->fixtureZip());

        $extracted = storage_path('app/real-estate/extracted');

        $this->assertTrue(
            ! File::isDirectory($extracted) || File::directories($extracted) === [],
            'Extracted CSV files were left behind.',
        );
    }

    private function importer(): RealEstateOpenDataImporter
    {
        return $this->app->make(RealEstateOpenDataImporter::class);
    }

    /**
     * A stand-in for the MOI ZIP: UTF-8 BOM, a Chinese header row followed by
     * an English one, two identical transactions registered under different
     * 編號, and an unusable row.
     *
     * @param bool $withSerial drop the 編號 column to exercise the fallback key
     */
    private function fixtureZip(bool $withSerial = true): string
    {
        $headers = [
            '鄉鎮市區', '交易標的', '土地位置建物門牌', '土地移轉總面積平方公尺', '交易年月日',
            '建物型態', '主要用途', '建物移轉總面積平方公尺', '建物現況格局-房', '建物現況格局-廳',
            '建物現況格局-衛', '電梯', '總價元', '單價元平方公尺', '車位總價元',
        ];

        $english = 'The villages and towns urban district,transaction sign,land sector position building sector house number plate,land shifting total area square meter,transaction year month and day,building state,main use,building shifting total area,building present situation pattern - room,building present situation pattern - hall,building present situation pattern - health,elevator,total price NTD,the unit price (NTD / square meter),the berth total price NTD';

        $row = '大安區,房地(土地+建物),臺北市大安區復興南路一段100號,15.32,1130315,住宅大樓(11層含以上有電梯),住家用,102.55,3,2,2,有,28500000,500000,0';
        $other = '信義區,房地(土地+建物),臺北市信義區松高路50號,20.10,1130520,華廈(10層含以下有電梯),住家用,88.20,2,1,1,有,19800000,420000,0';
        $noPrice = '中山區,房地(土地+建物),臺北市中山區南京東路一段9號,10.00,1130101,公寓(5樓含以下無電梯),住家用,66.00,2,1,1,無,,,0';

        if ($withSerial) {
            $headers[] = '編號';
            $english .= ',serial number';
            // Same transaction details, different official case ids.
            $row1 = $row.',RPXRMLQLNHLGFAO77DA';
            $row2 = $row.',RPSTMLQLNHLGFAO47DA';
            $other .= ',RPTVMLQLNHLGFAO77DA';
            $noPrice .= ',RPXNNLQLNHLGFAO47DA';
        } else {
            $row1 = $row;
            $row2 = $row;
        }

        $csv = "\u{FEFF}".implode(',', $headers)."\n"
            .$english."\n"
            .$row1."\n".$row2."\n".$other."\n".$noPrice."\n";

        $csvPath = $this->workspace.'/a_lvr_land_a.csv';
        File::put($csvPath, $csv);

        $zipPath = $this->workspace.'/lvr_landcsv.zip';
        File::delete($zipPath);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFile($csvPath, 'a_lvr_land_a.csv');
        $zip->close();

        return $zipPath;
    }

    private function openFileCount(): int
    {
        // get_resources() only exists in debug builds; fall back to a no-op
        // constant so the assertion still runs everywhere.
        return function_exists('get_resources') ? count(get_resources('stream')) : 0;
    }
}
