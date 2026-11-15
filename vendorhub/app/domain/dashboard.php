<?php
declare(strict_types=1);

// The seller sales dashboard. One pass over the seller's orders for the chosen period and the period before it.
// Days are Lagos days. Everything is drawn as plain inline SVG/HTML, so it needs no JavaScript.

const DASH_RANGES = ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', '12m' => 'Last 12 months'];
const DASH_METRICS = ['net' => 'Earnings', 'gross' => 'Sales', 'orders' => 'Orders'];
const DASH_ADMIN_METRICS = ['commission' => 'Commission', 'gross' => 'Sales', 'orders' => 'Orders'];
const DASH_DAYS = ['7d' => 7, '30d' => 30, '90d' => 90];

function dash_tz(): DateTimeZone
{
    return new DateTimeZone('Africa/Lagos');
}

/** The chosen period, the period before it (same length) and how it is cut up: day, week or month. */
function dash_window(string $range): array
{
    $today = (new DateTimeImmutable('now', dash_tz()))->setTime(0, 0);
    $end = $today->modify('+1 day');
    if ($range === '12m') {
        $start = $today->modify('first day of this month')->modify('-11 months');
        $prev = $start->modify('-12 months');
        $unit = 'month';
    } else {
        $n = DASH_DAYS[$range] ?? 30;
        $start = $today->modify('-' . ($n - 1) . ' days');
        $prev = $start->modify('-' . $n . ' days');
        $unit = $n > 31 ? 'week' : 'day';
    }
    return ['start' => $start, 'end' => $end, 'prev' => $prev, 'unit' => $unit];
}

function dash_idx(DateTimeImmutable $d, DateTimeImmutable $from, string $unit): int
{
    if ($unit === 'month') return ((int) $d->format('Y') - (int) $from->format('Y')) * 12 + (int) $d->format('n') - (int) $from->format('n');
    $days = (int) $from->setTime(0, 0)->diff($d->setTime(0, 0))->format('%r%a');
    return $unit === 'week' ? intdiv($days, 7) : $days;
}

function dash_utc(DateTimeImmutable $d): string
{
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
}

function dash_blank(int $n): array
{
    return ['comm' => array_fill(0, $n, 0.0), 'gross' => array_fill(0, $n, 0.0), 'net' => array_fill(0, $n, 0.0), 'orders' => array_fill(0, $n, []), 'units' => 0, 'commission' => 0.0, 'refunded' => 0, 'items' => 0, 'order_ids' => []];
}

/** Percent change and its direction. "new" when there was nothing before. */
function dash_delta(float $now, float $before): array
{
    if ($before <= 0) return ['dir' => $now > 0 ? 'new' : 'flat', 'pct' => null];
    $p = ($now - $before) / $before * 100;
    return ['dir' => abs($p) < 0.05 ? 'flat' : ($p > 0 ? 'up' : 'down'), 'pct' => round($p, 1)];
}

function dash_money(float $n, string $cur): string
{
    $symbol = CURRENCY_SYMBOLS[$cur] ?? ($cur . ' ');
    return $symbol . number_format($n, abs($n) >= 1000 ? 0 : 2);
}

/** 1.2K, 3.4M for axis labels. */
function dash_short(float $n, string $cur = ''): string
{
    $s = $cur !== '' ? (CURRENCY_SYMBOLS[$cur] ?? '') : '';
    if ($n >= 1000000) return $s . rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    if ($n >= 1000) return $s . rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    return $s . (string) (int) round($n);
}

/** One seller's dashboard, or the whole marketplace's when $sellerId is null. */
function dash_data(?string $sellerId, string $range = '30d', string $cur = '', string $metric = 'net'): array
{
    $platform = $sellerId === null;
    $metrics = $platform ? DASH_ADMIN_METRICS : DASH_METRICS;
    $range = isset(DASH_RANGES[$range]) ? $range : '30d';
    $metric = isset($metrics[$metric]) ? $metric : ($platform ? 'commission' : 'net');
    $who = $platform ? '' : 'i.seller_id = ? AND ';
    $whoP = $platform ? [] : [$sellerId];
    $w = dash_window($range);
    $tz = dash_tz();
    $unit = $w['unit'];
    $n = dash_idx($w['end']->modify('-1 day'), $w['start'], $unit) + 1;

    $rows = db()->all("SELECT o.id AS order_id, i.seller_id, o.currency, o.placed_at, o.ship_state, i.product_id, i.name, i.quantity, i.line_total, i.net, i.commission, i.status, p.category_id, p.featured_image
        FROM order_items i JOIN orders o ON o.id = i.order_id LEFT JOIN products p ON p.id = i.product_id
        WHERE {$who}o.placed_at >= ? AND o.placed_at < ?", array_merge($whoP, [dash_utc($w['prev']), dash_utc($w['end'])]));

    // Which currency to show: the one asked for, else the one with the most sales.
    $weight = [];
    foreach ($rows as $r) $weight[$r['currency']] = ($weight[$r['currency']] ?? 0) + (float) $r['line_total'];
    arsort($weight);
    $currencies = array_keys($weight);
    $earlier = db()->all('SELECT DISTINCT o.currency FROM order_items i JOIN orders o ON o.id = i.order_id' . ($platform ? '' : ' WHERE i.seller_id = ?'), $whoP);
    foreach ($earlier as $e) if (!in_array($e['currency'], $currencies, true)) $currencies[] = $e['currency'];
    if (!in_array($cur, $currencies, true)) $cur = $currencies[0] ?? 'NGN';

    $P = ['now' => dash_blank($n), 'before' => dash_blank($n)];
    $cats = []; $prods = []; $bySeller = []; $days = array_fill(1, 7, []); $states = [];
    $all = categories_all();

    foreach ($rows as $r) {
        if ($r['currency'] !== $cur) continue;
        $t = (new DateTimeImmutable((string) iso($r['placed_at'])))->setTimezone($tz);
        $isNow = $t >= $w['start'];
        $k = $isNow ? 'now' : 'before';
        if ($r['status'] === 'refunded') { $P[$k]['refunded']++; continue; }
        if ($r['status'] !== 'paid') continue;
        $i = dash_idx($t, $isNow ? $w['start'] : $w['prev'], $unit);
        $P[$k]['items']++;
        $P[$k]['units'] += (int) $r['quantity'];
        $P[$k]['commission'] += (float) $r['commission'];
        $P[$k]['order_ids'][$r['order_id']] = true;
        if ($i >= 0 && $i < $n) {
            $P[$k]['gross'][$i] += (float) $r['line_total'];
            $P[$k]['net'][$i] += (float) $r['net'];
            $P[$k]['comm'][$i] += (float) $r['commission'];
            $P[$k]['orders'][$i][$r['order_id']] = true;
        }
        if (!$isNow) continue;
        $chain = category_chain($r['category_id'], $all);
        $cn = $chain ? $chain[0]['name'] : 'Other';
        $cats[$cn] = ($cats[$cn] ?? 0) + (float) $r['line_total'];
        $pk = ($r['product_id'] ?: $r['name']);
        $prods[$pk] ??= ['name' => $r['name'], 'image' => $r['featured_image'], 'units' => 0, 'gross' => 0.0, 'product_id' => $r['product_id']];
        $prods[$pk]['units'] += (int) $r['quantity'];
        $prods[$pk]['gross'] += (float) $r['line_total'];
        $bySeller[$r['seller_id']] ??= ['gross' => 0.0, 'commission' => 0.0, 'orders' => []];
        $bySeller[$r['seller_id']]['gross'] += (float) $r['line_total'];
        $bySeller[$r['seller_id']]['commission'] += (float) $r['commission'];
        $bySeller[$r['seller_id']]['orders'][$r['order_id']] = true;
        $days[(int) $t->format('N')][$r['order_id']] = true;
        $st = trim((string) $r['ship_state']);
        if ($st !== '') $states[$st][$r['order_id']] = true;
    }
    $now = $P['now']; $before = $P['before'];

    arsort($cats);
    $catParts = []; $other = 0.0;
    foreach ($cats as $name => $v) { if (count($catParts) < 4) $catParts[] = ['name' => $name, 'value' => round2($v)]; else $other += $v; }
    if ($other > 0) $catParts[] = ['name' => 'Other', 'value' => round2($other)];
    usort($prods, fn ($a, $b) => $b['gross'] <=> $a['gross']);
    $stateRows = [];
    foreach ($states as $s => $ids) $stateRows[] = ['name' => $s, 'orders' => count($ids)];
    usort($stateRows, fn ($a, $b) => $b['orders'] <=> $a['orders']);

    $labels = [];
    for ($i = 0; $i < $n; $i++) {
        $d = $unit === 'month' ? $w['start']->modify('+' . $i . ' months') : $w['start']->modify('+' . ($unit === 'week' ? $i * 7 : $i) . ' days');
        $labels[] = $unit === 'month' ? $d->format('M') : $d->format('j M');
    }
    $series = fn (array $p) => $metric === 'orders' ? array_map('count', $p['orders']) : array_map('round2', $p[$metric === 'commission' ? 'comm' : $metric]);

    $totalNow = ['gross' => round2(array_sum($now['gross'])), 'net' => round2(array_sum($now['net'])), 'orders' => count($now['order_ids']), 'units' => $now['units'], 'commission' => round2($now['commission']), 'refunded' => $now['refunded'], 'items' => $now['items']];
    $totalBefore = ['gross' => round2(array_sum($before['gross'])), 'net' => round2(array_sum($before['net'])), 'orders' => count($before['order_ids']), 'units' => $before['units'], 'commission' => round2($before['commission'])];

    $pipeline = ['placed' => 0, 'processing' => 0, 'ready_for_shipping' => 0, 'shipped' => 0];
    foreach (db()->all("SELECT fulfilment, COUNT(DISTINCT order_id) AS n FROM order_items WHERE " . ($platform ? '' : 'seller_id = ? AND ') . "status = 'paid' AND fulfilment IN ('placed', 'processing', 'ready_for_shipping', 'shipped') GROUP BY fulfilment", $whoP) as $r) $pipeline[$r['fulfilment']] = (int) $r['n'];

    $paidOut = (float) db()->val("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE " . ($platform ? '' : 'seller_id = ? AND ') . "currency = ? AND status = 'paid'", array_merge($whoP, [$cur]));

    $extra = [];
    if ($platform) {
        uasort($bySeller, fn ($a, $b) => $b['gross'] <=> $a['gross']);
        $topIds = array_slice(array_keys($bySeller), 0, 5);
        $emails = [];
        foreach ($topIds as $id) $emails[$id] = (string) db()->val('SELECT email FROM users WHERE id = ?', [$id]);
        $topSellers = [];
        foreach ($topIds as $id) $topSellers[] = ['id' => $id, 'name' => store_label($id, $emails), 'gross' => round2($bySeller[$id]['gross']), 'commission' => round2($bySeller[$id]['commission']), 'orders' => count($bySeller[$id]['orders'])];
        $newNow = (int) db()->val("SELECT COUNT(*) FROM seller_verifications WHERE status = 'approved' AND reviewed_at >= ? AND reviewed_at < ?", [dash_utc($w['start']), dash_utc($w['end'])]);
        $newBefore = (int) db()->val("SELECT COUNT(*) FROM seller_verifications WHERE status = 'approved' AND reviewed_at >= ? AND reviewed_at < ?", [dash_utc($w['prev']), dash_utc($w['start'])]);
        $owed = (float) db()->val('SELECT COALESCE(SUM(amount), 0) FROM wallet_entries WHERE currency = ?', [$cur]);
        $waiting = (float) db()->val("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE currency = ? AND status IN ('requested', 'processing')", [$cur]);
        $extra = ['top_sellers' => $topSellers, 'active_sellers' => count($bySeller), 'new_sellers' => $newNow, 'new_before' => $newBefore, 'owed' => round2($owed), 'waiting' => round2($waiting),
            'verified_sellers' => (int) db()->val("SELECT COUNT(*) FROM seller_verifications WHERE status = 'approved'"), 'live_products' => (int) db()->val('SELECT COUNT(*) FROM products WHERE live = 1')];
    }

    return [
        'range' => $range, 'metric' => $metric, 'currency' => $cur, 'currencies' => $currencies, 'unit' => $unit,
        'start' => $w['start'], 'end' => $w['end'],
        'labels' => $labels, 'series' => $series($now), 'previous' => $series($before),
        'now' => $totalNow, 'before' => $totalBefore,
        'avg_order' => $totalNow['orders'] > 0 ? round2($totalNow['gross'] / $totalNow['orders']) : 0.0,
        'avg_before' => $totalBefore['orders'] > 0 ? round2($totalBefore['gross'] / $totalBefore['orders']) : 0.0,
        'return_rate' => ($totalNow['items'] + $totalNow['refunded']) > 0 ? round($totalNow['refunded'] / ($totalNow['items'] + $totalNow['refunded']) * 100, 1) : 0.0,
        'categories' => $catParts, 'top' => array_slice($prods, 0, 5),
        'weekdays' => array_map('count', array_values($days)), 'states' => array_slice($stateRows, 0, 5),
        'pipeline' => $pipeline, 'paid_out' => round2($paidOut), 'platform' => $platform,
    ] + $extra;
}

// ---------------------------------------------------------------- drawing

/** Round axis: returns [step, number of steps] so that step * steps covers $max. */
function dash_scale(float $max): array
{
    if ($max <= 0) return [1.0, 4];
    $rough = $max / 4;
    $mag = 10 ** floor(log10($rough));
    $step = 10 * $mag;
    foreach ([1, 2, 2.5, 5, 10] as $m) if ($rough <= $m * $mag) { $step = $m * $mag; break; }
    return [(float) $step, (int) ceil($max / $step)];
}

/** Bars for this period, a dashed line for the period before. Hovering a column shows both numbers. */
function dash_chart(array $d): string
{
    $series = $d['series']; $prev = $d['previous']; $labels = $d['labels'];
    $isMoney = $d['metric'] !== 'orders'; $cur = $d['currency'];
    $n = count($series);
    $W = 720; $H = 250; $L = $isMoney ? 62 : 38; $R = 8; $T = 10; $B = 28;
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    [$step, $steps] = dash_scale(max(max($series ?: [0]), max($prev ?: [0])));
    $top = $step * $steps;
    $fmt = fn (float $v) => $isMoney ? dash_short($v, $cur) : (string) (int) $v;
    $svg = '<svg class="dchart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e((DASH_METRICS + DASH_ADMIN_METRICS)[$d['metric']] . ' over time') . '"><defs><linearGradient id="dgrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#6fb1f0"/><stop offset="1" stop-color="#2f6db5"/></linearGradient></defs>';
    for ($g = 0; $g <= $steps; $g++) {
        $y = round($T + $ph - $g / $steps * $ph, 1);
        $svg .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y . '" y2="' . $y . '" class="grid"/><text x="' . ($L - 8) . '" y="' . ($y + 4) . '" text-anchor="end" class="axis">' . e($fmt($g * $step)) . '</text>';
    }
    $slot = $pw / $n; $bw = min(34, max(3, $slot * 0.62));
    $every = $n > 13 ? (int) ceil($n / 7) : 1;
    $pts = [];
    foreach ($series as $i => $v) {
        $x = $L + $slot * $i + ($slot - $bw) / 2;
        $bh = $v > 0 ? max(3, $v / $top * $ph) : 0;
        $pv = $prev[$i] ?? 0;
        $tip = $labels[$i] . ': ' . ($isMoney ? money($v, $cur) : (int) $v . ' orders') . ' (before: ' . ($isMoney ? money($pv, $cur) : (int) $pv) . ')';
        $svg .= '<g class="col"><rect class="hit" x="' . round($L + $slot * $i, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . $ph . '"/>'
            . ($bh > 0 ? '<rect class="bar" x="' . round($x, 1) . '" y="' . round($T + $ph - $bh, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="' . min(6, round($bw / 2, 1)) . '" fill="url(#dgrad)"/>' : '<rect class="bar zero" x="' . round($x, 1) . '" y="' . ($T + $ph - 2) . '" width="' . round($bw, 1) . '" height="2" rx="1"/>')
            . '<title>' . e($tip) . '</title></g>';
        $pts[] = round($L + $slot * $i + $slot / 2, 1) . ',' . round($T + $ph - ($pv / $top * $ph), 1);
        if ($i % $every === 0) $svg .= '<text x="' . round($L + $slot * $i + $slot / 2, 1) . '" y="' . ($H - 8) . '" text-anchor="middle" class="axis">' . e($labels[$i]) . '</text>';
    }
    if (array_sum($prev) > 0 && $n > 1) $svg .= '<polyline class="prevline" points="' . implode(' ', $pts) . '"/>';
    return $svg . '</svg>';
}

/** A half-circle split into the given parts. */
function dash_gauge(array $parts, string $big, string $small): string
{
    $total = array_sum(array_column($parts, 'value'));
    $cx = 120; $cy = 118; $r = 92;
    $pt = fn (float $f) => [round($cx + $r * cos(M_PI - $f * M_PI), 2), round($cy - $r * sin(M_PI - $f * M_PI), 2)];
    $arc = function (float $a, float $b) use ($pt, $r): string {
        [$x0, $y0] = $pt($a); [$x1, $y1] = $pt($b);
        return 'M' . $x0 . ' ' . $y0 . ' A' . $r . ' ' . $r . ' 0 0 1 ' . $x1 . ' ' . $y1;
    };
    $svg = '<svg class="dgauge" viewBox="0 0 240 134" role="img" aria-label="Sales by category"><path d="' . $arc(0, 1) . '" class="track"/>';
    if ($total > 0) {
        $at = 0.0; $gap = count($parts) > 1 ? 0.012 : 0.0;
        foreach ($parts as $i => $p) {
            $f = $p['value'] / $total;
            $svg .= '<path d="' . $arc($at + $gap / 2, max($at + $gap / 2 + 0.002, $at + $f - $gap / 2)) . '" class="seg s' . $i . '"><title>' . e($p['name'] . ': ' . round($f * 100) . '%') . '</title></path>';
            $at += $f;
        }
    }
    return $svg . '<text x="120" y="102" text-anchor="middle" class="big">' . e($big) . '</text><text x="120" y="124" text-anchor="middle" class="small">' . e($small) . '</text></svg>';
}

/** "+12.4%" style chip. $goodUp false for numbers where lower is better. */
function dash_chip(array $delta, bool $goodUp = true): string
{
    if ($delta['dir'] === 'new') return '<span class="dchip up">New</span>';
    if ($delta['dir'] === 'flat') return '<span class="dchip">No change</span>';
    $up = $delta['dir'] === 'up';
    $good = $up === $goodUp;
    return '<span class="dchip ' . ($good ? 'up' : 'down') . '">' . ($up ? '+' : '') . rtrim(rtrim(number_format($delta['pct'], 1), '0'), '.') . '%</span>';
}

function dash_greeting(): string
{
    $h = (int) (new DateTimeImmutable('now', dash_tz()))->format('G');
    return $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
}
