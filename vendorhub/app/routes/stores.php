<?php
declare(strict_types=1);

// One account, more than one store. Every extra store is applied for, verified and approved like the first.

function stores_enter(string $ownerId, string $storeId): void
{
    if ($storeId === $ownerId) store_clear(); else store_set($ownerId, $storeId);
    unset($GLOBALS['vh_user_cache']);
}

route('GET', '/stores', function () {
    $u = require_user();
    $ownerId = (string) ($u['storeOf'] ?? $u['id']);
    render('stores', ['title' => 'Your stores', 'user' => $u, 'stores' => stores_of($ownerId), 'current' => $u['id'], 'canAdd' => count(stores_of($ownerId)) < MAX_STORES]);
});

route('POST', '/stores/switch', function () {
    $u = require_user();
    $ownerId = (string) ($u['storeOf'] ?? $u['id']);
    $to = input('id');
    $ok = false;
    foreach (stores_of($ownerId) as $s) if ($s['id'] === $to) $ok = true;
    if (!$ok) redirect('/stores', 'That store is not yours.', 'error');
    stores_enter($ownerId, $to);
    $now = current_user();
    redirect($now ? home_path($now) : '/stores');
});

route('POST', '/stores/new', function () {
    $u = require_user();
    if (!empty($u['viewAs'])) abort(403);
    $r = store_create($u);
    if (!$r['ok']) redirect('/stores', $r['error'], 'error');
    audit($u, 'store', 'Started another store', $r['id']);
    stores_enter($r['owner'], $r['id']);
    redirect('/apply', 'New store started. Tell us about it to continue.');
});
