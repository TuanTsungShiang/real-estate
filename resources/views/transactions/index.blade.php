<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>實價登錄 MVP</title>
    <style>
        :root {
            color-scheme: light;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #1f2937;
            background: #f6f7f9;
        }
        body {
            margin: 0;
        }
        main {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px 18px 42px;
        }
        h1 {
            margin: 0 0 6px;
            font-size: 28px;
        }
        .muted {
            color: #6b7280;
        }
        .toolbar,
        .stats,
        table {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        .toolbar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            padding: 16px;
            margin: 22px 0 14px;
        }
        label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #4b5563;
        }
        input,
        select {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 9px 10px;
            font-size: 14px;
            background: #fff;
        }
        button,
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 6px;
            padding: 10px 14px;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }
        button {
            color: #fff;
            background: #2563eb;
        }
        .button {
            color: #374151;
            background: #e5e7eb;
        }
        .actions {
            display: flex;
            gap: 8px;
            align-items: end;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 1px;
            overflow: hidden;
            margin-bottom: 14px;
        }
        .stat {
            padding: 14px 16px;
            background: #fff;
        }
        .stat strong {
            display: block;
            font-size: 20px;
            margin-top: 4px;
        }
        .table-wrap {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
        }
        th,
        td {
            padding: 11px 12px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            white-space: nowrap;
            font-size: 14px;
        }
        th {
            color: #4b5563;
            background: #f9fafb;
            font-size: 12px;
        }
        td.address {
            min-width: 260px;
            white-space: normal;
        }
        .pagination {
            margin-top: 16px;
        }
        .pager {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .pager-link {
            display: inline-block;
            padding: 8px 14px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #fff;
            color: #1f2937;
            font-size: 14px;
            text-decoration: none;
        }
        .pager-link.is-disabled {
            color: #9ca3af;
            background: #f3f4f6;
        }
        .pager-status {
            font-size: 13px;
            color: #6b7280;
        }        @media (max-width: 900px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .actions {
                grid-column: span 2;
            }
        }
    </style>
</head>
<body>
<main>
    <header>
        <h1>實價登錄 MVP</h1>
        <div class="muted">官方 Open Data 匯入後的第一版查詢介面。</div>
    </header>

    <form class="toolbar" method="get" action="{{ route('transactions.index') }}">
        <div>
            <label for="city">縣市</label>
            <select id="city" name="city" onchange="this.form.district.value = ''; this.form.submit();">
                <option value="">全部</option>
                @foreach($cities as $city)
                    <option value="{{ $city }}" @selected(($filters['city'] ?? null) === $city)>{{ $city }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="district">行政區</label>
            <select id="district" name="district">
                <option value="">全部</option>
                @foreach($districts as $district)
                    <option value="{{ $district }}" @selected(($filters['district'] ?? null) === $district)>{{ $district }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="keyword">地址關鍵字</label>
            <input id="keyword" name="keyword" value="{{ $filters['keyword'] }}" placeholder="例：復興南路">
        </div>
        <div>
            <label for="building_type">建物型態</label>
            <input id="building_type" name="building_type" value="{{ $filters['building_type'] }}" placeholder="住宅大樓">
        </div>
        <div>
            <label for="min_total_price">最低總價</label>
            <input id="min_total_price" name="min_total_price" type="number" value="{{ $filters['min_total_price'] }}">
        </div>
        <div>
            <label for="max_total_price">最高總價</label>
            <input id="max_total_price" name="max_total_price" type="number" value="{{ $filters['max_total_price'] }}">
        </div>
        <div class="actions">
            <button type="submit">查詢</button>
            <a class="button" href="{{ route('transactions.index') }}">清除</a>
        </div>
    </form>

    <section class="stats">
        <div class="stat">
            <span class="muted">筆數</span>
            <strong>{{ number_format($summary['total_records']) }}</strong>
        </div>
        <div class="stat">
            <span class="muted">平均總價</span>
            <strong>{{ $summary['avg_total_price'] ? number_format($summary['avg_total_price']) : '-' }}</strong>
        </div>
        <div class="stat">
            <span class="muted">平均單價/坪</span>
            <strong>{{ $summary['avg_unit_price_ping'] ? number_format($summary['avg_unit_price_ping']) : '-' }}</strong>
        </div>
        <div class="stat">
            <span class="muted">最低單價/坪</span>
            <strong>{{ $summary['min_unit_price_ping'] ? number_format($summary['min_unit_price_ping']) : '-' }}</strong>
        </div>
        <div class="stat">
            <span class="muted">最高單價/坪</span>
            <strong>{{ $summary['max_unit_price_ping'] ? number_format($summary['max_unit_price_ping']) : '-' }}</strong>
        </div>
    </section>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>交易日</th>
                <th>縣市</th>
                <th>行政區</th>
                <th>地址</th>
                <th>建物型態</th>
                <th>總價</th>
                <th>單價/坪</th>
                <th>坪數</th>
                <th>格局</th>
                <th>電梯</th>
            </tr>
            </thead>
            <tbody>
            @forelse($transactions as $transaction)
                <tr>
                    <td>{{ optional($transaction->transaction_date)->format('Y-m-d') ?: '-' }}</td>
                    <td>{{ $transaction->city ?: '-' }}</td>
                    <td>{{ $transaction->district }}</td>
                    <td class="address">{{ $transaction->address }}</td>
                    <td>{{ $transaction->building_type ?: '-' }}</td>
                    <td>{{ $transaction->total_price ? number_format($transaction->total_price) : '-' }}</td>
                    <td>{{ $transaction->unit_price_ping ? number_format($transaction->unit_price_ping) : '-' }}</td>
                    <td>{{ $transaction->building_area_sqm ? number_format((float) $transaction->building_area_sqm * 0.3025, 2) : '-' }}</td>
                    <td>{{ $transaction->room_count ?? '-' }}/{{ $transaction->hall_count ?? '-' }}/{{ $transaction->bathroom_count ?? '-' }}</td>
                    <td>{{ $transaction->has_elevator === null ? '-' : ($transaction->has_elevator ? '有' : '無') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="muted">目前沒有資料。先執行 php artisan real-estate:import --limit=1000。</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">
        {{ $transactions->links("pagination.simple") }}
    </div>
</main>
</body>
</html>
