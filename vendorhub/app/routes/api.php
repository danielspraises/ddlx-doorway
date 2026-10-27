<?php
declare(strict_types=1);

// ---------------------------------------------------------------- live updates (the browser asks every few seconds)

route('GET', '/api/pulse', function () {
    $u = require_user();
    $db = db();
    $since = isset($_GET['since']) && ctype_digit((string) $_GET['since']) ? (int) $_GET['since'] : null;
    $last = (int) $db->val('SELECT COALESCE(MAX(id), 0) FROM events');
    $events = [];
    if ($since !== null && $last > $since) {
        $rows = $db->all('SELECT id, audience, seller_id, type, message FROM events WHERE id > ? ORDER BY id LIMIT 50', [$since]);
        foreach ($rows as $r) {
            $mine = ($r['audience'] === 'seller' && $r['seller_id'] === $u['id']) || ($r['audience'] === 'reviewers' && $u['canReview']) || ($r['audience'] === 'admins' && $u['isAdmin']) || ($r['audience'] === 'orders' && $u['canOrders']);
            if ($mine) $events[] = ['type' => $r['type'], 'message' => $r['message']];
        }
    }
    $p = $u['pending'];
    json_out(['last' => $last, 'events' => $events, 'counts' => ['Applications' => $p['applications'], 'Verifications' => $p['verifications'], 'Products' => $p['products'], 'Product review' => $p['products'], 'Payouts' => $p['payouts'], 'Orders' => $p['orders'], 'Notifications' => $p['notifications']]]);
}, ['api' => true]);

// ---------------------------------------------------------------- marketplace: pull the catalogue

function market_json(mixed $body, int $status = 200): never
{
    json_out($body, $status);
}

function market_check_key(): void
{
    $key = (string) setting('marketplace_api_key', cfg('marketplace_api_key', ''));
    if ($key === '') market_json(['error' => 'The marketplace API key is not set up in Vendor Hub.'], 503);
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!str_starts_with($h, 'Bearer ') || !hash_equals($key, substr($h, 7))) market_json(['error' => 'Invalid API key.'], 401);
}

// Orders are signed: HMAC-SHA256 over "<unix timestamp>.<raw body>" with the shared secret.
function market_check_signature(string $raw): void
{
    $secret = (string) setting('marketplace_webhook_secret', cfg('marketplace_webhook_secret', ''));
    if ($secret === '') market_json(['error' => 'The webhook secret is not set up in Vendor Hub.'], 503);
    $ts = (string) ($_SERVER['HTTP_X_VEROLA_TIMESTAMP'] ?? '');
    $sig = preg_replace('/^sha256=/', '', (string) ($_SERVER['HTTP_X_VEROLA_SIGNATURE'] ?? ''));
    if (!ctype_digit($ts) || abs(time() - (int) $ts) > 300) market_json(['error' => 'Missing or expired timestamp.'], 401);
    if (!hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $secret), (string) $sig)) market_json(['error' => 'Invalid signature.'], 401);
}

function media_url(?string $path): ?string
{
    return $path ? base_url() . '/media?p=' . rawurlencode($path) : null;
}

// Approved products, grouped under their seller. Stock is live; everything else is the approved version.
// Without updated_since only live products are returned. With it, every product changed since then is returned,
// including ones that went offline (live = false) so the marketplace can hide them.
function marketplace_catalogue(?string $sinceDb, int $limit): array
{
    $db = db();
    $sql = 'SELECT p.id, p.seller_id, p.live, p.published_snapshot, p.stock, p.updated_at, p.published_at FROM products p WHERE ';
    $args = [];
    if ($sinceDb) {
        $sql .= 'p.published_snapshot IS NOT NULL AND p.updated_at >= ?';
        $args[] = $sinceDb;
    } else {
        $sql .= 'p.live = 1';
    }
    $rows = $db->all($sql . ' ORDER BY p.updated_at, p.id LIMIT ' . (int) $limit, $args);
    $apps = [];
    $profiles = [];
    $products = [];
    foreach ($rows as $r) {
        $s = jdec($r['published_snapshot'], []);
        if (!$s) continue;
        $sid = $r['seller_id'];
        if (!isset($apps[$sid])) {
            $a = $db->one('SELECT applicant_email, brand, business FROM seller_applications WHERE user_id = ?', [$sid]) ?: [];
            $apps[$sid] = ['brand' => jdec($a['brand'] ?? '', []), 'business' => jdec($a['business'] ?? '', []), 'email' => $a['applicant_email'] ?? null];
            $profiles[$sid] = store_profile($sid);
        }
        $brand = $apps[$sid]['brand'];
        $profile = $profiles[$sid];
        $storeName = trim((string) ($brand['store_name'] ?? '')) ?: 'DDLX seller';
        $varStock = [];
        foreach ($db->all('SELECT id, stock FROM product_variations WHERE product_id = ?', [$r['id']]) as $v) $varStock[$v['id']] = (int) $v['stock'];
        $pf = fn (string $k) => (string) ($profile[$k] ?? '');
        $products[] = [
            'id' => $r['id'], 'live' => (int) $r['live'] === 1 && !$profile['vacation'], 'updated_at' => iso($r['updated_at']), 'published_at' => iso($r['published_at']),
            'seller' => [
                'id' => $sid, 'store_name' => $storeName, 'store_slug' => slugify($storeName) . '-' . substr($sid, 0, 6), 'email' => $apps[$sid]['email'],
                'city' => $apps[$sid]['business']['city'] ?? null, 'state' => $apps[$sid]['business']['state'] ?? null,
                'profile' => ['tagline' => $pf('tagline'), 'description' => $pf('description'), 'phone' => $pf('phone'), 'support_email' => $pf('support_email'), 'return_policy' => $pf('return_policy'),
                    'shipping_policy' => $pf('shipping_policy'), 'logo' => media_url($profile['logo']), 'banner' => media_url($profile['banner']), 'vacation' => (bool) $profile['vacation'], 'vacation_message' => $pf('vacation_message')],
            ],
            'name' => $s['name'], 'slug' => $s['slug'], 'sku' => $s['sku'], 'type' => $s['type'], 'brand' => $s['brand'], 'category' => $s['category'],
            'category_path' => $s['category_path'] ?? ($s['category'] ? [$s['category']] : []), 'tags' => $s['tags'],
            'short_description' => $s['short_description'], 'description' => $s['description'], 'currency' => $s['currency'],
            'regular_price' => $s['regular_price'], 'sale_price' => $s['sale_price'], 'sale_starts_at' => $s['sale_starts_at'], 'sale_ends_at' => $s['sale_ends_at'],
            'manage_stock' => $s['manage_stock'], 'stock' => (int) $r['stock'], 'allow_backorders' => $s['allow_backorders'],
            'weight_kg' => $s['weight_kg'], 'dimensions_cm' => ['length' => $s['length_cm'], 'width' => $s['width_cm'], 'height' => $s['height_cm']],
            'attributes' => $s['attributes'], 'featured_image' => media_url($s['featured_image']), 'gallery' => array_map('media_url', $s['gallery']),
            'variations' => array_map(fn ($v) => ['id' => $v['id'], 'sku' => $v['sku'], 'options' => (object) $v['options'], 'regular_price' => $v['regular_price'], 'sale_price' => $v['sale_price'],
                'sale_starts_at' => $v['sale_starts_at'], 'sale_ends_at' => $v['sale_ends_at'], 'stock' => $varStock[$v['id']] ?? 0, 'image' => media_url($v['image'])], $s['variations']),
        ];
    }
    $last = $rows ? $rows[count($rows) - 1] : null;
    return ['products' => $products, 'has_more' => count($rows) === $limit, 'next_since' => $last ? iso($last['updated_at']) : ($sinceDb ? iso($sinceDb) : null)];
}

route('GET', '/api/marketplace/products', function () {
    market_check_key();
    $since = trim((string) ($_GET['updated_since'] ?? ''));
    $sinceDb = null;
    if ($since !== '') {
        $sinceDb = from_iso($since);
        if (!$sinceDb) market_json(['error' => 'updated_since must be an ISO date.'], 400);
    }
    $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100) ?: 100));
    market_json(marketplace_catalogue($sinceDb, $limit));
}, ['api' => true]);

// ---------------------------------------------------------------- marketplace: sales and refunds (signed)

route('POST', '/api/marketplace/orders', function () {
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 1000000) market_json(['error' => 'Payload too large.'], 413);
    market_check_signature($raw);
    $json = json_decode($raw, true);
    if (is_array($json) && ($json['event'] ?? '') === 'order.status') {
        $st = parse_status_event($json);
        if (is_string($st)) market_json(['error' => 'Invalid order payload.', 'issues' => [$st]], 400);
        apply_marketplace_status($st) ? market_json(['ok' => true]) : market_json(['error' => 'Unknown order.'], 404);
    }
    $event = parse_order_event($json);
    if (is_string($event)) market_json(['error' => 'Invalid order payload.', 'issues' => [$event]], 400);
    try {
        if ($event['event'] === 'order.paid') {
            $r = record_paid_order($event);
            market_json(['ok' => true, 'duplicate' => $r['duplicate']]);
        }
        $r = record_refund($event);
        $r['found'] ? market_json(['ok' => true]) : market_json(['error' => 'Unknown order.'], 404);
    } catch (OrderRejected $e) {
        market_json(['error' => $e->getMessage()], 422);
    }
}, ['api' => true]);

// ---------------------------------------------------------------- public photos for the marketplace

route('GET', '/media', function () {
    $p = (string) ($_GET['p'] ?? '');
    if (preg_match(Storage::storeRe(), $p, $m)) {
        if (!db()->val('SELECT 1 FROM store_profiles WHERE logo = ? OR banner = ?', [$p, $p])) abort(404);
        $bytes = Storage::read('stores', $p);
        if ($bytes === null) abort(404);
        Storage::send($bytes, Storage::MIME[$m[2]] ?? 'application/octet-stream', true);
    }
    if (!preg_match(Storage::productRe(), $p, $m)) abort(404);
    $snap = db()->val('SELECT published_snapshot FROM products WHERE id = ? AND live = 1', [$m[2]]);
    $paths = $snap ? (jdec($snap, [])['image_paths'] ?? []) : [];
    if (!in_array($p, $paths, true)) abort(404);
    $bytes = Storage::read('products', $p);
    if ($bytes === null) abort(404);
    Storage::send($bytes, Storage::MIME[$m[3]] ?? 'application/octet-stream', true);
}, ['api' => true]);
