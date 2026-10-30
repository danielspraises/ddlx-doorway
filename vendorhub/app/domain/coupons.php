<?php
declare(strict_types=1);

// Seller-funded discount codes. A seller creates a code for their own products; the marketplace picks it up on its
// next sync and customers can use it at checkout. The discount reduces the sale price, so it comes out of the
// seller's earnings (commission is worked out on what the customer paid). The team can switch any code off.

const COUPON_MAX_PER_SELLER = 50;
const COUPON_KINDS = ['percent' => 'Percent off', 'fixed' => 'Amount off each item'];

function coupon_clean_code(string $c): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $c) ?? '');
}

// A date from a "datetime-local" box (Lagos time) as a database UTC value; false when it is not a date.
function local_to_db(string $v): string|false
{
    $iso = local_to_iso($v);
    return $iso ? (from_iso($iso) ?: false) : false;
}

function coupons_for_seller(string $sellerId): array
{
    return array_map('coupon_hydrate', db()->all('SELECT * FROM coupons WHERE seller_id = ? ORDER BY created_at DESC', [$sellerId]));
}

function coupon_hydrate(array $r): array
{
    $r['product_list'] = jdec((string) ($r['product_ids'] ?? ''), []);
    foreach (['amount', 'min_spend', 'discount_total'] as $k) if ($r[$k] !== null) $r[$k] = (float) $r[$k];
    foreach (['active', 'admin_disabled', 'one_per_customer', 'used_count'] as $k) $r[$k] = (int) $r[$k];
    $r['usage_limit'] = $r['usage_limit'] === null ? null : (int) $r['usage_limit'];
    $r['state'] = coupon_state($r);
    return $r;
}

function coupon_state(array $c): string
{
    if ($c['admin_disabled']) return 'blocked';
    if (!$c['active']) return 'paused';
    $now = now_db();
    if ($c['ends_at'] && $c['ends_at'] < $now) return 'expired';
    if ($c['usage_limit'] !== null && $c['used_count'] >= $c['usage_limit']) return 'used_up';
    if ($c['starts_at'] && $c['starts_at'] > $now) return 'scheduled';
    return 'live';
}

const COUPON_STATE_LABEL = ['live' => 'Live', 'scheduled' => 'Starts later', 'paused' => 'Paused', 'expired' => 'Ended', 'used_up' => 'Fully used', 'blocked' => 'Switched off by DDLX'];

function coupon_save(string $sellerId, array $in): array
{
    $id = (string) ($in['id'] ?? '');
    $existing = $id !== '' && is_uuid($id) ? db()->one('SELECT * FROM coupons WHERE id = ? AND seller_id = ?', [$id, $sellerId]) : null;
    if ($id !== '' && !$existing) return ['ok' => false, 'error' => 'Code not found.'];
    $code = $existing ? $existing['code'] : coupon_clean_code((string) ($in['code'] ?? ''));
    if (!$existing && (strlen($code) < 4 || strlen($code) > 20)) return ['ok' => false, 'error' => 'The code must be 4 to 20 letters, numbers, - or _.'];
    $kind = (string) ($in['kind'] ?? '');
    if (!isset(COUPON_KINDS[$kind])) return ['ok' => false, 'error' => 'Choose percent or amount off.'];
    $amount = is_numeric($in['amount'] ?? null) ? round((float) $in['amount'], 2) : 0.0;
    if ($kind === 'percent' && ($amount < 1 || $amount > 70)) return ['ok' => false, 'error' => 'A percent discount can be from 1 to 70.'];
    if ($kind === 'fixed' && ($amount <= 0 || $amount > 100000000)) return ['ok' => false, 'error' => 'Enter the amount off each item.'];
    $currency = strtoupper((string) ($in['currency'] ?? 'NGN'));
    if (!in_array($currency, CURRENCIES, true)) return ['ok' => false, 'error' => 'Choose a supported currency.'];
    $min = trim((string) ($in['min_spend'] ?? '')) === '' ? null : (is_numeric($in['min_spend']) && (float) $in['min_spend'] >= 0 ? round((float) $in['min_spend'], 2) : false);
    if ($min === false) return ['ok' => false, 'error' => 'Minimum spend must be a number.'];
    $limit = trim((string) ($in['usage_limit'] ?? '')) === '' ? null : (ctype_digit((string) $in['usage_limit']) && (int) $in['usage_limit'] > 0 && (int) $in['usage_limit'] <= 1000000 ? (int) $in['usage_limit'] : false);
    if ($limit === false) return ['ok' => false, 'error' => 'Uses allowed must be a whole number above zero, or left empty.'];
    $starts = trim((string) ($in['starts_at'] ?? '')) === '' ? null : local_to_db((string) $in['starts_at']);
    $ends = trim((string) ($in['ends_at'] ?? '')) === '' ? null : local_to_db((string) $in['ends_at']);
    if ($starts === false || $ends === false) return ['ok' => false, 'error' => 'Check the dates.'];
    if ($starts && $ends && $ends <= $starts) return ['ok' => false, 'error' => 'The end date must be after the start date.'];
    $scope = ($in['scope'] ?? 'all') === 'products' ? 'products' : 'all';
    $pids = [];
    if ($scope === 'products') {
        $want = array_values(array_filter(array_map('strval', (array) ($in['product_ids'] ?? [])), 'is_uuid'));
        if ($want) $pids = array_column(db()->all('SELECT id FROM products WHERE seller_id = ? AND id IN (' . db()->placeholders($want) . ')', array_merge([$sellerId], $want)), 'id');
        if (!$pids) return ['ok' => false, 'error' => 'Choose at least one product, or apply the code to all your products.'];
    }
    $now = now_db();
    $row = ['kind' => $kind, 'amount' => $amount, 'currency' => $currency, 'scope' => $scope, 'product_ids' => $scope === 'products' ? jenc($pids) : null, 'min_spend' => $min, 'usage_limit' => $limit,
        'one_per_customer' => !empty($in['one_per_customer']) ? 1 : 0, 'starts_at' => $starts, 'ends_at' => $ends, 'updated_at' => $now];
    if ($existing) {
        $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($row)));
        db()->exec("UPDATE coupons SET $sets WHERE id = ?", array_merge(array_values($row), [$id]));
        return ['ok' => true, 'id' => $id];
    }
    if ((int) db()->val('SELECT COUNT(*) FROM coupons WHERE seller_id = ?', [$sellerId]) >= COUPON_MAX_PER_SELLER) return ['ok' => false, 'error' => 'You have reached the limit of ' . COUPON_MAX_PER_SELLER . ' codes. Delete old ones first.'];
    $id = uuid();
    try {
        db()->insert('coupons', $row + ['id' => $id, 'seller_id' => $sellerId, 'code' => $code, 'active' => 1, 'admin_disabled' => 0, 'used_count' => 0, 'discount_total' => 0, 'sync_status' => 'pending', 'sync_note' => '', 'created_at' => $now]);
    } catch (Throwable $e) {
        if (Db::isDuplicate($e)) return ['ok' => false, 'error' => 'That code is already taken. Try another.'];
        throw $e;
    }
    return ['ok' => true, 'id' => $id];
}

function coupon_toggle(string $sellerId, string $id, bool $active): ?string
{
    return db()->exec('UPDATE coupons SET active = ?, updated_at = ? WHERE id = ? AND seller_id = ?', [$active ? 1 : 0, now_db(), $id, $sellerId]) ? null : 'Code not found.';
}

function coupon_delete(string $sellerId, string $id): ?string
{
    // Deleting is a pause the marketplace can see, then the row goes after the next sync. Used codes are kept for the records.
    $c = db()->one('SELECT used_count FROM coupons WHERE id = ? AND seller_id = ?', [$id, $sellerId]);
    if (!$c) return 'Code not found.';
    if ((int) $c['used_count'] > 0) return db()->exec('UPDATE coupons SET active = 0, updated_at = ? WHERE id = ?', [now_db(), $id]) ? null : 'Code not found.';
    db()->exec('UPDATE coupons SET active = 0, admin_disabled = 1, product_ids = NULL, code = ?, updated_at = ? WHERE id = ?', ['DEL-' . substr($id, 0, 8), now_db(), $id]);
    return null;
}

function coupon_admin_set(array $admin, string $id, bool $blocked): ?string
{
    $c = db()->one('SELECT code FROM coupons WHERE id = ?', [$id]);
    if (!$c) return 'Code not found.';
    db()->exec('UPDATE coupons SET admin_disabled = ?, updated_at = ? WHERE id = ?', [$blocked ? 1 : 0, now_db(), $id]);
    audit($admin, 'staff', ($blocked ? 'Switched off' : 'Switched on') . ' discount code ' . $c['code'], $id);
    return null;
}

function coupons_admin_list(): array
{
    $rows = db()->all("SELECT c.*, u.email, a.brand AS brand_json FROM coupons c JOIN users u ON u.id = c.seller_id LEFT JOIN seller_applications a ON a.user_id = u.id WHERE c.code NOT LIKE 'DEL-%' ORDER BY c.created_at DESC LIMIT 300");
    foreach ($rows as &$r) { $r['store'] = trim((string) (jdec((string) $r['brand_json'], [])['store_name'] ?? '')) ?: $r['email']; unset($r['brand_json']); $r = coupon_hydrate($r) + ['store' => $r['store'], 'email' => $r['email']]; }
    return $rows;
}

// What the marketplace pulls. Everything is listed, with "enabled", so the store can switch codes off too.
function coupons_feed(): array
{
    $rows = db()->all("SELECT c.*, a.brand AS brand_json FROM coupons c LEFT JOIN seller_applications a ON a.user_id = c.seller_id ORDER BY c.updated_at DESC LIMIT 2000");
    $out = [];
    foreach ($rows as $r) {
        $store = trim((string) (jdec((string) $r['brand_json'], [])['store_name'] ?? '')) ?: 'DDLX seller';
        unset($r['brand_json']);
        $c = coupon_hydrate($r);
        $out[] = ['id' => $c['id'], 'seller_id' => $c['seller_id'], 'store_name' => $store, 'code' => $c['code'], 'kind' => $c['kind'], 'amount' => $c['amount'], 'currency' => $c['currency'], 'scope' => $c['scope'],
            'product_ids' => $c['product_list'], 'min_spend' => $c['min_spend'], 'usage_limit' => $c['usage_limit'], 'one_per_customer' => (bool) $c['one_per_customer'],
            'starts_at' => iso($c['starts_at']), 'ends_at' => iso($c['ends_at']), 'enabled' => in_array($c['state'], ['live', 'scheduled'], true) && !vacation_on($c['seller_id'])];
    }
    return $out;
}

function vacation_on(string $sellerId): bool
{
    return !empty(store_profile($sellerId)['vacation']);
}

// Called when a paid order carries codes: counts the use and how much the seller gave away.
function coupons_record_use(array $codes, array $discountBySeller): void
{
    foreach (array_unique(array_map('coupon_clean_code', $codes)) as $code) {
        $c = db()->one('SELECT id, seller_id FROM coupons WHERE code = ?', [$code]);
        if (!$c) continue;
        $d = round2((float) ($discountBySeller[$c['seller_id']] ?? 0));
        db()->exec('UPDATE coupons SET used_count = used_count + 1, discount_total = discount_total + ?, updated_at = ? WHERE id = ?', [$d, now_db(), $c['id']]);
    }
}
