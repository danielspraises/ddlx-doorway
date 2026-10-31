<?php
declare(strict_types=1);

// Matching a Vendor Hub seller to a seller that already exists on the marketplace (Dokan), then bringing over
// their products and past orders. The admin drives it; every step is safe to repeat.

const IMPORT_PRODUCT_BATCH = 5;
const IMPORT_ORDER_BATCH = 15;
const IMPORT_MAX_IMAGES = 8;

function is_verified_seller(string $id): bool
{
    return (bool) db()->val("SELECT 1 FROM seller_verifications WHERE user_id = ? AND status = 'approved'", [$id]);
}

function seller_link(string $sellerId): ?array
{
    $r = db()->one('SELECT marketplace_user_id AS uid, marketplace_name AS name, marketplace_linked_at AS linked_at FROM users WHERE id = ?', [$sellerId]);
    return $r && $r['uid'] !== null ? ['uid' => (int) $r['uid'], 'name' => (string) $r['name'], 'linked_at' => $r['linked_at']] : null;
}

/** seller_id => ['uid','name'] for the admin's Sellers table. */
function seller_links_map(): array
{
    $out = [];
    foreach (db()->all('SELECT id, marketplace_user_id, marketplace_name FROM users WHERE marketplace_user_id IS NOT NULL') as $r) $out[$r['id']] = ['uid' => (int) $r['marketplace_user_id'], 'name' => (string) $r['marketplace_name']];
    return $out;
}

function link_counts(string $sellerId): array
{
    $db = db();
    return [
        'products' => (int) $db->val('SELECT COUNT(*) FROM products WHERE seller_id = ? AND marketplace_product_id IS NOT NULL', [$sellerId]),
        'orders' => (int) $db->val("SELECT COUNT(DISTINCT i.order_id) FROM order_items i JOIN order_item_log l ON l.order_item_id = i.id WHERE i.seller_id = ? AND l.note = 'Imported from the marketplace'", [$sellerId]),
    ];
}

/** Marketplace sellers (optionally filtered), or null when the marketplace cannot be reached. */
function marketplace_vendors(string $search = '', int $page = 1): ?array
{
    $d = marketplace_call('/vendors?search=' . rawurlencode($search) . '&page=' . max(1, $page), 'GET', null, 20);
    if (!$d || !isset($d['vendors']) || !is_array($d['vendors'])) return null;
    $linked = [];
    foreach (db()->all('SELECT marketplace_user_id, email FROM users WHERE marketplace_user_id IS NOT NULL') as $r) $linked[(int) $r['marketplace_user_id']] = $r['email'];
    foreach ($d['vendors'] as &$v) {
        $v['id'] = (int) ($v['id'] ?? 0);
        $v['email'] = (string) ($v['email'] ?? '');
        $v['name'] = (string) ($v['name'] ?? '');
        $v['linked_here'] = $linked[$v['id']] ?? null;
    }
    return array_values(array_filter($d['vendors'], fn ($v) => $v['id'] > 0));
}

/** Puts the most likely match first: same email, then same store name, then a partial match. */
function suggest_matches(array $vendors, string $email, string $store): array
{
    $norm = fn (string $s) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));
    $e = mb_strtolower(trim($email));
    $n = $norm($store);
    foreach ($vendors as &$v) {
        $score = 0;
        if ($e !== '' && mb_strtolower($v['email']) === $e) { $score = 3; $v['why'] = 'Same email'; }
        elseif ($n !== '' && $norm($v['name']) === $n) { $score = 2; $v['why'] = 'Same store name'; }
        elseif ($n !== '' && ($norm($v['name']) !== '') && (str_contains($norm($v['name']), $n) || str_contains($n, $norm($v['name'])))) { $score = 1; $v['why'] = 'Similar store name'; }
        $v['score'] = $score;
    }
    unset($v);
    usort($vendors, fn ($a, $b) => $b['score'] <=> $a['score']);
    return $vendors;
}

function link_seller(array $admin, string $sellerId, int $uid, string $name): ?string
{
    if (!is_verified_seller($sellerId)) return 'Only verified sellers can be linked.';
    if ($uid < 1) return 'Choose a marketplace seller.';
    $db = db();
    if (seller_link($sellerId)) return 'This seller is already linked.';
    $other = $db->val('SELECT email FROM users WHERE marketplace_user_id = ?', [$uid]);
    if ($other) return 'That marketplace seller is already linked to ' . $other . '.';
    $r = marketplace_call('/link', 'POST', ['user_id' => $uid, 'seller_id' => $sellerId], 20);
    if (!$r || empty($r['ok'])) return is_array($r) && !empty($r['message']) ? (string) $r['message'] : 'The marketplace did not accept the link. Check the connection under Settings.';
    $db->exec('UPDATE users SET marketplace_user_id = ?, marketplace_name = ?, marketplace_linked_at = ? WHERE id = ?', [$uid, str_limit($name, 200), now_db(), $sellerId]);
    audit($admin, 'seller.link', 'Linked seller ' . $sellerId . ' to marketplace seller #' . $uid . ' (' . $name . ')', $sellerId);
    return null;
}

/** Undo a link, only while nothing has been imported yet. */
function unlink_seller(array $admin, string $sellerId): ?string
{
    $link = seller_link($sellerId);
    if (!$link) return 'This seller is not linked.';
    $c = link_counts($sellerId);
    if ($c['products'] > 0 || $c['orders'] > 0) return 'Products or orders were already imported, so this link can no longer be removed.';
    $r = marketplace_call('/unlink', 'POST', ['user_id' => $link['uid']], 20);
    if (!$r || empty($r['ok'])) return 'The marketplace did not accept the change. Try again.';
    db()->exec('UPDATE users SET marketplace_user_id = NULL, marketplace_name = NULL, marketplace_linked_at = NULL WHERE id = ?', [$sellerId]);
    audit($admin, 'seller.unlink', 'Unlinked seller ' . $sellerId . ' from marketplace seller #' . $link['uid'], $sellerId);
    return null;
}

// ---------------------------------------------------------------- products

/** Downloads one image and stores it; returns the stored path or null. */
function import_fetch_image(string $url, string $sellerId, string $productId): ?string
{
    if (!preg_match('#^https?://#i', $url)) return null;
    $bytes = null;
    if (!empty($GLOBALS['vh_fetch_stub'])) $bytes = ($GLOBALS['vh_fetch_stub'])($url);
    elseif (!defined('VH_TESTING') && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_MAXFILESIZE => 12 * 1024 * 1024, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $bytes = ($res !== false && $code === 200) ? (string) $res : null;
    }
    if (!is_string($bytes) || $bytes === '') return null;
    $mime = Storage::sniff($bytes);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime ?? ''] ?? null;
    if (!$ext) return null;
    $path = $sellerId . '/' . $productId . '/' . uuid() . '.' . $ext;
    Storage::save('products', $path, Storage::cleanImage($bytes, $mime, 2400));
    return $path;
}

function import_category(array $path): array
{
    $all = categories_all();
    $parent = null;
    $found = null;
    foreach ($path as $name) {
        $hit = null;
        foreach ($all as $c) if ($c['parent_id'] === $parent && mb_strtolower($c['name']) === mb_strtolower(trim((string) $name))) { $hit = $c; break; }
        if (!$hit) break;
        $found = $hit['id'];
        $parent = $hit['id'];
    }
    return [$found, str_limit((string) (end($path) ?: ''), 80)];
}

function import_unique_slug(string $name): string
{
    $base = slugify($name);
    $slug = $base;
    for ($i = 2; db()->val('SELECT 1 FROM products WHERE slug = ?', [$slug]); $i++) $slug = substr($base, 0, 55) . '-' . $i;
    return $slug;
}

function import_sku_taken(string $sku): bool
{
    return (bool) (db()->val('SELECT 1 FROM products WHERE LOWER(sku) = LOWER(?)', [$sku]) ?: db()->val('SELECT 1 FROM product_variations WHERE LOWER(sku) = LOWER(?)', [$sku]));
}

/** Creates one Vendor Hub product from a marketplace product. Returns [vhId|null, note, variationMap]. */
function import_one_product(string $sellerId, array $m): array
{
    $db = db();
    $wcId = (int) ($m['id'] ?? 0);
    $name = trim((string) ($m['name'] ?? ''));
    if ($wcId < 1 || $name === '') return [null, 'Skipped: no name.', []];
    if (!in_array($m['type'] ?? '', ['simple', 'variable'], true)) return [null, $name . ': product type "' . ($m['type'] ?? '?') . '" is not supported, left on the marketplace.', []];

    $existing = $db->one('SELECT id FROM products WHERE marketplace_product_id = ?', [$wcId]);
    if ($existing) { // imported earlier but the marketplace was not told yet: just tell it again
        $map = [];
        foreach ($db->all('SELECT id, marketplace_variation_id FROM product_variations WHERE product_id = ? AND marketplace_variation_id IS NOT NULL', [$existing['id']]) as $v) $map[] = ['wc_id' => (int) $v['marketplace_variation_id'], 'vh_id' => $v['id']];
        return [$existing['id'], null, $map];
    }

    $variable = $m['type'] === 'variable';
    $sku = trim((string) ($m['sku'] ?? '')) ?: 'WC-' . $wcId;
    if (import_sku_taken($sku)) return [null, $name . ': the SKU "' . $sku . '" is already used in Vendor Hub. Change one of them and import again.', []];
    $currency = in_array($m['currency'] ?? '', CURRENCIES, true) ? $m['currency'] : 'NGN';
    $price = isset($m['regular_price']) && is_numeric($m['regular_price']) ? round((float) $m['regular_price'], 2) : null;
    $sale = isset($m['sale_price']) && is_numeric($m['sale_price']) && (float) $m['sale_price'] > 0 && $price && (float) $m['sale_price'] < $price ? round((float) $m['sale_price'], 2) : null;
    $attrs = [];
    foreach ((array) ($m['attributes'] ?? []) as $a) {
        $vals = array_values(array_filter(array_map(fn ($x) => trim((string) $x), (array) ($a['values'] ?? [])), fn ($x) => $x !== ''));
        if (trim((string) ($a['name'] ?? '')) !== '' && $vals) $attrs[] = ['name' => str_limit(trim((string) $a['name']), 60), 'values' => $vals];
    }
    $vars = [];
    if ($variable) {
        $n = 0;
        foreach ((array) ($m['variations'] ?? []) as $v) {
            $n++;
            $vsku = trim((string) ($v['sku'] ?? '')) ?: $sku . '-' . $n;
            if (import_sku_taken($vsku) || $vsku === $sku) return [null, $name . ': the variation SKU "' . $vsku . '" is already used in Vendor Hub.', []];
            $opts = [];
            foreach ((array) ($v['options'] ?? []) as $k => $val) $opts[trim((string) $k)] = trim((string) $val);
            $vp = isset($v['regular_price']) && is_numeric($v['regular_price']) ? round((float) $v['regular_price'], 2) : null;
            $vars[] = ['wc' => (int) ($v['id'] ?? 0), 'sku' => $vsku, 'options' => $opts, 'regular_price' => $vp, 'sale_price' => isset($v['sale_price']) && is_numeric($v['sale_price']) && (float) $v['sale_price'] > 0 && $vp && (float) $v['sale_price'] < $vp ? round((float) $v['sale_price'], 2) : null,
                'stock' => max(0, (int) ($v['stock'] ?? 0)), 'image' => $v['image'] ?? null, 'enabled' => ($v['enabled'] ?? true) ? 1 : 0, 'position' => $n];
        }
        if (!$vars) return [null, $name . ': a variable product with no variations, left on the marketplace.', []];
    }

    [$catId, $catName] = import_category((array) ($m['category_path'] ?? []));
    $id = uuid();
    $live = ($m['status'] ?? '') === 'publish' && ($variable || ($price !== null && $price > 0));
    $images = array_slice(array_values(array_filter((array) ($m['images'] ?? []), 'is_string')), 0, IMPORT_MAX_IMAGES);
    $paths = [];
    foreach ($images as $u) { $p = import_fetch_image($u, $sellerId, $id); if ($p) $paths[] = $p; }
    $featured = $paths[0] ?? null;
    $gallery = array_slice($paths, 1);
    $now = now_db();
    $varIds = [];
    $db->tx(function () use ($db, $id, $sellerId, $m, $name, $sku, $variable, $currency, $price, $sale, $attrs, $vars, $catId, $catName, $featured, $gallery, $live, $now, $wcId, &$varIds) {
        $db->insert('products', ['id' => $id, 'seller_id' => $sellerId, 'name' => str_limit($name, 200), 'slug' => import_unique_slug($name), 'product_type' => $variable ? 'variable' : 'simple', 'sku' => str_limit($sku, 100),
            'brand' => str_limit((string) ($m['brand'] ?? ''), 100), 'category' => $catName, 'category_id' => $catId, 'tags' => jenc(array_slice(array_values(array_map('strval', (array) ($m['tags'] ?? []))), 0, 20)),
            'short_description' => str_limit(trim(strip_tags((string) ($m['short_description'] ?? ''))), 500), 'description' => (string) ($m['description'] ?? ''), 'currency' => $currency,
            'regular_price' => $variable ? null : $price, 'sale_price' => $variable ? null : $sale, 'manage_stock' => !empty($m['manage_stock']) ? 1 : 0, 'stock' => max(0, (int) ($m['stock'] ?? 0)),
            'low_stock_threshold' => max(0, (int) ($m['low_stock'] ?? 5)), 'allow_backorders' => !empty($m['backorders']) ? 1 : 0,
            'weight_kg' => isset($m['weight']) && is_numeric($m['weight']) ? (float) $m['weight'] : null, 'length_cm' => isset($m['length']) && is_numeric($m['length']) ? (float) $m['length'] : null,
            'width_cm' => isset($m['width']) && is_numeric($m['width']) ? (float) $m['width'] : null, 'height_cm' => isset($m['height']) && is_numeric($m['height']) ? (float) $m['height'] : null,
            'attributes' => jenc($attrs), 'featured_image' => $featured, 'gallery' => jenc($gallery), 'status' => $live ? 'approved' : 'draft', 'live' => $live ? 1 : 0,
            'marketplace_product_id' => $wcId, 'created_at' => $now, 'updated_at' => $now]);
        foreach ($vars as $v) {
            $vid = uuid();
            $db->insert('product_variations', ['id' => $vid, 'product_id' => $id, 'sku' => str_limit($v['sku'], 100), 'options' => jenc((object) $v['options']), 'regular_price' => $v['regular_price'], 'sale_price' => $v['sale_price'],
                'stock' => $v['stock'], 'image' => null, 'enabled' => $v['enabled'], 'position' => $v['position'], 'marketplace_variation_id' => $v['wc'] ?: null]);
            if ($v['wc']) $varIds[] = ['wc_id' => $v['wc'], 'vh_id' => $vid];
        }
    });
    if ($live) {
        $p = load_product($id);
        $db->exec('UPDATE products SET published_snapshot = ?, published_at = ?, reviewed_at = ? WHERE id = ?', [jenc(product_snapshot($p)), $now, $now, $id]);
    }
    return [$id, null, $varIds];
}

/** One batch of the seller's existing marketplace products. Returns progress for the screen. */
function import_products_batch(array $admin, string $sellerId, int $after = 0): array
{
    $link = seller_link($sellerId);
    if (!$link) return ['error' => 'Link the seller first.'];
    $d = marketplace_call('/vendor/products?user_id=' . $link['uid'] . '&after=' . $after . '&limit=' . IMPORT_PRODUCT_BATCH, 'GET', null, 60);
    if (!$d || !isset($d['products'])) return ['error' => 'The marketplace did not answer. Try again in a moment.'];
    $created = 0; $notes = []; $adopt = [];
    foreach ($d['products'] as $m) {
        try {
            [$vh, $note, $vars] = import_one_product($sellerId, (array) $m);
        } catch (Throwable $e) {
            log_error('import product: ' . $e->getMessage());
            $notes[] = (string) ($m['name'] ?? 'A product') . ': could not be imported (' . $e->getMessage() . ').';
            continue;
        }
        if ($note) { $notes[] = $note; continue; }
        $created++;
        $adopt[] = ['wc_id' => (int) $m['id'], 'vh_id' => $vh, 'variations' => $vars];
    }
    if ($adopt) {
        $r = marketplace_call('/adopt', 'POST', ['user_id' => $link['uid'], 'items' => $adopt], 60);
        if (!$r || empty($r['ok'])) return ['error' => 'Products were copied but the marketplace could not be told. Run the import again; nothing will be duplicated.', 'created' => $created, 'notes' => $notes];
        db()->exec('UPDATE products SET updated_at = ? WHERE seller_id = ? AND marketplace_product_id IS NOT NULL', [now_db(), $sellerId]);
    }
    $next = isset($d['next']) && $d['next'] ? (int) $d['next'] : null;
    if ($next === null && $created > 0) audit($admin, 'seller.import_products', 'Imported products for seller ' . $sellerId, $sellerId);
    return ['created' => $created, 'notes' => $notes, 'next' => $next, 'remaining' => (int) ($d['remaining'] ?? 0)];
}

// ---------------------------------------------------------------- orders

const IMPORT_FULFILMENT = ['processing' => 'placed', 'ready-shipping' => 'ready_for_shipping', 'shipped' => 'shipped', 'delivered' => 'delivered', 'completed' => 'completed'];

/** Adds one past order's lines for this seller. Finished orders are history (no money); open ones are paid out normally after delivery. */
function import_one_order(string $sellerId, array $o): string
{
    $db = db();
    $mid = trim((string) ($o['id'] ?? ''));
    $status = (string) ($o['status'] ?? '');
    if ($mid === '' || !isset(IMPORT_FULFILMENT[$status]) || !in_array($o['currency'] ?? '', CURRENCIES, true)) return 'skipped';
    $placed = from_iso((string) ($o['placed_at'] ?? ''));
    if (!$placed) return 'skipped';
    $fulfilment = IMPORT_FULFILMENT[$status];
    $history = in_array($status, ['delivered', 'completed'], true);
    $lines = [];
    foreach ((array) ($o['items'] ?? []) as $it) {
        $p = $db->one('SELECT id, name, sku, category_id, product_type FROM products WHERE marketplace_product_id = ? AND seller_id = ?', [(int) ($it['wc_product_id'] ?? 0), $sellerId]);
        if (!$p) continue;
        $vid = null; $sku = $p['sku']; $opts = [];
        if (!empty($it['wc_variation_id'])) {
            $v = $db->one('SELECT id, sku, options FROM product_variations WHERE marketplace_variation_id = ? AND product_id = ?', [(int) $it['wc_variation_id'], $p['id']]);
            if ($v) { $vid = $v['id']; $sku = $v['sku']; $opts = jdec($v['options'], []); }
        }
        $q = (int) ($it['quantity'] ?? 0); $price = $it['unit_price'] ?? null;
        if ($q < 1 || $q > 10000 || !is_numeric($price) || $price < 0) continue;
        $lines[] = ['p' => $p, 'vid' => $vid, 'sku' => $sku, 'opts' => $opts, 'q' => $q, 'price' => (float) $price];
    }
    if (!$lines) return 'skipped';
    return $db->tx(function () use ($db, $sellerId, $o, $mid, $placed, $fulfilment, $history, $lines) {
        $order = $db->one('SELECT id FROM orders WHERE marketplace_order_id = ?' . $db->lock(), [$mid]);
        if ($order) {
            if ($db->val('SELECT 1 FROM order_items WHERE order_id = ? AND seller_id = ?', [$order['id'], $sellerId])) return 'duplicate';
            $orderId = $order['id'];
        } else {
            $orderId = uuid();
            $db->insert('orders', ['id' => $orderId, 'marketplace_order_id' => $mid, 'number' => str_limit((string) ($o['number'] ?? ''), 100), 'currency' => $o['currency'], 'customer_name' => str_limit((string) ($o['customer_name'] ?? ''), 200),
                'ship_city' => str_limit((string) ($o['ship_city'] ?? ''), 100), 'ship_state' => str_limit((string) ($o['ship_state'] ?? ''), 100), 'payment_ref' => str_limit(trim((string) ($o['payment_ref'] ?? '')), 100),
                'payment_method' => str_limit(trim((string) ($o['payment_method'] ?? '')), 40), 'history' => $history ? 1 : 0, 'placed_at' => $placed, 'created_at' => now_db()]);
        }
        foreach ($lines as $l) {
            $rate = commission_rate_for($l['p']['category_id'], $sellerId);
            $line = round2($l['price'] * $l['q']);
            $commission = round2($line * $rate / 100);
            $net = round2($line - $commission);
            $itemId = uuid();
            $db->insert('order_items', ['id' => $itemId, 'order_id' => $orderId, 'seller_id' => $sellerId, 'product_id' => $l['p']['id'], 'variation_id' => $l['vid'], 'name' => $l['p']['name'], 'sku' => $l['sku'],
                'options' => jenc((object) $l['opts']), 'quantity' => $l['q'], 'unit_price' => round2($l['price']), 'line_total' => $line, 'commission_rate' => $rate, 'commission' => $commission, 'net' => $net,
                'status' => 'paid', 'fulfilment' => $fulfilment, 'carrier' => str_limit((string) ($o['carrier'] ?? ''), 80), 'tracking_number' => str_limit((string) ($o['tracking_number'] ?? ''), 100), 'created_at' => $placed]);
            log_item_status($itemId, $fulfilment, 'marketplace', 'Imported from the marketplace');
            if (!$history) {
                $db->insert('wallet_entries', ['id' => uuid(), 'seller_id' => $sellerId, 'kind' => 'sale', 'amount' => $net, 'currency' => $o['currency'], 'order_item_id' => $itemId,
                    'note' => 'Order ' . (($o['number'] ?? '') ?: $mid) . ' (imported)', 'available_at' => NOT_DELIVERED_YET, 'created_at' => now_db()]);
            }
        }
        return 'imported';
    });
}

function import_orders_batch(array $admin, string $sellerId, int $after = 0): array
{
    $link = seller_link($sellerId);
    if (!$link) return ['error' => 'Link the seller first.'];
    if (!link_counts($sellerId)['products']) return ['error' => 'Import the seller\'s products first, so past orders can be matched to them.'];
    $d = marketplace_call('/vendor/orders?user_id=' . $link['uid'] . '&after=' . $after . '&limit=' . IMPORT_ORDER_BATCH, 'GET', null, 60);
    if (!$d || !isset($d['orders'])) return ['error' => 'The marketplace did not answer. Try again in a moment.'];
    $n = ['imported' => 0, 'duplicate' => 0, 'skipped' => 0];
    foreach ($d['orders'] as $o) {
        try { $n[import_one_order($sellerId, (array) $o)]++; } catch (Throwable $e) { log_error('import order: ' . $e->getMessage()); $n['skipped']++; }
    }
    $next = isset($d['next']) && $d['next'] ? (int) $d['next'] : null;
    if ($next === null) {
        try { level_refresh($sellerId, true); } catch (Throwable $e) { log_error('level refresh: ' . $e->getMessage()); }
        audit($admin, 'seller.import_orders', 'Imported past orders for seller ' . $sellerId, $sellerId);
    }
    return $n + ['next' => $next, 'remaining' => (int) ($d['remaining'] ?? 0)];
}
