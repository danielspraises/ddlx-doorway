<?php
declare(strict_types=1);

// Scheduled sales. A seller sets up a sale once (name, dates, which products, how much off) and it runs by itself.
// DDLX can also run a marketplace sale that sellers opt into.
//
// How it works: nothing about the product is edited. The marketplace feed adds the sale price and the start and end
// dates on top of the approved product, and WooCommerce switches the price on and off by those dates. Dates are whole
// Lagos days: a sale starts at 00:00 on its first day and ends at 23:59 on its last day.

const SALE_RULES = ['percent' => '% off', 'amount' => 'Money off', 'fixed' => 'One price', 'custom' => 'Set each price'];
const SALE_MAX_DAYS = 366;

function sale_today(): string
{
    return (new DateTimeImmutable('now', dash_tz()))->format('Y-m-d');
}

function sale_valid_date(string $d): bool
{
    return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function sale_nice_date(string $d): string
{
    return (new DateTimeImmutable($d, dash_tz()))->format('j M Y');
}

/** The moment a sale starts or ends, as ISO time in UTC (what the marketplace understands). */
function sale_iso(string $day, bool $end): string
{
    return (new DateTimeImmutable($day . ($end ? ' 23:59:59' : ' 00:00:00'), dash_tz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function sale_db_time(string $day, bool $end): string
{
    return (new DateTimeImmutable($day . ($end ? ' 23:59:59' : ' 00:00:00'), dash_tz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ($end ? '.999' : '.000');
}

// ---------------------------------------------------------------- loading

/** DDLX marketplace sales by id (a handful), used to tell whether a seller's entry is still valid. */
function sale_parents(bool $reset = false): array
{
    static $cache = null;
    if ($reset || $cache === null) {
        $cache = [];
        foreach (db()->all('SELECT * FROM sale_campaigns WHERE seller_id IS NULL') as $r) $cache[$r['id']] = $r;
    }
    return $cache;
}

function sale_hydrate(array $r): array
{
    $r['product_list'] = jdec((string) ($r['product_ids'] ?? ''), []);
    $r['price_map'] = jdec((string) ($r['prices'] ?? ''), []);
    $r['value'] = (float) $r['value'];
    $r['min_discount'] = $r['min_discount'] === null ? null : (float) $r['min_discount'];
    $r['admin_blocked'] = (int) $r['admin_blocked'];
    $r['phase'] = sale_phase($r);
    return $r;
}

/** scheduled | live | ended | paused | cancelled | blocked */
function sale_phase(array $c): string
{
    $parent = !empty($c['parent_id']) ? (sale_parents()[$c['parent_id']] ?? null) : null;
    if ($c['state'] === 'cancelled' || ($parent && $parent['state'] === 'cancelled')) return 'cancelled';
    if ((int) $c['admin_blocked'] === 1) return 'blocked';
    $today = sale_today();
    if ($c['ends_on'] < $today) return 'ended';
    if ($c['state'] === 'paused') return 'paused';
    return $c['starts_on'] > $today ? 'scheduled' : 'live';
}

const SALE_PHASE_LABEL = ['scheduled' => 'Scheduled', 'live' => 'Live', 'ended' => 'Ended', 'paused' => 'Paused', 'cancelled' => 'Ended early', 'blocked' => 'Switched off by DDLX'];

function sale_get(string $id, ?string $sellerId = null): ?array
{
    if (!is_uuid($id)) return null;
    $r = $sellerId === null ? db()->one('SELECT * FROM sale_campaigns WHERE id = ?', [$id]) : db()->one('SELECT * FROM sale_campaigns WHERE id = ? AND seller_id = ?', [$id, $sellerId]);
    return $r ? sale_hydrate($r) : null;
}

function sale_list(string $sellerId): array
{
    $rows = array_map('sale_hydrate', db()->all('SELECT * FROM sale_campaigns WHERE seller_id = ? ORDER BY starts_on DESC, created_at DESC LIMIT 200', [$sellerId]));
    $order = ['live' => 0, 'scheduled' => 1, 'paused' => 2, 'ended' => 3, 'cancelled' => 3, 'blocked' => 3];
    usort($rows, fn ($a, $b) => [$order[$a['phase']], $a['phase'] === 'scheduled' ? $a['starts_on'] : ''] <=> [$order[$b['phase']], $b['phase'] === 'scheduled' ? $b['starts_on'] : ''] ?: strcmp($b['starts_on'], $a['starts_on']));
    return $rows;
}

// ---------------------------------------------------------------- products and prices

/** A product as the marketplace sells it: the approved version. $row needs id, name, stock, manage_stock, live and published_snapshot. */
function sale_prod(array $row): ?array
{
    $s = jdec((string) $row['published_snapshot'], []);
    if (!$s) return null;
    $vars = [];
    foreach ((array) ($s['variations'] ?? []) as $v) if (isset($v['id'])) $vars[(string) $v['id']] = ['regular' => $v['regular_price'] === null ? null : (float) $v['regular_price'], 'options' => (array) ($v['options'] ?? [])];
    return ['id' => (string) $row['id'], 'name' => (string) ($s['name'] ?? $row['name']), 'image' => $s['featured_image'] ?? null, 'currency' => (string) ($s['currency'] ?? 'NGN'),
        'type' => (string) ($s['type'] ?? 'simple'), 'regular' => $s['regular_price'] === null ? null : (float) $s['regular_price'], 'vars' => $vars,
        'out' => (int) ($row['manage_stock'] ?? 0) === 1 && (int) ($row['stock'] ?? 0) <= 0, 'live' => (int) ($row['live'] ?? 0) === 1];
}

function sale_eligible(string $sellerId): array
{
    $out = [];
    foreach (db()->all('SELECT id, name, stock, manage_stock, live, published_snapshot FROM products WHERE seller_id = ? AND published_snapshot IS NOT NULL ORDER BY name', [$sellerId]) as $r) {
        $p = sale_prod($r);
        if ($p) $out[] = $p;
    }
    return $out;
}

function sale_in_scope(array $c, string $productId): bool
{
    $ids = $c['product_list'] ?? [];
    return match ($c['scope']) { 'selected' => in_array($productId, $ids, true), 'except' => !in_array($productId, $ids, true), default => true };
}

/** The sale price for one regular price, or null when the rule gives nothing sensible (zero, or not lower than regular). */
function sale_calc(string $rule, float $value, float $regular, string $currency): ?float
{
    if ($regular <= 0) return null;
    $p = match ($rule) { 'percent' => $regular * (1 - $value / 100), 'amount' => $regular - $value, 'fixed', 'custom' => $value, default => null };
    if ($p === null) return null;
    $p = $currency === 'NGN' ? round($p) : round($p, 2);
    return $p > 0 && $p < $regular ? $p : null;
}

/** [regular, sale] pairs this campaign gives a product: one for a simple product, one per variation for a variable one. Keyed 'simple' or by variation id. */
function sale_pairs(array $c, array $p): array
{
    if ($c['rule'] !== 'percent' && $p['currency'] !== $c['currency']) return [];
    $value = $c['rule'] === 'custom' ? (float) ($c['price_map'][$p['id']] ?? 0) : $c['value'];
    $out = [];
    if ($p['type'] === 'variable') {
        foreach ($p['vars'] as $vid => $v) {
            $sale = $v['regular'] === null ? null : sale_calc($c['rule'], $value, $v['regular'], $p['currency']);
            if ($sale !== null) $out[$vid] = [$v['regular'], $sale];
        }
    } elseif ($p['regular'] !== null) {
        $sale = sale_calc($c['rule'], $value, $p['regular'], $p['currency']);
        if ($sale !== null) $out['simple'] = [$p['regular'], $sale];
    }
    return $out;
}

/** What the table shows for each product in the sale. */
function sale_rows(array $c, array $eligible): array
{
    $rows = [];
    foreach ($eligible as $p) {
        if (!sale_in_scope($c, $p['id'])) continue;
        $pairs = sale_pairs($c, $p);
        $reg = $pairs ? array_column($pairs, 0) : [];
        $sale = $pairs ? array_column($pairs, 1) : [];
        $off = array_map(fn ($x) => (int) round((1 - $x[1] / $x[0]) * 100), $pairs);
        $rows[] = ['id' => $p['id'], 'name' => $p['name'], 'image' => $p['image'], 'currency' => $p['currency'], 'applied' => (bool) $pairs, 'out' => $p['out'],
            'regular' => $reg ? [min($reg), max($reg)] : null, 'sale' => $sale ? [min($sale), max($sale)] : null, 'off' => $off ? [min($off), max($off)] : null,
            'regular_now' => $p['type'] === 'variable' ? null : $p['regular']];
    }
    usort($rows, fn ($a, $b) => [$b['applied'], $a['name']] <=> [$a['applied'], $b['name']]);
    return $rows;
}

/** Applies the first running campaign that covers this product to its approved version (used by the marketplace feed). */
function sale_overlay(array $snapshot, array $campaigns, array $rowForProd): array
{
    $p = sale_prod($rowForProd);
    if (!$p) return $snapshot;
    foreach ($campaigns as $c) {
        if (!sale_in_scope($c, $p['id'])) continue;
        $pairs = sale_pairs($c, $p);
        if (!$pairs) continue;
        $from = sale_iso($c['starts_on'], false); $to = sale_iso($c['ends_on'], true);
        if ($p['type'] === 'variable') {
            foreach ($snapshot['variations'] as &$v) if (isset($pairs[$v['id']])) { $v['sale_price'] = $pairs[$v['id']][1]; $v['sale_starts_at'] = $from; $v['sale_ends_at'] = $to; }
            unset($v);
        } elseif (isset($pairs['simple'])) {
            $snapshot['sale_price'] = $pairs['simple'][1]; $snapshot['sale_starts_at'] = $from; $snapshot['sale_ends_at'] = $to;
        }
        return $snapshot;
    }
    return $snapshot;
}

/** Sales the marketplace should know about for one seller: scheduled or live, not paused, not switched off. Newest first. */
function sale_feed_campaigns(string $sellerId): array
{
    $out = [];
    foreach (db()->all('SELECT * FROM sale_campaigns WHERE seller_id = ? AND ends_on >= ? ORDER BY created_at DESC', [$sellerId, sale_today()]) as $r) {
        $c = sale_hydrate($r);
        if (in_array($c['phase'], ['scheduled', 'live'], true)) $out[] = $c;
    }
    return $out;
}

/** Makes the next marketplace sync pick up every product these campaigns cover. */
function sale_touch(string $sellerId, array ...$camps): void
{
    $ids = [];
    $eligible = sale_eligible($sellerId);
    foreach ($camps as $c) foreach ($eligible as $p) if (sale_in_scope($c, $p['id'])) $ids[$p['id']] = true;
    foreach (array_chunk(array_keys($ids), 400) as $chunk) db()->exec('UPDATE products SET updated_at = ? WHERE id IN (' . db()->placeholders($chunk) . ')', array_merge([now_db()], $chunk));
}

// ---------------------------------------------------------------- saving

/** Another sale of the same seller on the same days that already covers one of these products. */
function sale_conflicts(string $sellerId, array $c, array $eligible, ?string $ignoreId): array
{
    $mine = [];
    foreach ($eligible as $p) if (sale_in_scope($c, $p['id'])) $mine[$p['id']] = $p['name'];
    if (!$mine) return [];
    $out = [];
    foreach (db()->all('SELECT * FROM sale_campaigns WHERE seller_id = ? AND ends_on >= ? AND starts_on <= ? AND ends_on >= ?', [$sellerId, sale_today(), $c['ends_on'], $c['starts_on']]) as $r) {
        if ($r['id'] === $ignoreId) continue;
        $o = sale_hydrate($r);
        if (in_array($o['phase'], ['cancelled', 'ended', 'blocked'], true)) continue;
        $names = [];
        foreach ($mine as $pid => $name) if (sale_in_scope($o, $pid)) $names[] = $name;
        if ($names) $out[] = ['name' => $o['name'], 'products' => $names];
    }
    return $out;
}

/**
 * Checks what the seller sent. Returns ['error' => ?string, 'c' => the campaign as it would be saved, 'rows' => product rows].
 * $existing is the saved campaign when editing.
 */
function sale_validate(string $sellerId, array $in, ?array $existing): array
{
    $eligible = sale_eligible($sellerId);
    $today = sale_today();
    $fail = fn (string $m, array $c = [], array $rows = []) => ['error' => $m, 'c' => $c, 'rows' => $rows, 'eligible' => $eligible];
    $parent = null;
    $parentId = $existing['parent_id'] ?? (string) ($in['parent_id'] ?? '');
    if ($parentId !== '') {
        $parent = sale_parents()[$parentId] ?? null;
        if (!$parent || $parent['state'] !== 'active' || $parent['ends_on'] < $today) return $fail('That DDLX sale is no longer open.');
        if (!$existing && db()->val("SELECT 1 FROM sale_campaigns WHERE seller_id = ? AND parent_id = ? AND state <> 'cancelled'", [$sellerId, $parentId])) return $fail('You are already in that sale.');
    }
    $name = $parent ? (string) $parent['name'] : trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')));
    $starts = $parent ? (string) $parent['starts_on'] : trim((string) ($in['starts_on'] ?? ''));
    $ends = $parent ? (string) $parent['ends_on'] : trim((string) ($in['ends_on'] ?? ''));
    $rule = (string) ($in['rule'] ?? 'percent');
    $c = ['id' => $existing['id'] ?? '', 'parent_id' => $parentId !== '' ? $parentId : null, 'name' => $name, 'starts_on' => $starts, 'ends_on' => $ends, 'rule' => $rule, 'value' => 0.0,
        'currency' => strtoupper((string) ($in['currency'] ?? ($existing['currency'] ?? 'NGN'))), 'scope' => 'all', 'product_list' => [], 'price_map' => [], 'min_discount' => $parent ? ($parent['min_discount'] === null ? null : (float) $parent['min_discount']) : null];
    if (!in_array($c['currency'], CURRENCIES, true)) $c['currency'] = 'NGN';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) return $fail('Give the sale a name, up to 60 characters.', $c);
    if (!sale_valid_date($starts) || !sale_valid_date($ends)) return $fail('Choose a start and an end date.', $c);
    if ($ends < $starts) return $fail('The sale must end on or after the day it starts.', $c);
    if ((new DateTimeImmutable($starts))->diff(new DateTimeImmutable($ends))->days >= SALE_MAX_DAYS) return $fail('A sale can run for up to a year.', $c);
    if ($ends < $today) return $fail('The end date has already passed.', $c);
    if (!$existing && $starts < $today) return $fail('Start the sale today or later.', $c);
    if ($existing && $existing['starts_on'] !== $starts && $existing['starts_on'] <= $today) return $fail('A sale that has started keeps its start date.', $c);
    if (!isset(SALE_RULES[$rule])) return $fail('Choose how much to take off.', $c);
    $want = array_values(array_unique(array_filter(array_map('strval', (array) ($in['product_ids'] ?? [])), 'is_uuid')));
    $known = array_column($eligible, 'id');
    if ($rule === 'custom') {
        foreach ((array) ($in['price'] ?? []) as $pid => $v) {
            $v = is_string($v) ? str_replace(',', '', trim($v)) : $v;
            if ($v === '' || $v === null) continue;
            if (!is_numeric($v) || (float) $v <= 0 || (float) $v > 1000000000) return $fail('Prices must be numbers above zero.', $c);
            if (in_array((string) $pid, $known, true)) $c['price_map'][(string) $pid] = round((float) $v, 2);
        }
        if (!$c['price_map']) return $fail('Enter a sale price for at least one product.', $c);
        $c['scope'] = 'selected';
        $c['product_list'] = array_keys($c['price_map']);
    } else {
        $v = str_replace(',', '', trim((string) ($in['value'] ?? '')));
        if (!is_numeric($v)) return $fail($rule === 'percent' ? 'Enter the percent off.' : 'Enter an amount.', $c);
        $c['value'] = round((float) $v, 2);
        if ($rule === 'percent' && ($c['value'] < 1 || $c['value'] > 99)) return $fail('Percent off can be from 1 to 99.', $c);
        if ($rule !== 'percent' && ($c['value'] <= 0 || $c['value'] > 1000000000)) return $fail('Enter an amount above zero.', $c);
        $scope = (string) ($in['scope'] ?? 'all');
        $c['scope'] = in_array($scope, ['all', 'selected', 'except'], true) ? $scope : 'all';
        $c['product_list'] = array_values(array_intersect($want, $known));
        if ($c['scope'] === 'selected' && !$c['product_list']) return $fail('Choose at least one product, or put every product in the sale.', $c);
    }
    $rows = sale_rows($c, $eligible);
    $applied = array_filter($rows, fn ($r) => $r['applied']);
    if (!$eligible) return $fail('You have no approved products yet. A sale can only include products that are on the marketplace.', $c, $rows);
    if (!$applied) return $fail('No product gets a lower price with these settings.', $c, $rows);
    if ($c['min_discount'] !== null) {
        $short = array_values(array_filter($applied, fn ($r) => $r['off'][0] < $c['min_discount']));
        if ($short) return $fail('This sale needs at least ' . rtrim(rtrim(number_format($c['min_discount'], 2), '0'), '.') . '% off. ' . count($short) . ' product' . (count($short) === 1 ? ' falls' : 's fall') . ' short, such as ' . $short[0]['name'] . '.', $c, $rows);
    }
    $clash = sale_conflicts($sellerId, $c, $eligible, $existing['id'] ?? null);
    if ($clash) return $fail('"' . $clash[0]['name'] . '" already covers ' . $clash[0]['products'][0] . (count($clash[0]['products']) > 1 ? ' and ' . (count($clash[0]['products']) - 1) . ' more' : '') . ' on those days. Change the dates or the products.', $c, $rows);
    return ['error' => null, 'c' => $c, 'rows' => $rows, 'eligible' => $eligible];
}

function sale_save(string $sellerId, array $in, ?array $existing): array
{
    if ($existing && in_array($existing['phase'], ['ended', 'cancelled', 'blocked'], true)) return ['error' => 'This sale can no longer be changed.'] + ['c' => $existing, 'rows' => [], 'eligible' => []];
    $v = sale_validate($sellerId, $in, $existing);
    if ($v['error']) return $v;
    $c = $v['c'];
    $now = now_db();
    $row = ['name' => $c['name'], 'starts_on' => $c['starts_on'], 'ends_on' => $c['ends_on'], 'rule' => $c['rule'], 'value' => $c['value'], 'currency' => $c['currency'], 'scope' => $c['scope'],
        'product_ids' => $c['product_list'] ? jenc($c['product_list']) : null, 'prices' => $c['price_map'] ? jenc($c['price_map']) : null, 'updated_at' => $now];
    if ($existing) {
        if ($existing['starts_on'] !== $c['starts_on']) $row['reminded_start'] = 0;
        if ($existing['ends_on'] !== $c['ends_on']) $row['notified_end'] = 0;
        $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($row)));
        db()->exec("UPDATE sale_campaigns SET $sets WHERE id = ? AND seller_id = ?", array_merge(array_values($row), [$existing['id'], $sellerId]));
        $c['id'] = $existing['id'];
        sale_touch($sellerId, $existing, $c);
    } else {
        $c['id'] = uuid();
        db()->insert('sale_campaigns', $row + ['id' => $c['id'], 'seller_id' => $sellerId, 'parent_id' => $c['parent_id'], 'note' => '', 'min_discount' => null, 'state' => 'active', 'admin_blocked' => 0, 'reminded_start' => 0, 'notified_end' => 0, 'created_at' => $now]);
        sale_touch($sellerId, $c);
    }
    $v['c'] = $c;
    return $v;
}

/** pause | resume | cancel | delete. Returns an error message or null. */
function sale_action(string $sellerId, string $id, string $action): ?string
{
    $c = sale_get($id, $sellerId);
    if (!$c) return 'Sale not found.';
    $phase = $c['phase'];
    if ($action === 'delete') {
        if (!in_array($phase, ['ended', 'cancelled'], true)) return 'End the sale before deleting it.';
        db()->exec('DELETE FROM sale_campaigns WHERE id = ? AND seller_id = ?', [$id, $sellerId]);
        return null;
    }
    if (in_array($phase, ['ended', 'cancelled', 'blocked'], true)) return 'This sale is over.';
    if ($action === 'pause') {
        if ($phase === 'paused') return null;
        db()->exec("UPDATE sale_campaigns SET state = 'paused', updated_at = ? WHERE id = ?", [now_db(), $id]);
    } elseif ($action === 'resume') {
        if ($phase !== 'paused') return null;
        $clash = sale_conflicts($sellerId, $c, sale_eligible($sellerId), $id);
        if ($clash) return '"' . $clash[0]['name'] . '" now covers ' . $clash[0]['products'][0] . ' on those days.';
        db()->exec("UPDATE sale_campaigns SET state = 'active', updated_at = ? WHERE id = ?", [now_db(), $id]);
    } elseif ($action === 'cancel') {
        db()->exec("UPDATE sale_campaigns SET state = 'cancelled', updated_at = ? WHERE id = ?", [now_db(), $id]);
    } else {
        return 'Unknown action.';
    }
    sale_touch($sellerId, $c);
    return null;
}

// ---------------------------------------------------------------- results

/** What the sale's products earned during its days, and the same number of days just before it. */
function sale_results(array $c): ?array
{
    if (!in_array($c['phase'], ['live', 'ended', 'paused', 'cancelled'], true) || $c['starts_on'] > sale_today()) return null;
    $tz = dash_tz();
    $from = new DateTimeImmutable($c['starts_on'], $tz);
    $to = (new DateTimeImmutable(min($c['ends_on'], sale_today()), $tz))->modify('+1 day');
    $days = max(1, (int) $from->diff($to)->days);
    $prevFrom = $from->modify('-' . $days . ' days');
    $filter = ''; $args = [];
    if ($c['scope'] === 'selected' || $c['scope'] === 'except') {
        $ids = $c['product_list'] ?: ['none'];
        $filter = $c['scope'] === 'selected' ? ' AND i.product_id IN (' . db()->placeholders($ids) . ')' : ' AND (i.product_id IS NULL OR i.product_id NOT IN (' . db()->placeholders($ids) . '))';
        $args = $ids;
    }
    $q = function (DateTimeImmutable $a, DateTimeImmutable $b) use ($c, $filter, $args): array {
        $r = db()->one("SELECT COUNT(DISTINCT o.id) AS orders, COALESCE(SUM(i.quantity), 0) AS units, COALESCE(SUM(i.line_total), 0) AS gross FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.seller_id = ? AND i.status = 'paid' AND o.currency = ? AND o.placed_at >= ? AND o.placed_at < ?" . $filter,
            array_merge([$c['seller_id'], $c['currency'], dash_utc($a), dash_utc($b)], $args)) ?: [];
        return ['orders' => (int) ($r['orders'] ?? 0), 'units' => (int) ($r['units'] ?? 0), 'gross' => round2((float) ($r['gross'] ?? 0))];
    };
    return ['now' => $q($from, $to), 'before' => $q($prevFrom, $from), 'days' => $days];
}

// ---------------------------------------------------------------- DDLX marketplace sales

function sale_market_list(): array
{
    $rows = array_map('sale_hydrate', db()->all('SELECT * FROM sale_campaigns WHERE seller_id IS NULL ORDER BY starts_on DESC LIMIT 100'));
    foreach ($rows as &$r) {
        $r['joined'] = (int) db()->val("SELECT COUNT(*) FROM sale_campaigns WHERE parent_id = ? AND state <> 'cancelled'", [$r['id']]);
    }
    unset($r);
    return $rows;
}

/** DDLX sales a seller can still join, each with their own entry if they already did. */
function sale_market_open(string $sellerId): array
{
    $out = [];
    foreach (db()->all("SELECT * FROM sale_campaigns WHERE seller_id IS NULL AND state = 'active' AND ends_on >= ? ORDER BY starts_on", [sale_today()]) as $r) {
        $r = sale_hydrate($r);
        $r['mine'] = db()->val("SELECT id FROM sale_campaigns WHERE seller_id = ? AND parent_id = ? AND state <> 'cancelled'", [$sellerId, $r['id']]) ?: null;
        $out[] = $r;
    }
    return $out;
}

function sale_market_save(array $admin, array $in): ?string
{
    $id = (string) ($in['id'] ?? '');
    $existing = $id !== '' ? db()->one('SELECT * FROM sale_campaigns WHERE id = ? AND seller_id IS NULL', [$id]) : null;
    if ($id !== '' && !$existing) return 'Sale not found.';
    $name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')));
    $note = trim((string) ($in['note'] ?? ''));
    $starts = trim((string) ($in['starts_on'] ?? '')); $ends = trim((string) ($in['ends_on'] ?? ''));
    $min = trim((string) ($in['min_discount'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) return 'Give the sale a name, up to 60 characters.';
    if (mb_strlen($note) > 300) return 'The note is too long.';
    if (!sale_valid_date($starts) || !sale_valid_date($ends)) return 'Choose a start and an end date.';
    if ($ends < $starts) return 'The sale must end on or after the day it starts.';
    if ($ends < sale_today()) return 'The end date has already passed.';
    if (!$existing && $starts < sale_today()) return 'Start the sale today or later.';
    if ($min !== '' && (!is_numeric($min) || (float) $min < 1 || (float) $min > 99)) return 'The smallest discount can be from 1 to 99, or left empty.';
    $now = now_db();
    $row = ['name' => $name, 'note' => $note, 'starts_on' => $starts, 'ends_on' => $ends, 'min_discount' => $min === '' ? null : round((float) $min, 2), 'updated_at' => $now];
    if ($existing) {
        $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($row)));
        db()->exec("UPDATE sale_campaigns SET $sets WHERE id = ?", array_merge(array_values($row), [$id]));
        // Sellers who joined follow the new dates.
        $kids = db()->all("SELECT * FROM sale_campaigns WHERE parent_id = ? AND state <> 'cancelled'", [$id]);
        db()->exec('UPDATE sale_campaigns SET name = ?, starts_on = ?, ends_on = ?, reminded_start = 0, notified_end = 0, updated_at = ? WHERE parent_id = ?', [$name, $starts, $ends, $now, $id]);
        sale_parents(true);
        foreach ($kids as $k) sale_touch((string) $k['seller_id'], sale_hydrate($k));
        audit($admin, 'sale', 'Updated the marketplace sale ' . $name, $id);
        return null;
    }
    $id = uuid();
    db()->insert('sale_campaigns', $row + ['id' => $id, 'seller_id' => null, 'parent_id' => null, 'rule' => 'percent', 'value' => 0, 'currency' => 'NGN', 'scope' => 'all', 'product_ids' => null, 'prices' => null, 'state' => 'active', 'admin_blocked' => 0, 'reminded_start' => 0, 'notified_end' => 0, 'created_at' => $now]);
    sale_parents(true);
    audit($admin, 'sale', 'Created the marketplace sale ' . $name, $id);
    foreach (db()->all("SELECT user_id FROM seller_verifications WHERE status = 'approved'") as $s) {
        notify((string) $s['user_id'], 'sale', $name . ' is open for sellers', sale_nice_date($starts) . ' to ' . sale_nice_date($ends) . ($note !== '' ? '. ' . $note : '.') . ' Join from Promotions.', '/seller?tab=promotions', false);
    }
    return null;
}

function sale_market_cancel(array $admin, string $id): ?string
{
    $m = db()->one('SELECT * FROM sale_campaigns WHERE id = ? AND seller_id IS NULL', [$id]);
    if (!$m) return 'Sale not found.';
    $kids = db()->all("SELECT * FROM sale_campaigns WHERE parent_id = ? AND state <> 'cancelled'", [$id]);
    db()->exec("UPDATE sale_campaigns SET state = 'cancelled', updated_at = ? WHERE id = ?", [now_db(), $id]);
    sale_parents(true);
    foreach ($kids as $k) { sale_touch((string) $k['seller_id'], sale_hydrate($k)); notify((string) $k['seller_id'], 'sale', $m['name'] . ' was cancelled', 'Your discounts for it have been taken off.', '/seller?tab=promotions', false); }
    audit($admin, 'sale', 'Cancelled the marketplace sale ' . $m['name'], $id);
    return null;
}

function sale_admin_block(array $admin, string $id, bool $block): ?string
{
    $c = db()->one('SELECT * FROM sale_campaigns WHERE id = ? AND seller_id IS NOT NULL', [$id]);
    if (!$c) return 'Sale not found.';
    db()->exec('UPDATE sale_campaigns SET admin_blocked = ?, updated_at = ? WHERE id = ?', [$block ? 1 : 0, now_db(), $id]);
    sale_touch((string) $c['seller_id'], sale_hydrate($c));
    if ($block) notify((string) $c['seller_id'], 'sale', 'Your sale ' . $c['name'] . ' was switched off', 'Please contact the DDLX team if you have questions.', '/seller?tab=promotions', false);
    audit($admin, 'sale', ($block ? 'Switched off' : 'Switched back on') . ' the sale ' . $c['name'], $id);
    return null;
}

/** Every seller's sales for the admin, newest first, with the store name and how many products each covers. */
function sale_admin_list(): array
{
    $rows = array_map('sale_hydrate', db()->all('SELECT * FROM sale_campaigns WHERE seller_id IS NOT NULL ORDER BY created_at DESC LIMIT 200'));
    $emails = [];
    foreach (db()->all('SELECT id, email FROM users') as $u) $emails[$u['id']] = $u['email'];
    $eligible = [];
    foreach ($rows as &$r) {
        $sid = (string) $r['seller_id'];
        $r['store'] = store_label($sid, $emails);
        $eligible[$sid] ??= sale_eligible($sid);
        $r['covers'] = count(array_filter(sale_rows($r, $eligible[$sid]), fn ($x) => $x['applied']));
    }
    unset($r);
    return $rows;
}

// ---------------------------------------------------------------- reminders

/** Hourly, on whatever request comes first: a heads-up the day before a sale starts, and a note when it has ended. */
function sales_sweep_if_due(): void
{
    try {
        $last = (string) setting('sales_last_sweep', '');
        if ($last !== '' && strtotime($last . ' UTC') > time() - 3600) return;
        set_setting('sales_last_sweep', now_db());
        $today = sale_today();
        $tomorrow = (new DateTimeImmutable('tomorrow', dash_tz()))->format('Y-m-d');
        foreach (db()->all("SELECT * FROM sale_campaigns WHERE seller_id IS NOT NULL AND state = 'active' AND admin_blocked = 0 AND reminded_start = 0 AND starts_on <= ? AND ends_on >= ?", [$tomorrow, $today]) as $r) {
            $c = sale_hydrate($r);
            if (!in_array($c['phase'], ['scheduled', 'live'], true)) continue;
            db()->exec('UPDATE sale_campaigns SET reminded_start = 1 WHERE id = ?', [$c['id']]);
            $low = 0;
            foreach (sale_eligible((string) $c['seller_id']) as $p) if ($p['out'] && sale_in_scope($c, $p['id'])) $low++;
            notify((string) $c['seller_id'], 'sale', $c['phase'] === 'live' ? $c['name'] . ' is live' : $c['name'] . ' starts tomorrow', ($low > 0 ? $low . ' product' . ($low === 1 ? ' is' : 's are') . ' out of stock. Restock to avoid missing sales.' : 'Your discounts switch on at midnight.'), '/sales/view?id=' . $c['id'], false);
        }
        foreach (db()->all("SELECT * FROM sale_campaigns WHERE seller_id IS NOT NULL AND state IN ('active', 'paused') AND notified_end = 0 AND ends_on < ?", [$today]) as $r) {
            db()->exec('UPDATE sale_campaigns SET notified_end = 1 WHERE id = ?', [$r['id']]);
            notify((string) $r['seller_id'], 'sale', $r['name'] . ' has ended', 'See how it did.', '/sales/view?id=' . $r['id'], false);
        }
    } catch (Throwable $e) {
        log_error('sales sweep: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------- wording

function sale_describe(array $c): string
{
    return match ($c['rule']) {
        'percent' => rtrim(rtrim(number_format($c['value'], 2), '0'), '.') . '% off',
        'amount' => money($c['value'], $c['currency']) . ' off each',
        'fixed' => 'All at ' . money($c['value'], $c['currency']),
        default => 'Your own prices',
    };
}

function sale_dates(array $c): string
{
    return $c['starts_on'] === $c['ends_on'] ? sale_nice_date($c['starts_on']) : sale_nice_date($c['starts_on']) . ' to ' . sale_nice_date($c['ends_on']);
}

/** "₦8,000" or "₦8,000 – ₦12,000". */
function sale_money_range(?array $r, string $cur): string
{
    if (!$r) return '–';
    return $r[0] === $r[1] ? dash_money($r[0], $cur) : dash_money($r[0], $cur) . ' – ' . dash_money($r[1], $cur);
}

function sale_off_range(?array $r): string
{
    if (!$r) return '';
    return $r[0] === $r[1] ? $r[0] . '% off' : $r[0] . '–' . $r[1] . '% off';
}
