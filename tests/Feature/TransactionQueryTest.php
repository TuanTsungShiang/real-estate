<?php

namespace Tests\Feature;

use App\Models\RealEstateTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 中正區 exists in both counties, which is the whole reason the city
        // filter has to be there.
        $this->transaction(city: '臺北市', district: '中正區', address: '臺北市中正區信義路一段1號');
        $this->transaction(city: '臺北市', district: '大安區', address: '臺北市大安區復興南路一段100號');
        $this->transaction(city: '基隆市', district: '中正區', address: '基隆市中正區中正路50號');
    }

    public function test_district_alone_still_spans_counties(): void
    {
        $response = $this->getJson('/api/transactions?district=中正區');

        $response->assertOk();
        $this->assertSame(2, $response->json('total'));
    }

    public function test_city_narrows_the_results(): void
    {
        $this->assertSame(2, $this->getJson('/api/transactions?city=臺北市')->json('total'));
        $this->assertSame(1, $this->getJson('/api/transactions?city=基隆市')->json('total'));
    }

    public function test_city_and_district_together_pick_one_county(): void
    {
        $response = $this->getJson('/api/transactions?city=基隆市&district=中正區');

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('基隆市中正區中正路50號', $response->json('data.0.address'));
    }

    public function test_the_index_page_only_lists_districts_of_the_chosen_city(): void
    {
        $this->get('/transactions?city=基隆市')
            ->assertOk()
            ->assertViewHas('districts', fn ($districts) => $districts->all() === ['中正區']);

        $this->get('/transactions')
            ->assertOk()
            ->assertViewHas('districts', fn ($districts) => $districts->count() === 2);
    }

    public function test_cities_are_listed_north_to_south(): void
    {
        $this->get('/transactions')
            ->assertOk()
            ->assertViewHas('cities', fn ($cities) => $cities->all() === ['臺北市', '基隆市']);
    }

    public function test_dates_serialize_without_a_timezone_shift(): void
    {
        config(['app.timezone' => 'Asia/Taipei']);

        $this->assertSame(
            '2024-03-15',
            $this->getJson('/api/transactions')->json('data.0.transaction_date'),
        );
    }

    public function test_per_page_is_bounded(): void
    {
        $this->assertSame(30, $this->getJson('/api/transactions?per_page=0')->json('per_page'));
        $this->assertSame(30, $this->getJson('/api/transactions?per_page=-5')->json('per_page'));
        $this->assertSame(100, $this->getJson('/api/transactions?per_page=99999')->json('per_page'));
    }

    public function test_a_malformed_date_filter_is_ignored_rather_than_fatal(): void
    {
        $response = $this->getJson('/api/transactions?date_from=abc');

        $response->assertOk();
        $this->assertSame(3, $response->json('total'));
    }

    public function test_the_summary_groups_districts_by_city(): void
    {
        $summary = $this->getJson('/api/transactions/summary')->json();

        $this->assertSame(3, $summary['total_records']);
        $this->assertCount(3, $summary['districts'], '中正區 must not be merged across counties.');
    }

    private function transaction(string $city, string $district, string $address): void
    {
        RealEstateTransaction::query()->create([
            'row_hash' => sha1($city.$district.$address),
            'source_file' => 'test.csv',
            'city' => $city,
            'district' => $district,
            'address' => $address,
            'transaction_date' => '2024-03-15',
            'transaction_date_raw' => '1130315',
            'building_type' => '住宅大樓(11層含以上有電梯)',
            'total_price' => 28500000,
            'unit_price_sqm' => 500000,
            'unit_price_ping' => 1652893,
            'raw_payload' => ['鄉鎮市區' => $district],
        ]);
    }
}
