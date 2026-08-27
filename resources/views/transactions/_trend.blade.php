@php
    // Two stacked single-series charts sharing one x scale, never one plot with
    // two y axes: the alignment of two scales would invent a correlation.
    $months = $trend->values();

    if ($months->count() > 60) {
        $months = $months->slice(-60)->values();
    }

    $n = $months->count();

    $W = 960;
    $padL = 68;
    $padR = 92;
    $plotW = $W - $padL - $padR;
    $band = $n > 0 ? $plotW / $n : 0;
    $barW = max(2.0, min(24.0, $band - 2));   // 2px surface gap between bars
    $x = fn (int $i) => $padL + $band * ($i + 0.5);

    $niceStep = function (float $raw): float {
        if ($raw <= 0) {
            return 1;
        }

        $magnitude = 10 ** floor(log10($raw));

        return match (true) {
            $raw / $magnitude <= 1 => 1,
            $raw / $magnitude <= 2 => 2,
            $raw / $magnitude <= 2.5 => 2.5,
            $raw / $magnitude <= 5 => 5,
            default => 10,
        } * $magnitude;
    };

    $minSample = (int) config('real_estate.trend_min_sample', 5);

    // A month with a couple of sales has a meaningless median, so show its
    // volume but leave a gap in the price line rather than a spike.
    $priceAt = fn ($m) => $m->priced_records >= $minSample ? $m->median_unit_price_ping : null;

    $prices = $months->map($priceAt)->filter(fn ($v) => $v !== null);
    $hasPrice = $prices->count() >= 2;

    $pTop = 18;
    $pBottom = 150;

    if ($hasPrice) {
        $pMin = (float) $prices->min();
        $pMax = (float) $prices->max();
        $spread = $pMax - $pMin;
        $headroom = $spread > 0 ? $spread * 0.18 : max(1.0, $pMax * 0.1);
        $pStep = $niceStep((($pMax + $headroom) - max(0.0, $pMin - $headroom)) / 3);
        $pLo = max(0.0, floor(($pMin - $headroom) / $pStep) * $pStep);
        $pHi = ceil(($pMax + $headroom) / $pStep) * $pStep;

        if ($pHi <= $pLo) {
            $pHi = $pLo + $pStep;
        }

        $pY = fn (float $v) => $pBottom - ($v - $pLo) / ($pHi - $pLo) * ($pBottom - $pTop);

        // Break the line where a month has volume but no priced rows, rather
        // than drawing straight through the gap.
        $segments = [];
        $run = [];

        foreach ($months as $i => $m) {
            if ($priceAt($m) === null) {
                if (count($run) > 0) {
                    $segments[] = $run;
                    $run = [];
                }

                continue;
            }

            $run[] = [$x($i), $pY((float) $priceAt($m))];
        }

        if (count($run) > 0) {
            $segments[] = $run;
        }

        $lastSegment = end($segments) ?: [];
        $endPoint = $lastSegment === [] ? null : end($lastSegment);
        $endValue = $prices->last();

        $pTicks = [];
        for ($t = $pLo; $t <= $pHi + 0.0001; $t += $pStep) {
            $pTicks[] = $t;
        }
    }

    $vTop = 14;
    $vBottom = 96;
    $vMax = (float) max(1, $months->max('total_records') ?? 1);
    $vStep = $niceStep($vMax / 2);
    $vHi = max($vStep, ceil($vMax / $vStep) * $vStep);
    $vY = fn (float $v) => $vBottom - ($v / $vHi) * ($vBottom - $vTop);

    $labelAll = $n <= 14;
    $isLabelled = fn (string $month, int $i) => $labelAll || in_array(substr($month, -2), ['01', '07'], true);
@endphp

<section class="trend">
    <header class="trend-head">
        <h2>走勢</h2>
        <p class="muted">
            @if($n === 0)
                目前的篩選條件沒有可用的交易日期。
            @else
                依目前篩選條件，{{ $months->first()->month }} ～ {{ $months->last()->month }}，共 {{ $n }} 個月
                @if($trend->count() > $n)
                    （僅顯示最近 60 個月）
                @endif
            @endif
        </p>
    </header>

    @if($n < 2)
        <p class="trend-empty">
            資料只涵蓋 {{ $n }} 個月，看不出走勢。先用
            <code>php artisan real-estate:import --season=110S3-115S2</code>
            匯入歷史季度資料，或放寬篩選條件。
        </p>
    @else
        <figure class="trend-figure">
            <figcaption>單價／坪 中位數<span class="muted">（元，每月至少 {{ $minSample }} 筆有單價的成交才計入；線段中斷代表該月樣本不足）</span></figcaption>

            @if(! $hasPrice)
                <p class="trend-empty">這個範圍內沒有任何月份達到 {{ $minSample }} 筆有單價的成交，畫不出價格走勢。</p>
            @else
                <svg viewBox="0 0 {{ $W }} 168" role="img" aria-label="每月平均單價每坪走勢" class="chart">
                    @foreach($pTicks as $tick)
                        <line x1="{{ $padL }}" y1="{{ round($pY($tick), 2) }}" x2="{{ $W - $padR }}" y2="{{ round($pY($tick), 2) }}"
                              stroke="{{ $tick == $pLo ? 'var(--axis)' : 'var(--grid)' }}" stroke-width="1"/>
                        <text x="{{ $padL - 10 }}" y="{{ round($pY($tick) + 4, 2) }}" class="tick" text-anchor="end">{{ number_format($tick) }}</text>
                    @endforeach

                    @foreach($segments as $segment)
                        @if(count($segment) > 1)
                            <path d="M {{ collect($segment)->map(fn ($p) => round($p[0], 2).' '.round($p[1], 2))->implode(' L ') }} L {{ round(end($segment)[0], 2) }} {{ $pBottom }} L {{ round($segment[0][0], 2) }} {{ $pBottom }} Z"
                                  fill="var(--series-1)" fill-opacity="0.1"/>
                        @endif
                        <path d="M {{ collect($segment)->map(fn ($p) => round($p[0], 2).' '.round($p[1], 2))->implode(' L ') }}"
                              fill="none" stroke="var(--series-1)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
                    @endforeach

                    @if($endPoint)
                        <circle cx="{{ round($endPoint[0], 2) }}" cy="{{ round($endPoint[1], 2) }}" r="4.5"
                                fill="var(--series-1)" stroke="var(--surface)" stroke-width="2"/>
                        <text x="{{ round($endPoint[0] + 12, 2) }}" y="{{ round($endPoint[1] + 4, 2) }}" class="end-label">{{ number_format($endValue) }}</text>
                    @endif
                </svg>
            @endif
        </figure>

        <figure class="trend-figure">
            <figcaption>每月成交量<span class="muted">（件）</span></figcaption>

            <svg viewBox="0 0 {{ $W }} 132" role="img" aria-label="每月成交量" class="chart">
                @foreach([0, $vHi / 2, $vHi] as $tick)
                    <line x1="{{ $padL }}" y1="{{ round($vY($tick), 2) }}" x2="{{ $W - $padR }}" y2="{{ round($vY($tick), 2) }}"
                          stroke="{{ $tick == 0 ? 'var(--axis)' : 'var(--grid)' }}" stroke-width="1"/>
                    <text x="{{ $padL - 10 }}" y="{{ round($vY($tick) + 4, 2) }}" class="tick" text-anchor="end">{{ number_format($tick) }}</text>
                @endforeach

                @foreach($months as $i => $m)
                    @continue($m->total_records === 0)
                    @php
                        $bx = $x($i) - $barW / 2;
                        $by = $vY((float) $m->total_records);
                        $bh = $vBottom - $by;
                        $r = min(4, $barW / 2, $bh);
                    @endphp
                    <path d="M {{ round($bx, 2) }} {{ $vBottom }} L {{ round($bx, 2) }} {{ round($by + $r, 2) }} Q {{ round($bx, 2) }} {{ round($by, 2) }} {{ round($bx + $r, 2) }} {{ round($by, 2) }} L {{ round($bx + $barW - $r, 2) }} {{ round($by, 2) }} Q {{ round($bx + $barW, 2) }} {{ round($by, 2) }} {{ round($bx + $barW, 2) }} {{ round($by + $r, 2) }} L {{ round($bx + $barW, 2) }} {{ $vBottom }} Z"
                          fill="var(--series-1)"/>
                @endforeach

                {{-- Separate pass: a month with no sales still needs its tick. --}}
                @foreach($months as $i => $m)
                    @if($isLabelled($m->month, $i))
                        <text x="{{ round($x($i), 2) }}" y="{{ $vBottom + 20 }}" class="tick" text-anchor="middle">{{ str_replace('-', '/', $m->month) }}</text>
                    @endif
                @endforeach

                {{-- Band-wide hit areas so the tooltip target is the column, not the bar. --}}
                @foreach($months as $i => $m)
                    <rect x="{{ round($x($i) - $band / 2, 2) }}" y="0" width="{{ round($band, 2) }}" height="{{ $vBottom }}" fill="transparent">
                        <title>{{ str_replace('-', '/', $m->month) }} ・ 成交 {{ number_format($m->total_records) }} 件（其中 {{ number_format($m->priced_records) }} 件有單價）@if($priceAt($m)) ・ 單價/坪中位數 {{ number_format($priceAt($m)) }} 元@elseif($m->priced_records > 0) ・ 樣本不足 {{ $minSample }} 筆，不計入走勢@endif</title>
                    </rect>
                @endforeach
            </svg>
        </figure>

        <details class="trend-table">
            <summary>以表格檢視這 {{ $n }} 個月</summary>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>月份</th>
                        <th>成交量</th>
                        <th>有單價</th>
                        <th>單價/坪 中位數</th>
                        <th>單價/坪 平均</th>
                        <th>平均總價</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($months->reverse() as $m)
                        <tr>
                            <td>{{ str_replace('-', '/', $m->month) }}</td>
                            <td>{{ number_format($m->total_records) }}</td>
                            <td>{{ number_format($m->priced_records) }}{{ $m->priced_records > 0 && $m->priced_records < $minSample ? ' ※' : '' }}</td>
                            <td>{{ $m->median_unit_price_ping ? number_format($m->median_unit_price_ping) : '-' }}</td>
                            <td>{{ $m->avg_unit_price_ping ? number_format($m->avg_unit_price_ping) : '-' }}</td>
                            <td>{{ $m->avg_total_price ? number_format($m->avg_total_price) : '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</section>
