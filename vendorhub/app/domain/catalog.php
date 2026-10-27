<?php
declare(strict_types=1);

// Categories (a tree up to 4 levels deep) and products (WooCommerce-style, approval-gated).

const MAX_CATEGORY_DEPTH = 4;
const MAX_GALLERY = 8;
const MAX_VARIATIONS = 100;
const PRODUCT_IMAGE_MAX = 5 * 1024 * 1024;
const PRODUCT_STATUS_LABEL = ['draft' => 'Draft', 'pending_review' => 'In review', 'changes_requested' => 'Changes requested', 'rejected' => 'Not approved', 'approved' => 'Approved'];

// ---------------------------------------------------------------- categories

// Every category, keyed by id. The tree is small, so it is read once per request.
function categories_all(bool $fresh = false): array
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (db()->all('SELECT c.id, c.parent_id, c.name, c.position, c.active, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count FROM categories c ORDER BY c.position, c.name') as $r) {
            $r['active'] = (int) $r['active'] === 1;
            $r['product_count'] = (int) $r['product_count'];
            $cache[$r['id']] = $r;
        }
    }
    return $cache;
}

function category_children(?string $parent, ?array $all = null): array
{
    $all ??= categories_all();
    $out = array_values(array_filter($all, fn ($c) => $c['parent_id'] === $parent));
    usort($out, fn ($a, $b) => [(int) $a['position'], strtolower($a['name'])] <=> [(int) $b['position'], strtolower($b['name'])]);
    return $out;
}

// Root-to-category rows for an id.
function category_chain(?string $id, ?array $all = null): array
{
    $all ??= categories_all();
    $chain = [];
    $seen = [];
    while ($id && isset($all[$id]) && !isset($seen[$id])) {
        $seen[$id] = true;
        array_unshift($chain, $all[$id]);
        $id = $all[$id]['parent_id'];
    }
    return $chain;
}

function category_path_names(?string $id): array
{
    return array_map(fn ($c) => $c['name'], category_chain($id));
}

// The whole tree as an indented list (parents first).
function category_flat(?array $all = null): array
{
    $all ??= categories_all();
    $out = [];
    $walk = function (?string $parent, int $depth) use (&$walk, &$out, $all) {
        foreach (category_children($parent, $all) as $c) {
            $out[] = ['category' => $c, 'depth' => $depth];
            $walk($c['id'], $depth + 1);
        }
    };
    $walk(null, 0);
    return $out;
}

function category_descendant_ids(string $id, ?array $all = null): array
{
    $all ??= categories_all();
    $ids = [$id];
    for ($i = 0; $i < count($ids); $i++) {
        foreach ($all as $c) if ($c['parent_id'] === $ids[$i] && !in_array($c['id'], $ids, true)) $ids[] = $c['id'];
    }
    return $ids;
}

function category_height(string $id, ?array $all = null): int
{
    $all ??= categories_all();
    $max = 1;
    foreach (category_children($id, $all) as $c) $max = max($max, 1 + category_height($c['id'], $all));
    return $max;
}

// Admin actions. Each returns null on success or an error message.
function category_create(?string $parent, string $name): ?string
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 80) return 'Enter a name (up to 80 characters).';
    $all = categories_all(true);
    if ($parent !== null) {
        if (!isset($all[$parent])) return 'The parent category no longer exists.';
        if (count(category_chain($parent, $all)) >= MAX_CATEGORY_DEPTH) return 'Categories can go ' . MAX_CATEGORY_DEPTH . ' levels deep at most.';
    }
    if (category_sibling_clash($all, $parent, $name, null)) return 'There is already a category with this name here.';
    $pos = 1 + (int) db()->val('SELECT COALESCE(MAX(position), 0) FROM categories WHERE ' . ($parent === null ? 'parent_id IS NULL' : 'parent_id = ?'), $parent === null ? [] : [$parent]);
    db()->insert('categories', ['id' => uuid(), 'parent_id' => $parent, 'name' => $name, 'position' => $pos, 'active' => 1, 'created_at' => now_db()]);
    categories_all(true);
    return null;
}

function category_sibling_clash(array $all, ?string $parent, string $name, ?string $exceptId): bool
{
    foreach ($all as $c) {
        if ($c['parent_id'] === $parent && $c['id'] !== $exceptId && mb_strtolower($c['name']) === mb_strtolower($name)) return true;
    }
    return false;
}

function category_rename(string $id, string $name): ?string
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 80) return 'Enter a name (up to 80 characters).';
    $all = categories_all(true);
    if (!isset($all[$id])) return 'Category not found.';
    if (category_sibling_clash($all, $all[$id]['parent_id'], $name, $id)) return 'There is already a category with this name here.';
    db()->exec('UPDATE categories SET name = ? WHERE id = ?', [$name, $id]);
    db()->exec('UPDATE products SET category = ? WHERE category_id = ?', [$name, $id]);
    categories_all(true);
    return null;
}

function category_move(string $id, ?string $parent): ?string
{
    $all = categories_all(true);
    if (!isset($all[$id])) return 'Category not found.';
    if ($parent === $id) return 'A category cannot go inside itself.';
    if ($parent !== null) {
        if (!isset($all[$parent])) return 'The new parent no longer exists.';
        if (in_array($parent, category_descendant_ids($id, $all), true)) return 'A category cannot be moved into one of its own sub-categories.';
        if (count(category_chain($parent, $all)) + category_height($id, $all) > MAX_CATEGORY_DEPTH) return 'That would make the tree deeper than ' . MAX_CATEGORY_DEPTH . ' levels.';
    }
    if (category_sibling_clash($all, $parent, $all[$id]['name'], $id)) return 'There is already a category with this name there.';
    $pos = 1 + (int) db()->val('SELECT COALESCE(MAX(position), 0) FROM categories WHERE ' . ($parent === null ? 'parent_id IS NULL' : 'parent_id = ?'), $parent === null ? [] : [$parent]);
    db()->exec('UPDATE categories SET parent_id = ?, position = ? WHERE id = ?', [$parent, $pos, $id]);
    categories_all(true);
    return null;
}

// Hides or shows a category with everything under it. Products keep their category.
function category_set_active(string $id, bool $active): ?string
{
    $all = categories_all(true);
    if (!isset($all[$id])) return 'Category not found.';
    $ids = category_descendant_ids($id, $all);
    db()->exec('UPDATE categories SET active = ? WHERE id IN (' . db()->placeholders($ids) . ')', array_merge([$active ? 1 : 0], $ids));
    categories_all(true);
    return null;
}

function category_delete(string $id): ?string
{
    $all = categories_all(true);
    if (!isset($all[$id])) return 'Category not found.';
    if (category_children($id, $all)) return 'Delete or move its sub-categories first.';
    if ($all[$id]['product_count'] > 0) return 'Products use this category. Move them first, or hide the category instead.';
    db()->exec('DELETE FROM categories WHERE id = ?', [$id]);
    categories_all(true);
    return null;
}

// ---------------------------------------------------------------- product rules

function slug_for(string $name, string $id): string
{
    return slugify($name) . '-' . substr($id, 0, 6);
}

function money_or_null(mixed $v): ?float
{
    if ($v === null) return null;
    $s = trim((string) $v);
    if ($s === '' || !is_numeric($s)) return null;
    return round((float) $s, 2);
}

function num_or_null(mixed $v, int $dec = 3): ?float
{
    $s = trim((string) ($v ?? ''));
    if ($s === '' || !is_numeric($s)) return null;
    return round((float) $s, $dec);
}

function option_key(array $options): string
{
    ksort($options);
    $parts = [];
    foreach ($options as $k => $v) $parts[] = $k . '=' . $v;
    return implode('|', $parts);
}

// All combinations of the attribute values, e.g. Size x Colour.
function combinations(array $attributes): array
{
    $usable = array_values(array_filter($attributes, fn ($a) => trim((string) ($a['name'] ?? '')) !== '' && !empty($a['values'])));
    if (!$usable) return [];
    $acc = [[]];
    foreach ($usable as $attr) {
        $next = [];
        foreach ($acc as $combo) foreach ($attr['values'] as $value) $next[] = $combo + [trim($attr['name']) => $value];
        $acc = $next;
    }
    return $acc;
}

// "Size: S, M, L" per line -> [['name'=>'Size','values'=>['S','M','L']]]
function parse_attributes(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode(':', $line, 2);
        $name = trim($parts[0]);
        $values = [];
        foreach (explode(',', $parts[1] ?? '') as $v) {
            $v = trim($v);
            if ($v !== '') $values[] = $v;
        }
        $out[] = ['name' => $name, 'values' => $values];
    }
    return $out;
}

function attributes_text(array $attributes): string
{
    return implode("\n", array_map(fn ($a) => $a['name'] . ': ' . implode(', ', $a['values']), $attributes));
}

function price_problem(string $label, array $i): ?string
{
    $reg = $i['regular_price'] ?? null;
    $sale = $i['sale_price'] ?? null;
    if ($sale !== null) {
        if ($reg === null) return $label . ': set a regular price before adding a sale price.';
        if ($sale >= $reg) return $label . ': the sale price must be lower than the regular price.';
    }
    if (!empty($i['sale_starts_at']) && !empty($i['sale_ends_at']) && strtotime($i['sale_ends_at']) <= strtotime($i['sale_starts_at'])) return $label . ': the sale must end after it starts.';
    return null;
}

// First problem found, or null when the product can be saved (as a draft).
function validate_product(array $p): ?string
{
    if (trim($p['name']) === '') return 'Product name is required.';
    if (mb_strlen($p['name']) > 200) return 'The product name is too long.';
    if (trim($p['sku']) === '') return 'SKU is required.';
    if ($e = price_problem('Product', $p)) return $e;
    foreach (['regular_price', 'sale_price'] as $k) if (($p[$k] ?? 0) !== null && $p[$k] > 1000000000) return 'That price is too high.';
    if ($p['product_type'] === 'simple') return null;
    $attrs = array_values(array_filter($p['attributes'], fn ($a) => trim($a['name']) !== '' || $a['values']));
    $names = array_map(fn ($a) => mb_strtolower(trim($a['name'])), $attrs);
    foreach ($attrs as $a) if (trim($a['name']) === '') return 'Every attribute needs a name.';
    if (count(array_unique($names)) !== count($names)) return 'Attribute names must be different from each other.';
    foreach ($attrs as $a) {
        $vals = array_map(fn ($v) => mb_strtolower(trim($v)), $a['values']);
        if (!$vals) return $a['name'] . ': add at least one value.';
        if (count(array_unique($vals)) !== count($vals)) return $a['name'] . ': values must be different from each other.';
    }
    if (count($p['variations']) > MAX_VARIATIONS) return 'A product can have up to ' . MAX_VARIATIONS . ' variations.';
    $seenSku = [mb_strtolower(trim($p['sku'])) => true];
    $seenOpts = [];
    foreach ($p['variations'] as $v) {
        $label = 'Variation ' . ($v['sku'] !== '' ? $v['sku'] : '(no SKU)');
        if (trim($v['sku']) === '') return 'Every variation needs a SKU.';
        $low = mb_strtolower(trim($v['sku']));
        if (isset($seenSku[$low])) return 'Variation SKU ' . $v['sku'] . ' is used twice.';
        $seenSku[$low] = true;
        $ok = count($v['options']) === count($attrs);
        foreach ($attrs as $a) if (!in_array($v['options'][trim($a['name'])] ?? '', $a['values'], true)) $ok = false;
        if (!$ok) return $label . ': its options no longer match the attributes. Rebuild the variations.';
        $key = option_key($v['options']);
        if (isset($seenOpts[$key])) return $label . ': another variation has the same options.';
        $seenOpts[$key] = true;
        if ($e = price_problem($label, $v)) return $e;
    }
    return null;
}

// What must be filled in before a product can go to review.
function readiness_problems(array $p): array
{
    $pr = [];
    if (trim($p['name']) === '') $pr[] = 'Add a product name.';
    if (empty($p['category_id'])) $pr[] = 'Choose a category.';
    if (trim($p['description']) === '' && trim($p['short_description']) === '') $pr[] = 'Add a description.';
    if (empty($p['featured_image'])) $pr[] = 'Add a featured image.';
    if (empty($p['weight_kg']) || $p['weight_kg'] <= 0) $pr[] = 'Add the product weight in the Shipping section.';
    if (empty($p['length_cm']) || empty($p['width_cm']) || empty($p['height_cm'])) $pr[] = 'Add the length, width and height in the Shipping section.';
    if ($p['product_type'] === 'simple') {
        if (empty($p['regular_price']) || $p['regular_price'] <= 0) $pr[] = 'Set a regular price above zero.';
    } else {
        $enabled = array_filter($p['variations'], fn ($v) => $v['enabled']);
        if (!array_filter($p['attributes'], fn ($a) => trim($a['name']) !== '' && $a['values'])) $pr[] = 'Add at least one attribute, such as Size.';
        if (!$enabled) $pr[] = 'Add at least one variation.';
        elseif (array_filter($enabled, fn ($v) => empty($v['regular_price']) || $v['regular_price'] <= 0)) $pr[] = 'Every variation needs a regular price above zero.';
    }
    return $pr;
}

// ---------------------------------------------------------------- loading

function hydrate_product(array $r): array
{
    $f = fn ($v) => $v === null ? null : (float) $v;
    $all = categories_all();
    $out = [
        'id' => $r['id'], 'seller_id' => $r['seller_id'], 'name' => $r['name'], 'slug' => $r['slug'], 'product_type' => $r['product_type'], 'sku' => $r['sku'],
        'brand' => $r['brand'], 'category' => $r['category'], 'category_id' => $r['category_id'],
        'category_path' => array_map(fn ($c) => $c['name'], category_chain($r['category_id'], $all)),
        'tags' => jdec($r['tags'], []), 'short_description' => $r['short_description'], 'description' => $r['description'], 'currency' => $r['currency'],
        'regular_price' => $f($r['regular_price']), 'sale_price' => $f($r['sale_price']),
        'sale_starts_at' => iso($r['sale_starts_at']), 'sale_ends_at' => iso($r['sale_ends_at']),
        'manage_stock' => (int) $r['manage_stock'] === 1, 'stock' => (int) $r['stock'], 'low_stock_threshold' => (int) $r['low_stock_threshold'], 'allow_backorders' => (int) $r['allow_backorders'] === 1,
        'weight_kg' => $f($r['weight_kg']), 'length_cm' => $f($r['length_cm']), 'width_cm' => $f($r['width_cm']), 'height_cm' => $f($r['height_cm']),
        'attributes' => jdec($r['attributes'], []), 'featured_image' => $r['featured_image'], 'gallery' => jdec($r['gallery'], []),
        'status' => $r['status'], 'live' => (int) $r['live'] === 1, 'admin_comment' => $r['admin_comment'],
        'submitted_at' => iso($r['submitted_at']), 'reviewed_at' => iso($r['reviewed_at']), 'published_at' => iso($r['published_at']),
        'created_at' => iso($r['created_at']), 'updated_at' => iso($r['updated_at']), 'updated_raw' => $r['updated_at'],
        'has_snapshot' => !empty($r['published_snapshot']),
        'variations' => [],
    ];
    foreach (array_merge(['store_name', 'seller_email'], []) as $k) if (array_key_exists($k, $r)) $out[$k] = $r[$k];
    return $out;
}

function attach_variations(array $products): array
{
    if (!$products) return [];
    $ids = array_column($products, 'id');
    $rows = db()->all('SELECT * FROM product_variations WHERE product_id IN (' . db()->placeholders($ids) . ') ORDER BY position, sku', $ids);
    $by = [];
    foreach ($rows as $v) {
        $by[$v['product_id']][] = [
            'id' => $v['id'], 'sku' => $v['sku'], 'options' => jdec($v['options'], []),
            'regular_price' => $v['regular_price'] === null ? null : (float) $v['regular_price'], 'sale_price' => $v['sale_price'] === null ? null : (float) $v['sale_price'],
            'sale_starts_at' => iso($v['sale_starts_at']), 'sale_ends_at' => iso($v['sale_ends_at']),
            'stock' => (int) $v['stock'], 'image' => $v['image'], 'enabled' => (int) $v['enabled'] === 1, 'position' => (int) $v['position'],
        ];
    }
    foreach ($products as &$p) $p['variations'] = $by[$p['id']] ?? [];
    return $products;
}

function load_product(string $id): ?array
{
    $r = db()->one('SELECT * FROM products WHERE id = ?', [$id]);
    return $r ? attach_variations([hydrate_product($r)])[0] : null;
}

function seller_products(string $sellerId): array
{
    $rows = db()->all('SELECT * FROM products WHERE seller_id = ? ORDER BY updated_at DESC', [$sellerId]);
    return attach_variations(array_map('hydrate_product', $rows));
}

function product_image_paths(array $p): array
{
    $all = array_merge([$p['featured_image']], $p['gallery'], array_map(fn ($v) => $v['image'], $p['variations']));
    return array_values(array_unique(array_filter($all)));
}

function product_snapshot(array $p): array
{
    $vars = array_values(array_filter($p['variations'], fn ($v) => $v['enabled']));
    $shown = $p;
    $shown['variations'] = $vars;
    return [
        'id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'sku' => $p['sku'], 'type' => $p['product_type'], 'brand' => $p['brand'],
        'category' => $p['category'], 'category_path' => $p['category_path'], 'tags' => $p['tags'],
        'short_description' => $p['short_description'], 'description' => $p['description'], 'currency' => $p['currency'],
        'regular_price' => $p['regular_price'], 'sale_price' => $p['sale_price'], 'sale_starts_at' => $p['sale_starts_at'], 'sale_ends_at' => $p['sale_ends_at'],
        'manage_stock' => $p['manage_stock'], 'allow_backorders' => $p['allow_backorders'], 'low_stock_threshold' => $p['low_stock_threshold'],
        'weight_kg' => $p['weight_kg'], 'length_cm' => $p['length_cm'], 'width_cm' => $p['width_cm'], 'height_cm' => $p['height_cm'],
        'attributes' => $p['attributes'], 'featured_image' => $p['featured_image'], 'gallery' => $p['gallery'],
        'variations' => array_map(fn ($v) => ['id' => $v['id'], 'sku' => $v['sku'], 'options' => $v['options'], 'regular_price' => $v['regular_price'], 'sale_price' => $v['sale_price'],
            'sale_starts_at' => $v['sale_starts_at'], 'sale_ends_at' => $v['sale_ends_at'], 'image' => $v['image']], $vars),
        'image_paths' => product_image_paths($shown),
        'approved_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
}

function snapshot_paths(string $productId): array
{
    $s = db()->val('SELECT published_snapshot FROM products WHERE id = ?', [$productId]);
    $d = $s ? jdec($s, []) : [];
    return is_array($d['image_paths'] ?? null) ? $d['image_paths'] : [];
}

// Delete image files that nothing points at any more (the current product and the approved version keep theirs).
function remove_unreferenced(string $productId, array $candidates): void
{
    $candidates = array_values(array_unique($candidates));
    if (!$candidates) return;
    $p = load_product($productId);
    $keep = $p ? array_merge(product_image_paths($p), snapshot_paths($productId)) : [];
    foreach ($candidates as $path) {
        if (!in_array($path, $keep, true) && preg_match(Storage::productRe(), $path)) Storage::remove('products', $path);
    }
}

// ---------------------------------------------------------------- SKUs

function sku_prefix(string $sellerId): string
{
    $a = load_application($sellerId);
    $word = preg_split('/\s+/', trim(preg_replace('/[^A-Z0-9 ]+/', '', strtoupper((string) ($a['brand']['store_name'] ?? '')))))[0] ?? '';
    return strlen($word) >= 2 ? substr($word, 0, 4) : 'DDLX';
}

function local_next_sku(string $prefix): string
{
    $rows = db()->all('SELECT sku FROM products WHERE sku LIKE ? UNION ALL SELECT sku FROM product_variations WHERE sku LIKE ?', [$prefix . '-%', $prefix . '-%']);
    $max = 0;
    foreach ($rows as $r) if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/i', $r['sku'], $m)) $max = max($max, (int) $m[1]);
    return $prefix . '-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

// SKUs must be unique across the whole marketplace store; the connector plugin answers for it. Null = could not ask.
function marketplace_call(string $path, string $method, ?array $body = null): ?array
{
    $url = rtrim((string) setting('marketplace_url', cfg('marketplace_url', '')), '/');
    $key = (string) setting('marketplace_api_key', cfg('marketplace_api_key', ''));
    if ($url === '' || $key === '' || defined('VH_TESTING') && empty($GLOBALS['vh_market_stub'])) return null;
    if (!empty($GLOBALS['vh_market_stub'])) return ($GLOBALS['vh_market_stub'])($path, $method, $body);
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url . '/wp-json/vendor-hub/v1' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code < 200 || $code >= 300) return null;
    $d = json_decode((string) $res, true);
    return is_array($d) ? $d : null;
}

function marketplace_connected(): bool
{
    return rtrim((string) setting('marketplace_url', cfg('marketplace_url', '')), '/') !== '' && (string) setting('marketplace_api_key', cfg('marketplace_api_key', '')) !== '';
}

// Is every SKU free? Checks other sellers here, then the marketplace (only SKUs that are new or changed).
function sku_problem(?string $productId, string $sku, string $type, array $variations): ?string
{
    $items = [['sku' => trim($sku), 'product_id' => $productId, 'variation_id' => null]];
    if ($type === 'variable') foreach ($variations as $v) $items[] = ['sku' => trim($v['sku']), 'product_id' => $productId, 'variation_id' => $v['id'] ?? null];
    $lowers = array_values(array_unique(array_map(fn ($i) => mb_strtolower($i['sku']), $items)));
    $ph = db()->placeholders($lowers);
    $own = $productId ?? '';
    $taken = db()->all("SELECT sku FROM products WHERE LOWER(sku) IN ($ph) AND id <> ? UNION ALL SELECT sku FROM product_variations WHERE LOWER(sku) IN ($ph) AND product_id <> ?", array_merge($lowers, [$own], $lowers, [$own]));
    if ($taken) return 'The SKU ' . $taken[0]['sku'] . ' is already used by another product. Press Generate to get a free one.';
    $known = [];
    if ($productId) {
        foreach (db()->all('SELECT sku FROM products WHERE id = ? UNION ALL SELECT sku FROM product_variations WHERE product_id = ?', [$productId, $productId]) as $r) $known[mb_strtolower($r['sku'])] = true;
    }
    $changed = array_values(array_filter($items, fn ($i) => !isset($known[mb_strtolower($i['sku'])])));
    if ($changed) {
        $r = marketplace_call('/sku/check', 'POST', ['items' => $changed]);
        if (is_array($r['conflicts'] ?? null) && $r['conflicts']) return 'The SKU ' . $r['conflicts'][0] . ' is already used on the marketplace. Press Generate to get a free one.';
    }
    return null;
}

function suggest_sku(string $sellerId): ?string
{
    $prefix = sku_prefix($sellerId);
    for ($i = 0; $i < 5; $i++) {
        $r = marketplace_call('/sku/next?prefix=' . rawurlencode($prefix), 'GET');
        $sku = is_string($r['sku'] ?? null) && $r['sku'] !== '' ? $r['sku'] : local_next_sku($prefix);
        if (!sku_problem(null, $sku, 'simple', [])) return $sku;
    }
    return null;
}

// ---------------------------------------------------------------- saving

function dt_or_null(?string $iso): ?string
{
    return $iso ? from_iso($iso) : null;
}

// Creates or updates a product and its variations. Returns ['ok'=>true,'id'=>..] or ['ok'=>false,'error'=>..].
function save_product(string $sellerId, array $d): array
{
    $db = db();
    if ($e = validate_product($d)) return ['ok' => false, 'error' => $e];
    $id = $d['id'] ?? null;
    $existing = null;
    if ($id) {
        $existing = load_product($id);
        if (!$existing || $existing['seller_id'] !== $sellerId) return ['ok' => false, 'error' => 'Product not found.'];
        if ($existing['status'] === 'pending_review') return ['ok' => false, 'error' => 'This product is in review. Withdraw it from review to edit it.'];
        if (!empty($d['expected']) && $d['expected'] !== $existing['updated_raw']) return ['ok' => false, 'error' => 'This product changed in another window. Reload the page and try again.'];
    }
    if ($e = sku_problem($id, $d['sku'], $d['product_type'], $d['variations'])) return ['ok' => false, 'error' => $e];

    $categoryName = '';
    if (!empty($d['category_id'])) {
        $all = categories_all();
        $c = $all[$d['category_id']] ?? null;
        if (!$c || (!$c['active'] && ($existing['category_id'] ?? null) !== $c['id'])) return ['ok' => false, 'error' => 'Choose a category from the list.'];
        $categoryName = $c['name'];
    } else {
        $d['category_id'] = null;
    }
    $variable = $d['product_type'] === 'variable';
    $tags = array_values(array_unique(array_map('mb_strtolower', $d['tags'])));
    $lowImages = [];
    $now = now_db();

    try {
        $db->tx(function () use ($db, $d, $sellerId, &$id, $categoryName, $tags, $variable, &$lowImages, $now, $existing) {
            $cols = [
                'name' => $d['name'], 'sku' => $d['sku'], 'product_type' => $d['product_type'], 'brand' => $d['brand'], 'category' => $categoryName, 'category_id' => $d['category_id'],
                'tags' => jenc($tags), 'short_description' => $d['short_description'], 'description' => $d['description'], 'currency' => $d['currency'],
                'regular_price' => $d['regular_price'], 'sale_price' => $d['sale_price'], 'sale_starts_at' => dt_or_null($d['sale_starts_at']), 'sale_ends_at' => dt_or_null($d['sale_ends_at']),
                'manage_stock' => $d['manage_stock'] ? 1 : 0, 'stock' => $d['stock'], 'low_stock_threshold' => $d['low_stock_threshold'], 'allow_backorders' => $d['allow_backorders'] ? 1 : 0,
                'weight_kg' => $d['weight_kg'], 'length_cm' => $d['length_cm'], 'width_cm' => $d['width_cm'], 'height_cm' => $d['height_cm'],
                'attributes' => jenc($d['attributes']), 'updated_at' => $now,
            ];
            if ($existing) {
                $set = implode(', ', array_map(fn ($c) => $c . ' = ?', array_keys($cols)));
                $db->exec('UPDATE products SET ' . $set . ", status = CASE WHEN status = 'approved' THEN 'draft' ELSE status END WHERE id = ? AND seller_id = ?", array_merge(array_values($cols), [$id, $sellerId]));
            } else {
                $id = uuid();
                $db->insert('products', $cols + ['id' => $id, 'seller_id' => $sellerId, 'slug' => slug_for($d['name'], $id), 'gallery' => '[]', 'status' => 'draft', 'live' => 0, 'created_at' => $now]);
            }
            $old = $db->all('SELECT id, image FROM product_variations WHERE product_id = ?', [$id]);
            $incoming = $variable ? $d['variations'] : [];
            $known = array_column($old, 'id');
            foreach ($incoming as $v) if (!empty($v['id']) && !in_array($v['id'], $known, true)) throw new RuntimeException('A variation could not be found. Refresh and try again.');
            $keep = array_filter(array_column($incoming, 'id'));
            foreach ($old as $g) {
                if (!in_array($g['id'], $keep, true)) {
                    $db->exec('DELETE FROM product_variations WHERE id = ?', [$g['id']]);
                    if ($g['image']) $lowImages[] = $g['image'];
                }
            }
            // SKUs can swap between variations: park them first, then set the final values.
            foreach ($incoming as $i => $v) if (!empty($v['id'])) $db->exec('UPDATE product_variations SET sku = ?, position = ? WHERE id = ?', ['~' . $v['id'], $i, $v['id']]);
            foreach ($incoming as $i => $v) {
                $row = ['sku' => $v['sku'], 'options' => jenc($v['options']), 'regular_price' => $v['regular_price'], 'sale_price' => $v['sale_price'],
                    'sale_starts_at' => dt_or_null($v['sale_starts_at']), 'sale_ends_at' => dt_or_null($v['sale_ends_at']), 'stock' => $v['stock'], 'enabled' => $v['enabled'] ? 1 : 0, 'position' => $i];
                if (!empty($v['id'])) {
                    $set = implode(', ', array_map(fn ($c) => $c . ' = ?', array_keys($row)));
                    $db->exec('UPDATE product_variations SET ' . $set . ' WHERE id = ? AND product_id = ?', array_merge(array_values($row), [$v['id'], $id]));
                } else {
                    $db->insert('product_variations', $row + ['id' => uuid(), 'product_id' => $id]);
                }
            }
        });
    } catch (Throwable $e) {
        if (Db::isDuplicate($e)) return ['ok' => false, 'error' => 'That SKU is already taken. Press Generate to get a free one.'];
        if ($e instanceof RuntimeException) return ['ok' => false, 'error' => $e->getMessage()];
        throw $e;
    }
    remove_unreferenced($id, $lowImages);
    return ['ok' => true, 'id' => $id];
}

function owned_product(string $id, string $sellerId): ?array
{
    $p = is_uuid($id) ? load_product($id) : null;
    return $p && $p['seller_id'] === $sellerId ? $p : null;
}

function submit_product(string $id, string $sellerId): ?string
{
    $p = owned_product($id, $sellerId);
    if (!$p) return 'Product not found.';
    if (!in_array($p['status'], ['draft', 'changes_requested', 'rejected'], true)) return 'This product cannot be submitted right now.';
    if ($pr = readiness_problems($p)) return $pr[0];
    foreach (categories_all() as $c) if ($c['parent_id'] === $p['category_id']) return 'Choose the most specific category for this product.';
    $n = db()->exec("UPDATE products SET status = 'pending_review', submitted_at = ?, updated_at = ? WHERE id = ? AND seller_id = ? AND status IN ('draft', 'changes_requested', 'rejected')", [now_db(), now_db(), $id, $sellerId]);
    if (!$n) return 'This product cannot be submitted right now.';
    announce('reviewers', 'review_queue', $p['name'] . ' is waiting for review');
    return null;
}

function withdraw_submission(string $id, string $sellerId): ?string
{
    $n = db()->exec("UPDATE products SET status = 'draft', updated_at = ? WHERE id = ? AND seller_id = ? AND status = 'pending_review'", [now_db(), $id, $sellerId]);
    if (!$n) return 'This product is not in review.';
    announce('reviewers', 'review_queue');
    return null;
}

function set_product_live(string $id, string $sellerId, bool $live): ?string
{
    $n = $live
        ? db()->exec("UPDATE products SET live = 1, updated_at = ? WHERE id = ? AND seller_id = ? AND status = 'approved' AND published_snapshot IS NOT NULL AND live = 0", [now_db(), $id, $sellerId])
        : db()->exec('UPDATE products SET live = 0, updated_at = ? WHERE id = ? AND seller_id = ? AND live = 1', [now_db(), $id, $sellerId]);
    return $n ? null : ($live ? 'Only an approved product can go back on the marketplace.' : 'This product is not live.');
}

function delete_product(string $id, string $sellerId): ?string
{
    $p = owned_product($id, $sellerId);
    if (!$p) return 'Product not found.';
    if ($p['live']) return 'Take this product off the marketplace before deleting it.';
    if ($p['status'] === 'pending_review') return 'Withdraw this product from review before deleting it.';
    $files = array_merge(product_image_paths($p), snapshot_paths($id));
    $n = db()->exec('DELETE FROM products WHERE id = ? AND seller_id = ? AND live = 0', [$id, $sellerId]);
    if (!$n) return 'Product not found.';
    foreach (array_unique($files) as $f) if (preg_match(Storage::productRe(), $f)) Storage::remove('products', $f);
    return null;
}

// "delta" adds or removes units (never below zero); "set" replaces the count. Stock is not reviewed content.
function adjust_stock(string $id, string $sellerId, ?string $variationId, string $mode, int $value): ?string
{
    if ($mode === 'set' && $value < 0) return 'Stock cannot be negative.';
    if ($value < -1000000 || $value > 1000000) return 'That number is too large.';
    $db = db();
    return $db->tx(function () use ($db, $id, $sellerId, $variationId, $mode, $value) {
        if (!$db->val('SELECT 1 FROM products WHERE id = ? AND seller_id = ?' . $db->lock(), [$id, $sellerId])) return 'Product not found.';
        $table = $variationId ? 'product_variations' : 'products';
        $where = $variationId ? 'id = ? AND product_id = ?' : 'id = ?';
        $args = $variationId ? [$variationId, $id] : [$id];
        $cur = $db->val("SELECT stock FROM $table WHERE $where", $args);
        if ($cur === null) return 'Product not found.';
        $new = $mode === 'set' ? $value : max(0, min(1000000, (int) $cur + $value));
        $db->exec("UPDATE $table SET stock = ? WHERE $where", array_merge([$new], $args));
        $db->exec('UPDATE products SET updated_at = ? WHERE id = ?', [now_db(), $id]);
        return null;
    });
}

// ---------------------------------------------------------------- review

function review_queue(): array
{
    $rows = db()->all("SELECT p.*, COALESCE(NULLIF(a.brand, ''), '{}') AS brand_json, u.email AS seller_email FROM products p JOIN users u ON u.id = p.seller_id LEFT JOIN seller_applications a ON a.user_id = p.seller_id WHERE p.status <> 'draft' OR p.live = 1 ORDER BY (p.status = 'pending_review') DESC, p.submitted_at DESC, p.updated_at DESC LIMIT 300");
    $out = [];
    foreach ($rows as $r) {
        $brand = jdec($r['brand_json'], []);
        unset($r['brand_json']);
        $p = hydrate_product($r);
        $p['store_name'] = trim((string) ($brand['store_name'] ?? '')) ?: 'Unnamed store';
        $p['seller_email'] = $r['seller_email'];
        $out[] = $p;
    }
    return attach_variations($out);
}

function review_product(array $reviewer, string $id, string $decision, string $comment, string $expectedRaw): ?string
{
    $comment = trim($comment);
    if ($decision !== 'approve' && $comment === '') return 'Add a note so the seller knows why.';
    $p = load_product($id);
    if (!$p) return 'Product not found.';
    if ($p['updated_raw'] !== $expectedRaw) return 'This product changed while you were reviewing it. Reopen it and review again.';
    $now = now_db();
    $oldPaths = [];
    if ($decision === 'take_down') {
        if (!$p['live']) return 'This product is not live.';
        db()->exec('UPDATE products SET live = 0, admin_comment = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?', [$comment, $reviewer['id'], $now, $now, $id]);
        $message = $p['name'] . ' was taken off the marketplace';
    } else {
        if ($p['status'] !== 'pending_review') return 'This product is not waiting for review.';
        if ($decision === 'approve') {
            if ($pr = readiness_problems($p)) return 'Cannot approve yet: ' . $pr[0];
            $oldPaths = snapshot_paths($id);
            db()->exec("UPDATE products SET status = 'approved', live = 1, published_snapshot = ?, published_at = ?, admin_comment = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND status = 'pending_review'",
                [jenc(product_snapshot($p)), $now, $comment ?: null, $reviewer['id'], $now, $now, $id]);
            $message = $p['name'] . ' was approved and is live';
        } else {
            $status = $decision === 'reject' ? 'rejected' : 'changes_requested';
            db()->exec("UPDATE products SET status = ?, admin_comment = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND status = 'pending_review'", [$status, $comment, $reviewer['id'], $now, $now, $id]);
            $message = $decision === 'reject' ? $p['name'] . ' was not approved' : 'Changes requested on ' . $p['name'];
        }
    }
    if ($oldPaths) remove_unreferenced($id, $oldPaths);
    announce('seller', 'product', $message, $p['seller_id']);
    notify($p['seller_id'], 'product', $message, $decision === 'approve' ? '' : trim($comment), '/products/edit?id=' . $id);
    announce('reviewers', 'review_queue');
    return null;
}

// ---------------------------------------------------------------- display helpers

function product_visibility(array $p): array
{
    if ($p['live'] && $p['status'] === 'approved') return ['live', 'Live on marketplace'];
    if ($p['live']) return ['live', 'Live (new version ' . strtolower(PRODUCT_STATUS_LABEL[$p['status']] ?? $p['status']) . ')'];
    if ($p['status'] === 'approved') return ['hidden', 'Approved, hidden'];
    return [$p['status'], PRODUCT_STATUS_LABEL[$p['status']] ?? $p['status']];
}

function stock_state(array $p): string
{
    if ($p['product_type'] === 'variable') {
        $enabled = array_filter($p['variations'], fn ($v) => $v['enabled']);
        if (!$enabled) return 'out';
        $total = array_sum(array_column($enabled, 'stock'));
        return $total <= 0 ? 'out' : ($total <= $p['low_stock_threshold'] ? 'low' : 'ok');
    }
    if (!$p['manage_stock']) return 'ok';
    return $p['stock'] <= 0 ? 'out' : ($p['stock'] <= $p['low_stock_threshold'] ? 'low' : 'ok');
}

function price_label(array $p): string
{
    $cur = $p['currency'];
    if ($p['product_type'] === 'variable') {
        $prices = array_filter(array_map(fn ($v) => $v['sale_price'] ?? $v['regular_price'], array_filter($p['variations'], fn ($v) => $v['enabled'])), fn ($x) => $x !== null);
        if (!$prices) return 'No price yet';
        return min($prices) === max($prices) ? money(min($prices), $cur) : money(min($prices), $cur) . ' – ' . money(max($prices), $cur);
    }
    if ($p['regular_price'] === null) return 'No price yet';
    return money($p['sale_price'] ?? $p['regular_price'], $cur);
}

function category_label(array $p): string
{
    return $p['category_path'] ? implode(' › ', $p['category_path']) : ($p['category'] ?: 'No category');
}
