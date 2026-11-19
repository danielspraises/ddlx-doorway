<?php
declare(strict_types=1);

// Scheduled sales: the seller's pages, and the admin's actions for DDLX sales.

function sale_form_values(array $src, ?array $existing, ?array $parent): array
{
    $f = ['id' => '', 'parent_id' => '', 'name' => '', 'starts_on' => '', 'ends_on' => '', 'rule' => 'percent', 'value' => '', 'currency' => 'NGN', 'scope' => 'all', 'product_list' => [], 'price_map' => []];
    if ($existing) {
        $f = array_merge($f, ['id' => $existing['id'], 'parent_id' => (string) ($existing['parent_id'] ?? ''), 'name' => $existing['name'], 'starts_on' => $existing['starts_on'], 'ends_on' => $existing['ends_on'], 'rule' => $existing['rule'],
            'value' => $existing['rule'] === 'custom' ? '' : rtrim(rtrim(number_format($existing['value'], 2, '.', ''), '0'), '.'), 'currency' => $existing['currency'], 'scope' => $existing['scope'], 'product_list' => $existing['product_list'], 'price_map' => $existing['price_map']]);
    }
    if ($parent) $f = array_merge($f, ['parent_id' => $parent['id'], 'name' => $parent['name'], 'starts_on' => $parent['starts_on'], 'ends_on' => $parent['ends_on']]);
    if ($src) {
        foreach (['name', 'starts_on', 'ends_on', 'rule', 'value', 'currency', 'scope'] as $k) if (isset($src[$k])) $f[$k] = (string) $src[$k];
        $f['product_list'] = array_values(array_filter(array_map('strval', (array) ($src['product_ids'] ?? [])), 'is_uuid'));
        $f['price_map'] = array_filter(array_map('strval', (array) ($src['price'] ?? [])), fn ($v) => $v !== '');
        if ($f['rule'] !== 'custom') $f['price_map'] = [];
    }
    return $f;
}

function sale_page(array $u, array $vars): void
{
    render('sale_edit', $vars + ['title' => 'Sale', 'user' => $u, 'live_refresh' => false]);
}

route('GET', '/sales/edit', function () {
    $u = require_seller();
    $existing = input('id') !== '' ? sale_get(input('id'), $u['id']) : null;
    if (input('id') !== '' && !$existing) abort(404);
    if ($existing && in_array($existing['phase'], ['ended', 'cancelled', 'blocked'], true)) redirect('/sales/view?id=' . $existing['id']);
    $parent = null;
    if (!$existing && input('join') !== '') {
        $parent = sale_parents()[input('join')] ?? null;
        if (!$parent || $parent['state'] !== 'active' || $parent['ends_on'] < sale_today()) redirect('/seller?tab=promotions', 'That DDLX sale is no longer open.', 'error');
        if (db()->val("SELECT id FROM sale_campaigns WHERE seller_id = ? AND parent_id = ? AND state <> 'cancelled'", [$u['id'], $parent['id']])) redirect('/seller?tab=promotions', 'You are already in that sale.', 'error');
    }
    $existingParent = $existing && $existing['parent_id'] ? (sale_parents()[$existing['parent_id']] ?? null) : null;
    sale_page($u, ['f' => sale_form_values([], $existing, $parent), 'existing' => $existing, 'parent' => $parent ?: $existingParent, 'eligible' => sale_eligible($u['id']), 'error' => null, 'rows' => null]);
});

route('POST', '/sales/save', function () {
    $u = require_seller();
    $existing = input('id') !== '' ? sale_get(input('id'), $u['id']) : null;
    if (input('id') !== '' && !$existing) abort(404);
    $in = $_POST;
    $parentId = $existing['parent_id'] ?? (string) ($in['parent_id'] ?? '');
    $parent = $parentId !== '' ? (sale_parents()[$parentId] ?? null) : null;
    if (($in['do'] ?? '') === 'save') {
        $r = sale_save($u['id'], $in, $existing);
        if (!$r['error']) redirect('/sales/view?id=' . $r['c']['id'], $r['c']['starts_on'] <= sale_today() ? 'Your sale is on.' : 'Sale scheduled. It switches on by itself on ' . sale_nice_date($r['c']['starts_on']) . '.');
    } else {
        $r = sale_validate($u['id'], $in, $existing);
        // Previewing is not an error: show the table whenever there is one.
        if ($r['error'] && !$r['rows']) $r['rows'] = null;
    }
    sale_page($u, ['f' => sale_form_values($in, $existing, $parent), 'existing' => $existing, 'parent' => $parent, 'eligible' => $r['eligible'] ?: sale_eligible($u['id']), 'error' => $r['error'], 'rows' => $r['rows'] ?: null,
        'previewed' => ($in['do'] ?? '') === 'preview' && !$r['error']]);
});

route('GET', '/sales/view', function () {
    $u = require_seller();
    $c = sale_get(input('id'), $u['id']);
    if (!$c) abort(404);
    $eligible = sale_eligible($u['id']);
    render('sale_view', ['title' => $c['name'], 'user' => $u, 'live_refresh' => false, 'c' => $c, 'rows' => sale_rows($c, $eligible), 'results' => sale_results($c), 'parent' => $c['parent_id'] ? (sale_parents()[$c['parent_id']] ?? null) : null]);
});

route('POST', '/sales/action', function () {
    $u = require_seller();
    $id = input('id');
    $action = input('action');
    $err = sale_action($u['id'], $id, $action);
    if ($action === 'delete' && !$err) redirect('/seller?tab=promotions', 'Sale deleted.');
    $msg = ['pause' => 'Sale paused. Prices go back to normal.', 'resume' => 'Sale resumed.', 'cancel' => 'Sale ended. Prices go back to normal.'][$action] ?? 'Done.';
    redirect('/sales/view?id=' . $id, $err ?? $msg, $err ? 'error' : 'ok');
});

// ---------------------------------------------------------------- admin

route('POST', '/admin/sales/save', function () {
    $u = require_admin();
    $err = sale_market_save($u, $_POST);
    redirect('/admin?tab=sales' . ($err && input('id') !== '' ? '&edit=' . input('id') : ''), $err ?? 'Sale saved. Sellers can join it from Promotions.', $err ? 'error' : 'ok');
});

route('POST', '/admin/sales/cancel', function () {
    $u = require_admin();
    $err = sale_market_cancel($u, input('id'));
    redirect('/admin?tab=sales', $err ?? 'Sale cancelled. Sellers\' discounts for it are off.', $err ? 'error' : 'ok');
});

route('POST', '/admin/sales/block', function () {
    $u = require_admin();
    $err = sale_admin_block($u, input('id'), input('block') === '1');
    redirect('/admin?tab=sales', $err ?? (input('block') === '1' ? 'Sale switched off.' : 'Sale switched back on.'), $err ? 'error' : 'ok');
});
