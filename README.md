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

Import from another URL:

```bash
php artisan real-estate:import --url="https://example.com/data.zip"
```

Imports are idempotent: each row carries a `row_hash` built from its identity
fields plus how many times that identity has appeared in the run, so re-running
an import updates known rows in place instead of duplicating the dataset, while
two genuinely identical transactions still stay two rows. Use `--fresh` only
when you want to start from an empty table.

The summary table reports `Repeats` (rows sharing an identity with an earlier
row in the same run - imported, just flagged) and `Skipped` (rows without a
district, address or total price - the `_build`, `_land`, `_park` sub-files and
the `c` rental files have no sale price and are expected to land here).

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
```

Useful query parameters:

| Parameter | Example |
| --- | --- |
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
/api/transactions?district=大安區&keyword=復興南路&per_page=30
```

## Notes

This MVP intentionally uses official batch data instead of crawling the public search page.
The import parser keeps flexible Chinese-header mappings because the government CSV schema can change over time.
