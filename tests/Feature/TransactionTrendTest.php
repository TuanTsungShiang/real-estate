<?php

namespace Tests\Feature;

use App\Models\RealEstateTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionTrendTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_it_reports_volume_and_median_per_month(): void
    {
        // 2024-01: five priced sales, so the median counts.
        foreach ([100, 200, 300, 400, 500] as $price) {
            $this->sale('2024-01-15', $price);
        }

        // 2024-02: four priced sales, an even count.
        foreach ([100, 200, 300, 500] as $price) {
            $this->sale('2024-02-10', $price);
        }

        $months = $this->getJson('/api/transactions/trend')->json('months');

        $this->assertSame('2024-01', $months[0]['month']);
        $this->assertSame(5, $months[0]['total_records']);
        $this->assertSame(300, $months[0]['median_unit_price_ping']);
        $this->assertSame(300, $months[0]['avg_unit_price_ping']);

        // Even count: the mean of the two middle rows, 200 and 300.
        $this->assertSame(250, $months[1]['median_unit_price_ping']);
        $this->assertSame(275, $months[1]['avg_unit_price_ping']);
    }

    public function test_the_median_ignores_an_extreme_sale_that_the_mean_follows(): void
    {
        foreach ([100, 110, 120, 130, 10_000] as $price) {
            $this->sale('2024-01-15', $price);
        }

        $month = $this->getJson('/api/transactions/trend')->json('months.0');

        $this->assertSame(120, $month['median_unit_price_ping']);
        $this->assertSame(2092, $month['avg_unit_price_ping']);
    }

    public function test_land_rows_count_as_volume_but_not_as_price(): void
    {
        foreach ([100, 200, 300] as $price) {
            $this->sale('2024-01-15', $price);
        }

        $this->sale('2024-01-20', null);
        $this->sale('2024-01-21', null);

        $month = $this->getJson('/api/transactions/trend')->json('months.0');

        $this->assertSame(5, $month['total_records']);
        $this->assertSame(3, $month['priced_records']);
        $this->assertSame(200, $month['median_unit_price_ping']);
    }

    public function test_months_with_no_sales_still_appear_so_the_axis_is_a_time_axis(): void
    {
        $this->sale('2024-01-15', 100);
        $this->sale('2024-04-15', 200);

        $months = collect($this->getJson('/api/transactions/trend')->json('months'));

        $this->assertSame(['2024-01', '2024-02', '2024-03', '2024-04'], $months->pluck('month')->all());
        $this->assertSame([1, 0, 0, 1], $months->pluck('total_records')->all());
    }

    public function test_the_trend_follows_the_filters(): void
    {
        $this->sale('2024-01-15', 100, city: '臺北市', district: '大安區');
        $this->sale('2024-02-15', 200, city: '基隆市', district: '中正區');

        $months = collect($this->getJson('/api/transactions/trend?city=臺北市')->json('months'));

        $this->assertCount(1, $months);
        $this->assertSame('2024-01', $months->first()['month']);
    }

    public function test_a_date_filter_narrows_the_trend(): void
    {
        $this->sale('2024-01-15', 100);
        $this->sale('2024-02-15', 200);
        $this->sale('2024-03-15', 300);

        $months = collect(
            $this->getJson('/api/transactions/trend?date_from=2024-02-01&date_to=2024-02-29')->json('months')
        );

        $this->assertSame(['2024-02'], $months->pluck('month')->all());
    }

    public function test_the_trend_is_empty_when_nothing_matches(): void
    {
        $this->sale('2024-01-15', 100);

        $this->assertSame([], $this->getJson('/api/transactions/trend?city=澎湖縣')->json('months'));
    }

    private function sale(
        string $date,
        ?int $unitPricePing,
        string $city = '臺北市',
        string $district = '大安區',
    ): void {
        $this->sequence++;

        RealEstateTransaction::query()->create([
            'row_hash' => sha1('trend-'.$this->sequence),
            'source_file' => 'test.csv',
            'city' => $city,
            'district' => $district,
            'address' => "測試路{$this->sequence}號",
            'transaction_date' => $date,
            'transaction_date_raw' => '1130115',
            'total_price' => 10_000_000,
            'unit_price_ping' => $unitPricePing,
            'raw_payload' => ['鄉鎮市區' => $district],
        ]);
    }
}
