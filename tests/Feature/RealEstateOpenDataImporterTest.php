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
        $this->assertSame(1, $result['repeated']);
        // The English header row and the row with no price.
        $this->assertSame(2, $result['skipped']);

        $this->assertSame(3, RealEstateTransaction::query()->count());

        $transaction = RealEstateTransaction::query()
            ->where('address', 'like', '%復興南路%')
            ->orderBy('id')
            ->firstOrFail();

        // 鄉鎮市區 is the first column, so a surviving BOM would blank it out
        // and the whole row would have been skipped.
        $this->assertSame('大安區', $transaction->district);
        $this->assertSame('2024-03-15', $transaction->transaction_date->toDateString());
        $this->assertSame(28500000, $transaction->total_price);
        $this->assertSame(1652893, $transaction->unit_price_ping);
        $this->assertTrue($transaction->has_elevator);
        $this->assertSame('住宅大樓(11層含以上有電梯)', $transaction->raw_payload['建物型態']);
    }

    public function test_identical_transactions_are_kept_as_separate_rows(): void
    {
        $this->importer()->importZip($this->fixtureZip());

        $repeated = RealEstateTransaction::query()
            ->where('address', 'like', '%復興南路%')
            ->get();

        $this->assertCount(2, $repeated, 'A genuine repeat transaction was collapsed away.');
        $this->assertCount(2, $repeated->pluck('row_hash')->unique());
    }

    public function test_reimporting_the_same_data_updates_instead_of_duplicating(): void
    {
        $importer = $this->importer();

        $importer->importZip($this->fixtureZip());
        $importer->importZip($this->fixtureZip());

        $this->assertSame(3, RealEstateTransaction::query()->count());
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
     * an English one, a repeated transaction and an unusable row.
     */
    private function fixtureZip(): string
    {
        $csv = "\u{FEFF}".implode(',', [
            '鄉鎮市區', '交易標的', '土地位置建物門牌', '土地移轉總面積平方公尺', '交易年月日',
            '建物型態', '主要用途', '建物移轉總面積平方公尺', '建物現況格局-房', '建物現況格局-廳',
            '建物現況格局-衛', '電梯', '總價元', '單價元平方公尺', '車位總價元',
        ])."\n";

        $csv .= "The villages and towns urban district,transaction sign,land sector position building sector house number plate,land shifting total area square meter,transaction year month and day,building state,main use,building shifting total area,building present situation pattern - room,building present situation pattern - hall,building present situation pattern - health,elevator,total price NTD,the unit price (NTD / square meter),the berth total price NTD\n";

        $row = '大安區,房地(土地+建物),臺北市大安區復興南路一段100號,15.32,1130315,住宅大樓(11層含以上有電梯),住家用,102.55,3,2,2,有,28500000,500000,0';
        $other = '信義區,房地(土地+建物),臺北市信義區松高路50號,20.10,1130520,華廈(10層含以下有電梯),住家用,88.20,2,1,1,有,19800000,420000,0';
        $noPrice = '中山區,房地(土地+建物),臺北市中山區南京東路一段9號,10.00,1130101,公寓(5樓含以下無電梯),住家用,66.00,2,1,1,無,,,0';

        $csv .= $row."\n".$row."\n".$other."\n".$noPrice."\n";

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
