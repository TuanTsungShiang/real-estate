# Real Estate MVP

Laravel MVP for importing Taiwan real-estate open data and browsing transaction records.

The first data source is the Ministry of the Interior real-price registration open data ZIP:

```text
https://plvr.land.moi.gov.tw/Download?fileName=lvr_landcsv.zip&type=zip
```

## MVP Scope

- Download the official ZIP.
- Extract CSV files.
- Import sale records into `real_estate_transactions`.
- Store the original row in `raw_payload`.
- Browse imported records at `/transactions`.
- Query data from `/api/transactions`.
- Show a lightweight summary at `/api/transactions/summary`.

## Local Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan real-estate:import --limit=1000
php artisan serve
```

Open:

```text
http://localhost:8000/transactions
```

## Import Commands

Import from the default official URL:

```bash
php artisan real-estate:import
```

Limit rows for a quick MVP test:

```bash
php artisan real-estate:import --limit=500
```

Clear existing rows before importing:

```bash
php artisan real-estate:import --fresh
```

Import from a local ZIP:

```bash
php artisan real-estate:import --path=/absolute/path/lvr_landcsv.zip
```

Import past quarters, which is what a trend needs - the default URL only serves
the current period:

```bash
php artisan real-estate:import --season=114S1
php artisan real-estate:import --season=114S1,114S2
php artisan real-estate:import --season=110S3-115S2
```

Seasons are a ROC year and quarter, so `114S1` is 2025 Q1. Each quarterly ZIP is
around 14 MB and 80k rows and takes roughly a minute to download and import.

Import from another URL:

```bash
php artisan real-estate:import --url="https://example.com/data.zip"
```

Imports are idempotent: `row_hash` is derived from the official `編號` that MOI
stamps on every registered case, so re-running an import updates known rows in
place, and two identical-looking transactions registered as separate cases stay
two rows. Exports without a `編號` fall back to a hash of the row contents plus
an occurrence counter. Use `--fresh` only when you want to start from an empty
table.

The summary table reports `Fallback keys` (rows with no `編號`, expected to be 0
for current exports) and `Skipped` (rows without a district, address or total
price - the `_build`, `_land`, `_park` sub-files and the `c` rental files have
no sale price and are expected to land here).

## Tests

```bash
composer test
```

`tests/Feature/RealEstateOpenDataImporterTest.php` builds a ZIP shaped like the
official one (UTF-8 BOM, Chinese header followed by an English one, a duplicated
row) and covers parsing, deduplication, `--limit` and temp-file cleanup.

## API

```text
GET /api/transactions
GET /api/transactions/summary
GET /api/transactions/trend
```

Useful query parameters:

| Parameter | Example |
| --- | --- |
| `city` | `臺北市` |
| `district` | `大安區` |
| `keyword` | `復興南路` |
| `building_type` | `住宅大樓` |
| `min_total_price` | `10000000` |
| `max_total_price` | `30000000` |
| `date_from` | `2024-01-01` |
| `date_to` | `2026-12-31` |
| `per_page` | `50` |

Example:

```text
/api/transactions?city=臺北市&district=大安區&keyword=復興南路&per_page=30
```

District names repeat across counties - `中正區` is both 臺北市 and 基隆市 - so
filter on `district` alone only when you mean every county at once. The CSV has
no city column; `city` is derived from the county letter that prefixes each
source file, mapped in `config/real_estate.php`.


## Trend

`/api/transactions/trend` and the chart on `/transactions` break whatever the
current filters select into months, so a district or a street name becomes a
trend line. Every month between the first and the last is emitted, including
empty ones, so the x axis is a time axis rather than a list of months that
happen to have rows.

Each month reports `total_records`, `priced_records`, `median_unit_price_ping`,
`avg_unit_price_ping` and `avg_total_price`. The chart plots the **median**: a
single 400M luxury sale drags a month's mean far enough to flatten the whole
line. Months with fewer than `real_estate.trend_min_sample` priced sales leave a
gap in the price line rather than a spike, and their volume bar still shows.

Dates that cannot be real - a dropped digit turns 1100210 into 民國11年, and some
rows carry a contract date in the future - are imported with a null
`transaction_date`. The original value stays in `transaction_date_raw` and
`raw_payload`.

## Notes

This MVP intentionally uses official batch data instead of crawling the public search page.
The import parser keeps flexible Chinese-header mappings because the government CSV schema can change over time.
