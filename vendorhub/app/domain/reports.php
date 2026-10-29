<?php
declare(strict_types=1);

// Numbers and exports for the dashboards. Days are Lagos days.

function lagos_day(string $db): string
{
    return (new DateTimeImmutable((string) iso($db)))->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d');
}

// Last $days Lagos days, oldest first: [ 'YYYY-MM-DD' => value ].
function empty_days(int $days = 30): array
{
    $out = [];
    $d = (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->modify('-' . ($days - 1) . ' days');
    for ($i = 0; $i < $days; $i++) { $out[$d->format('Y-m-d')] = 0.0; $d = $d->modify('+1 day'); }
    return $out;
}

// What the seller earned per day (paid items only), in their busiest currency.
function seller_daily_earnings(string $sellerId, int $days = 30): array
{
    $rows = db()->all("SELECT o.currency, o.placed_at, i.net FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.seller_id = ? AND i.status = 'paid' AND o.placed_at > ?", [$sellerId, db_plus('-' . ($days + 1) . ' days')]);
    return daily_by_currency($rows, 'net', $days);
}

function daily_by_currency(array $rows, string $col, int $days): array
{
    $by = [];
    foreach ($rows as $r) { $by[$r['currency']] = ($by[$r['currency']] ?? 0) + (float) $r[$col]; }
    arsort($by);
    $cur = array_key_first($by) ?? 'NGN';
    $series = empty_days($days);
    foreach ($rows as $r) {
        if ($r['currency'] !== $cur) continue;
        $k = lagos_day($r['placed_at']);
        if (isset($series[$k])) $series[$k] += (float) $r[$col];
    }
    return ['currency' => $cur, 'series' => array_map('round2', $series)];
}

function seller_to_process(string $sellerId): int
{
    return (int) db()->val("SELECT COUNT(DISTINCT order_id) FROM order_items WHERE seller_id = ? AND status = 'paid' AND fulfilment IN ('placed', 'processing')", [$sellerId]);
}

function admin_overview(): array
{
    $db = db();
    $since = db_plus('-30 days');
    $rows = $db->all("SELECT o.currency, o.placed_at, i.line_total, i.commission, i.order_id FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.status = 'paid' AND o.placed_at > ?", [$since]);
    $per = [];
    foreach ($rows as $r) {
        $c = &$per[$r['currency']];
        $c ??= ['gross' => 0.0, 'commission' => 0.0, 'orders' => []];
        $c['gross'] += (float) $r['line_total'];
        $c['commission'] += (float) $r['commission'];
        $c['orders'][$r['order_id']] = true;
        unset($c);
    }
    foreach ($per as $cur => $c) $per[$cur] = ['currency' => $cur, 'gross' => round2($c['gross']), 'commission' => round2($c['commission']), 'orders' => count($c['orders'])];
    uasort($per, fn ($a, $b) => $b['gross'] <=> $a['gross']);
    $owed = [];
    foreach ($db->all('SELECT currency, SUM(amount) AS total FROM wallet_entries GROUP BY currency') as $r) $owed[$r['currency']] = round2((float) $r['total']);
    $waiting = [];
    foreach ($db->all("SELECT currency, SUM(amount) AS total FROM withdrawals WHERE status = 'requested' GROUP BY currency") as $r) $waiting[$r['currency']] = round2((float) $r['total']);
    return [
        'by_category' => commission_by_category($since),
        'sales' => array_values($per),
        'chart' => daily_by_currency($rows, 'line_total', 30),
        'owed' => $owed,
        'withdrawals_waiting' => $waiting,
        'sellers' => (int) $db->val("SELECT COUNT(*) FROM seller_verifications WHERE status = 'approved'"),
        'live_products' => (int) $db->val('SELECT COUNT(*) FROM products WHERE live = 1'),
        'new_orders' => (int) $db->val("SELECT COUNT(DISTINCT order_id) FROM order_items WHERE status = 'paid' AND fulfilment = 'placed'"),
        'top' => $db->all("SELECT p.name, SUM(i.quantity) AS units, SUM(i.line_total) AS revenue, o.currency FROM order_items i JOIN orders o ON o.id = i.order_id JOIN products p ON p.id = i.product_id WHERE i.status = 'paid' AND o.placed_at > ? GROUP BY p.id, p.name, o.currency ORDER BY units DESC LIMIT 5", [$since]),
    ];
}

// A small bar chart as inline SVG. Each bar has a hover tooltip; nothing needs JavaScript.
function bar_chart(array $series, string $currency = '', string $label = 'Last 30 days'): string
{
    $max = max($series ?: [0]);
    if ($max <= 0) return '<p class="muted small">No sales in the last 30 days yet.</p>';
    $w = 600; $h = 140; $n = count($series); $gap = 3; $bw = ($w - $gap * ($n - 1)) / $n;
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . ($h + 18) . '" role="img" aria-label="' . e($label) . '">';
    $i = 0;
    foreach ($series as $day => $v) {
        $bh = $v > 0 ? max(2, $v / $max * $h) : 1;
        $x = $i * ($bw + $gap);
        $svg .= '<rect x="' . round($x, 2) . '" y="' . round($h - $bh, 2) . '" width="' . round($bw, 2) . '" height="' . round($bh, 2) . '" rx="2"' . ($v > 0 ? '' : ' class="zero"') . '><title>' . e((new DateTimeImmutable($day))->format('j M') . ': ' . money($v, $currency ?: 'NGN')) . '</title></rect>';
        $i++;
    }
    $keys = array_keys($series);
    $svg .= '<text x="0" y="' . ($h + 14) . '" class="axis">' . e((new DateTimeImmutable($keys[0]))->format('j M')) . '</text><text x="' . $w . '" y="' . ($h + 14) . '" text-anchor="end" class="axis">' . e((new DateTimeImmutable((string) end($keys)))->format('j M')) . '</text></svg>';
    return $svg;
}

// ---------------------------------------------------------------- CSV

// Quotes every field and defuses spreadsheet formulas (a cell starting with = + - @ would otherwise run).
function csv_line(array $fields): string
{
    $safe = function ($v): string {
        $s = (string) $v;
        if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s)) $s = "'" . $s;
        return '"' . str_replace('"', '""', $s) . '"';
    };
    return implode(',', array_map($safe, $fields)) . "\r\n";
}

function csv_out(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '', $filename) . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . csv_line($header);
    foreach ($rows as $r) echo csv_line($r);
    exit;
}

function range_from_input(string $from, string $to): array
{
    $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : null;
    $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : null;
    $conv = fn (?string $d, string $time) => $d ? local_to_iso($d . 'T' . $time) : null;
    return [$f ? from_iso((string) $conv($f, '00:00')) : null, $t ? from_iso((string) $conv($t, '23:59')) : null];
}

function export_seller_sales(string $sellerId, ?string $from, ?string $to): array
{
    $sql = 'SELECT o.number, o.placed_at, i.name, i.sku, i.options, i.quantity, i.unit_price, i.line_total, i.commission, i.net, o.currency, i.status, i.fulfilment, i.carrier, i.tracking_number, o.ship_city, o.ship_state
        FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.seller_id = ?';
    $args = [$sellerId];
    if ($from) { $sql .= ' AND o.placed_at >= ?'; $args[] = $from; }
    if ($to) { $sql .= ' AND o.placed_at <= ?'; $args[] = $to; }
    $rows = [];
    foreach (db()->all($sql . ' ORDER BY o.placed_at DESC', $args) as $r) {
        $opts = jdec($r['options'], []);
        $rows[] = [$r['number'], human_date($r['placed_at'], true), $r['name'], $r['sku'], implode(' / ', (array) $opts), $r['quantity'], $r['unit_price'], $r['line_total'], $r['commission'], $r['net'], $r['currency'],
            $r['status'] === 'refunded' ? 'Refunded' : (FULFILMENT_LABEL[$r['fulfilment']] ?? $r['fulfilment']), $r['carrier'], $r['tracking_number'], trim($r['ship_city'] . ', ' . $r['ship_state'], ', ')];
    }
    return [['Order', 'Placed', 'Item', 'SKU', 'Options', 'Qty', 'Unit price', 'Sold for', 'Commission', 'You earn', 'Currency', 'Status', 'Carrier', 'Tracking', 'Ship to'], $rows];
}

function export_seller_products(string $sellerId): array
{
    $rows = [];
    foreach (seller_products($sellerId) as $p) {
        [$vis] = product_visibility($p);
        $rows[] = [$p['name'], $p['sku'], $p['product_type'], category_label($p), $p['currency'], $p['regular_price'], $p['sale_price'] ?? '', $p['manage_stock'] ? $p['stock'] : 'not tracked', $p['status'], $vis];
        foreach ($p['variations'] ?? [] as $v) $rows[] = ['  ' . $p['name'] . ' (' . implode(' / ', (array) ($v['options'] ?? [])) . ')', $v['sku'], 'variation', '', $p['currency'], $v['regular_price'] ?? '', $v['sale_price'] ?? '', $v['stock'] ?? '', '', ''];
    }
    return [['Name', 'SKU', 'Type', 'Category', 'Currency', 'Price', 'Sale price', 'Stock', 'Review status', 'Visibility'], $rows];
}

function export_seller_payouts(string $sellerId): array
{
    $rows = [];
    foreach (db()->all('SELECT kind, amount, currency, note, available_at, created_at FROM wallet_entries WHERE seller_id = ? ORDER BY created_at DESC', [$sellerId]) as $r) {
        $rows[] = [human_date($r['created_at'], true), $r['kind'], $r['amount'], $r['currency'], $r['note'], $r['available_at'] >= NOT_DELIVERED_YET ? 'Waiting for delivery' : human_date($r['available_at'])];
    }
    return [['Date', 'Type', 'Amount', 'Currency', 'Note', 'Withdrawable from'], $rows];
}

function export_admin_orders(?string $from, ?string $to): array
{
    $rows = [];
    foreach (admin_orders('all', 5000) as $o) {
        if ($from && $o['placed_at'] < $from) continue;
        if ($to && $o['placed_at'] > $to) continue;
        $rows[] = [$o['number'] ?: $o['marketplace_order_id'], human_date($o['placed_at'], true), $o['customer_name'], trim($o['ship_city'] . ', ' . $o['ship_state'], ', '), implode('; ', array_map(fn ($i) => $i['quantity'] . ' x ' . $i['name'], $o['items'])),
            implode('; ', $o['seller_labels']), $o['total'], $o['currency'], $o['state'] === 'refunded' ? 'Refunded' : FULFILMENT_LABEL[$o['state']], $o['carrier'], $o['tracking']];
    }
    return [['Order', 'Placed', 'Customer', 'Ship to', 'Items', 'Sellers', 'Total', 'Currency', 'Status', 'Carrier', 'Tracking'], $rows];
}

function export_admin_payouts(): array
{
    $rows = [];
    foreach (db()->all('SELECT w.requested_at, w.status, w.amount, w.fee, w.currency, w.reference, w.admin_note, w.bank_name, w.account_number, w.account_name, u.email, w.decided_at
        FROM withdrawals w JOIN users u ON u.id = w.seller_id ORDER BY w.requested_at DESC') as $r) {
        $rows[] = [human_date($r['requested_at'], true), $r['email'], $r['status'], $r['amount'], $r['fee'], (float) $r['amount'] - (float) $r['fee'], $r['currency'], $r['bank_name'], $r['account_number'], $r['account_name'], $r['reference'], $r['admin_note'], human_date($r['decided_at'], true)];
    }
    return [['Requested', 'Seller', 'Status', 'Amount', 'Fee', 'To pay', 'Currency', 'Bank', 'Account number', 'Account name', 'Reference', 'Note', 'Decided'], $rows];
}

// Commission earned per top-level category (everything under it counts towards it), per currency.
function commission_by_category(string $since, ?string $until = null): array
{
    $all = categories_all();
    $sql = "SELECT o.currency, p.category_id, SUM(i.line_total) AS gross, SUM(i.commission) AS commission, SUM(i.quantity) AS units FROM order_items i JOIN orders o ON o.id = i.order_id LEFT JOIN products p ON p.id = i.product_id WHERE i.status = 'paid' AND o.placed_at > ?" . ($until ? ' AND o.placed_at <= ?' : '') . ' GROUP BY o.currency, p.category_id';
    $out = [];
    foreach (db()->all($sql, $until ? [$since, $until] : [$since]) as $r) {
        $chain = category_chain($r['category_id'], $all);
        $name = $chain ? $chain[0]['name'] : 'Uncategorised';
        $k = $r['currency'] . '|' . $name;
        $out[$k] ??= ['currency' => $r['currency'], 'category' => $name, 'gross' => 0.0, 'commission' => 0.0, 'units' => 0];
        $out[$k]['gross'] += (float) $r['gross'];
        $out[$k]['commission'] += (float) $r['commission'];
        $out[$k]['units'] += (int) $r['units'];
    }
    foreach ($out as &$o) { $o['gross'] = round2($o['gross']); $o['commission'] = round2($o['commission']); }
    unset($o);
    usort($out, fn ($a, $b) => [$a['currency'], $b['commission']] <=> [$b['currency'], $a['commission']]);
    return $out;
}

function export_commission(?string $from, ?string $to): array
{
    $rows = [];
    foreach (commission_by_category($from ?? '1970-01-01 00:00:00.000', $to) as $r) $rows[] = [$r['category'], $r['currency'], $r['units'], $r['gross'], $r['commission']];
    return [['Category', 'Currency', 'Units', 'Sales', 'Commission'], $rows];
}
