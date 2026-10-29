<?php
declare(strict_types=1);

// Seller product editor and the actions on a product (submit, withdraw, live, delete, stock, images).

function product_input_from_post(): array
{
    $attributes = parse_attributes(input_raw('attributes'));
    $vars = [];
    foreach (post_array('var') as $row) {
        if (!is_array($row)) continue;
        $opts = json_decode((string) ($row['options'] ?? '{}'), true);
        $vars[] = [
            'id' => is_uuid($row['id'] ?? null) ? $row['id'] : null,
            'sku' => trim((string) ($row['sku'] ?? '')),
            'options' => is_array($opts) ? array_map('strval', $opts) : [],
            'regular_price' => money_or_null($row['regular_price'] ?? null),
            'sale_price' => money_or_null($row['sale_price'] ?? null),
            'sale_starts_at' => local_to_iso($row['sale_starts_at'] ?? ''),
            'sale_ends_at' => local_to_iso($row['sale_ends_at'] ?? ''),
            'stock' => max(0, min(1000000, (int) ($row['stock'] ?? 0))),
            'enabled' => !empty($row['enabled']),
        ];
    }
    $tags = array_values(array_filter(array_map(fn ($t) => str_limit(trim($t), 40), explode(',', input_raw('tags')))));
    return [
        'id' => is_uuid(input('id')) ? input('id') : null,
        'expected' => input('expected'),
        'name' => str_limit(input('name'), 200), 'sku' => str_limit(input('sku'), 100),
        'product_type' => input('product_type') === 'variable' ? 'variable' : 'simple',
        'brand' => str_limit(input('brand'), 100), 'category_id' => is_uuid(input('category_id')) ? input('category_id') : null,
        'tags' => array_slice($tags, 0, 20),
        'short_description' => str_limit(input_raw('short_description'), 500), 'description' => str_limit(input_raw('description'), 10000),
        'currency' => in_array(input('currency'), CURRENCIES, true) ? input('currency') : 'NGN',
        'regular_price' => money_or_null(input('regular_price')), 'sale_price' => money_or_null(input('sale_price')),
        'sale_starts_at' => local_to_iso(input('sale_starts_at')), 'sale_ends_at' => local_to_iso(input('sale_ends_at')),
        'manage_stock' => !empty($_POST['manage_stock']), 'stock' => max(0, min(1000000, (int) input('stock', '0'))),
        'low_stock_threshold' => max(0, min(1000000, (int) input('low_stock_threshold', '5'))), 'allow_backorders' => !empty($_POST['allow_backorders']),
        'weight_kg' => num_or_null(input('weight_kg'), 3), 'length_cm' => num_or_null(input('length_cm'), 1), 'width_cm' => num_or_null(input('width_cm'), 1), 'height_cm' => num_or_null(input('height_cm'), 1),
        'attributes' => $attributes, 'variations' => $vars,
    ];
}

// Rebuilds the variation rows from the attributes, keeping rows (and their data) whose options still exist.
function rebuild_variations(array $input, string $sellerId): array
{
    $existing = [];
    foreach ($input['variations'] as $v) $existing[option_key($v['options'])] = $v;
    $out = [];
    $prefix = $input['sku'] !== '' ? $input['sku'] : sku_prefix($sellerId);
    $taken = array_map(fn ($v) => mb_strtolower($v['sku']), $input['variations']);
    $n = 0;
    foreach (array_slice(combinations($input['attributes']), 0, MAX_VARIATIONS) as $combo) {
        $key = option_key($combo);
        if (isset($existing[$key])) { $out[] = $existing[$key]; continue; }
        do { $n++; $sku = $prefix . '-V' . $n; } while (in_array(mb_strtolower($sku), $taken, true));
        $taken[] = mb_strtolower($sku);
        $out[] = ['id' => null, 'sku' => $sku, 'options' => $combo, 'regular_price' => $input['regular_price'], 'sale_price' => null, 'sale_starts_at' => null, 'sale_ends_at' => null, 'stock' => 0, 'enabled' => true];
    }
    return $out;
}

function product_form_from_saved(array $p): array
{
    return $p + ['expected' => $p['updated_raw']];
}

function render_editor(array $u, array $form, ?string $error = null, ?string $notice = null): void
{
    $saved = !empty($form['id']) ? load_product($form['id']) : null;
    render('product_editor', ['title' => $saved ? 'Edit product' : 'New product', 'form' => $form, 'saved' => $saved, 'error' => $error, 'notice' => $notice, 'user' => $u, 'market' => marketplace_connected()]);
}

route('GET', '/products/edit', function () {
    $u = require_seller();
    $id = input('id');
    if ($id === '') {
        $new = ['id' => null, 'expected' => '', 'name' => '', 'sku' => '', 'product_type' => 'simple', 'brand' => '', 'category_id' => null, 'tags' => [], 'short_description' => '', 'description' => '', 'currency' => 'NGN',
            'regular_price' => null, 'sale_price' => null, 'sale_starts_at' => null, 'sale_ends_at' => null, 'manage_stock' => true, 'stock' => 0, 'low_stock_threshold' => 5, 'allow_backorders' => false,
            'weight_kg' => null, 'length_cm' => null, 'width_cm' => null, 'height_cm' => null, 'attributes' => [], 'variations' => []];
        render_editor($u, $new);
        return;
    }
    $p = owned_product($id, $u['id']);
    if (!$p) redirect('/seller?tab=products', 'Product not found.', 'error');
    render_editor($u, product_form_from_saved($p));
});

route('POST', '/products/save', function () {
    $u = require_seller();
    $form = product_input_from_post();
    $action = input('action', 'save');
    if ($action === 'rebuild') {
        $form['variations'] = rebuild_variations($form, $u['id']);
        render_editor($u, $form, null, count($form['variations']) . ' variation(s) ready. Set their prices and stock, then save.');
        return;
    }
    if ($action === 'generate_sku') {
        $form['sku'] = suggest_sku($u['id']) ?? $form['sku'];
        render_editor($u, $form, null, 'A free SKU was filled in. Save to keep it.');
        return;
    }
    $r = save_product($u['id'], $form);
    if (!$r['ok']) { render_editor($u, $form, $r['error']); return; }
    if ($action === 'submit') {
        if ($err = submit_product($r['id'], $u['id'])) redirect('/products/edit?id=' . $r['id'], 'Saved, but it cannot be submitted yet: ' . $err, 'error');
        redirect('/seller?tab=products', 'Product submitted for review.');
    }
    redirect('/products/edit?id=' . $r['id'], 'Product saved.');
});

// Lifecycle buttons from the product list and editor.
route('POST', '/products/action', function () {
    $u = require_seller();
    $id = input('id');
    $back = input('back') === 'editor' ? '/products/edit?id=' . $id : '/seller?tab=' . (input('back') === 'inventory' ? 'inventory' : 'products');
    $err = match (input('action')) {
        'submit' => submit_product($id, $u['id']),
        'withdraw' => withdraw_submission($id, $u['id']),
        'live' => set_product_live($id, $u['id'], true),
        'unlive' => set_product_live($id, $u['id'], false),
        'delete' => delete_product($id, $u['id']),
        default => 'Unknown action.',
    };
    $done = ['submit' => 'Product submitted for review.', 'withdraw' => 'Withdrawn from review.', 'live' => 'Back on the marketplace.', 'unlive' => 'Taken off the marketplace.', 'delete' => 'Product deleted.'][input('action')] ?? 'Done.';
    if (input('action') === 'delete' && !$err) $back = '/seller?tab=products';
    redirect($back, $err ?? $done, $err ? 'error' : 'ok');
});

route('POST', '/inventory/adjust', function () {
    $u = require_seller();
    $op = input('op');
    $mode = $op === 'set' ? 'set' : 'delta';
    $value = abs((int) input('value', '0'));
    if ($op === 'remove') $value = -$value;
    $err = adjust_stock(input('id'), $u['id'], is_uuid(input('variation_id')) ? input('variation_id') : null, $mode, $value);
    redirect('/seller?tab=inventory', $err ?? 'Stock updated.', $err ? 'error' : 'ok');
});

// ---------------------------------------------------------------- photos

route('POST', '/products/image', function () {
    $u = require_seller();
    $id = input('id');
    $slot = input('slot');
    $p = owned_product($id, $u['id']);
    $back = '/products/edit?id=' . $id;
    if (!$p) redirect('/seller?tab=products', 'Product not found.', 'error');
    if ($p['status'] === 'pending_review') redirect($back, 'This product is in review. Withdraw it from review to change photos.', 'error');
    if (!in_array($slot, ['featured', 'gallery', 'variation'], true)) redirect($back, 'Unknown photo slot.', 'error');
    $variationId = is_uuid(input('variation_id')) ? input('variation_id') : null;
    if ($slot === 'variation' && !array_filter($p['variations'], fn ($v) => $v['id'] === $variationId)) redirect($back, 'Choose a variation first.', 'error');

    // Several files may arrive at once (gallery); normalise $_FILES['files'].
    $files = [];
    $f = $_FILES['files'] ?? $_FILES['file'] ?? null;
    if ($f && is_array($f['name'] ?? null)) {
        foreach (array_keys($f['name']) as $i) $files[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    } elseif ($f) {
        $files[] = $f;
    }
    $files = array_values(array_filter($files, fn ($x) => ($x['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (!$files) redirect($back, 'Choose a photo first.', 'error');
    if ($slot !== 'gallery') $files = array_slice($files, 0, 1);

    $added = 0;
    $skipped = [];
    $now = now_db();
    foreach ($files as $file) {
        $fresh = load_product($id);
        if ($slot === 'gallery' && count($fresh['gallery']) >= MAX_GALLERY) { $skipped[] = 'The gallery holds up to ' . MAX_GALLERY . ' photos.'; break; }
        $got = Storage::fromUpload($file, Storage::IMAGE_TYPES, PRODUCT_IMAGE_MAX);
        if (is_string($got)) { $skipped[] = ($file['name'] ?? 'A file') . ': ' . $got; continue; }
        [$bytes, $mime] = $got;
        $path = $u['id'] . '/' . $id . '/' . uuid() . '.' . Storage::EXT[$mime];
        Storage::save('products', $path, Storage::cleanImage($bytes, $mime));
        $replaced = null;
        $touch = "status = CASE WHEN status = 'approved' THEN 'draft' ELSE status END, updated_at = ?";
        if ($slot === 'featured') {
            $replaced = $fresh['featured_image'];
            db()->exec("UPDATE products SET featured_image = ?, $touch WHERE id = ? AND seller_id = ? AND status <> 'pending_review'", [$path, $now, $id, $u['id']]);
        } elseif ($slot === 'gallery') {
            $g = $fresh['gallery'];
            $g[] = $path;
            db()->exec("UPDATE products SET gallery = ?, $touch WHERE id = ? AND seller_id = ? AND status <> 'pending_review'", [jenc($g), $now, $id, $u['id']]);
        } else {
            foreach ($fresh['variations'] as $v) if ($v['id'] === $variationId) $replaced = $v['image'];
            db()->exec('UPDATE product_variations SET image = ? WHERE id = ? AND product_id = ?', [$path, $variationId, $id]);
            db()->exec("UPDATE products SET $touch WHERE id = ?", [$now, $id]);
        }
        if ($replaced) remove_unreferenced($id, [$replaced]);
        $added++;
    }
    $msg = $added ? $added . ' photo' . ($added === 1 ? '' : 's') . ' saved.' : '';
    if ($skipped) $msg = trim($msg . ' ' . implode(' ', $skipped));
    redirect($back, $msg ?: 'No photos were saved.', $added ? 'ok' : 'error');
});

route('POST', '/products/image/remove', function () {
    $u = require_seller();
    $id = input('id');
    $path = input('path');
    $p = owned_product($id, $u['id']);
    $back = '/products/edit?id=' . $id;
    if (!$p) redirect('/seller?tab=products', 'Product not found.', 'error');
    if ($p['status'] === 'pending_review') redirect($back, 'This product is in review. Withdraw it from review to change photos.', 'error');
    $now = now_db();
    $touch = "status = CASE WHEN status = 'approved' THEN 'draft' ELSE status END, updated_at = ?";
    if ($p['featured_image'] === $path) {
        db()->exec("UPDATE products SET featured_image = NULL, $touch WHERE id = ?", [$now, $id]);
    } elseif (in_array($path, $p['gallery'], true)) {
        db()->exec("UPDATE products SET gallery = ?, $touch WHERE id = ?", [jenc(array_values(array_diff($p['gallery'], [$path]))), $now, $id]);
    } else {
        $hit = false;
        foreach ($p['variations'] as $v) if ($v['image'] === $path) { db()->exec('UPDATE product_variations SET image = NULL WHERE id = ?', [$v['id']]); $hit = true; }
        if (!$hit) redirect($back, 'Photo not found.', 'error');
        db()->exec("UPDATE products SET $touch WHERE id = ?", [$now, $id]);
    }
    remove_unreferenced($id, [$path]);
    redirect($back, 'Photo removed.');
});

// The seller sees their own photos; reviewers and admins can see any seller's.
route('GET', '/files/product', function () {
    $u = require_user();
    $p = input('p');
    if (!preg_match(Storage::productRe(), $p, $m)) abort(404);
    if ($m[1] !== $u['id'] && !$u['isAdmin'] && !$u['canReview']) abort(403, 'You do not have access to this photo.');
    $bytes = Storage::read('products', $p);
    if ($bytes === null) abort(404);
    Storage::send($bytes, Storage::MIME[$m[3]] ?? 'application/octet-stream', false);
});

route('GET', '/files/store', function () {
    $u = require_user();
    $p = input('p');
    if (!preg_match(Storage::storeRe(), $p, $m)) abort(404);
    if ($m[1] !== $u['id'] && !$u['isAdmin']) abort(403);
    $bytes = Storage::read('stores', $p);
    if ($bytes === null) abort(404);
    Storage::send($bytes, Storage::MIME[$m[2]] ?? 'application/octet-stream', false);
});

route('GET', '/api/sku/suggest', function () {
    $u = require_seller();
    $sku = suggest_sku($u['id']);
    $sku ? json_out(['sku' => $sku]) : json_out(['error' => 'Could not find a free SKU. Type one in instead.'], 503);
}, ['api' => true]);

route('GET', '/products/import/template', function () {
    require_seller();
    $rows = import_template_rows();
    csv_out('ddlx-products-template.csv', array_shift($rows), $rows);
});

route('GET', '/products/import', function () {
    $u = require_seller();
    render('import', ['title' => 'Import products', 'user' => $u, 'result' => null, 'categories' => array_map(fn ($k) => implode(' > ', array_map('ucfirst', explode(' > ', $k))), array_keys(import_category_map()))]);
});

route('POST', '/products/import', function () {
    $u = require_seller();
    $f = $_FILES['file'] ?? null;
    $csv = '';
    $err = null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $err = 'Choose a CSV file to upload.';
    elseif (($f['size'] ?? 0) > 1024 * 1024) $err = 'That file is too large. Keep it under 1 MB.';
    else $csv = (string) file_get_contents($f['tmp_name']);
    $res = $err ? ['ok' => false, 'error' => $err, 'errors' => [], 'created' => []] : import_products($u['id'], $csv);
    if ($res['ok']) redirect('/seller?tab=products', count($res['created']) . ' products added as drafts. Add photos, then submit them for review.');
    render('import', ['title' => 'Import products', 'user' => $u, 'result' => $res, 'categories' => array_map(fn ($k) => implode(' > ', array_map('ucfirst', explode(' > ', $k))), array_keys(import_category_map()))]);
});

route('GET', '/inventory/export', function () {
    $u = require_seller();
    $rows = stock_template_rows($u['id']);
    csv_out('ddlx-stock.csv', array_shift($rows), $rows);
});

route('POST', '/inventory/import', function () {
    $u = require_seller();
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) redirect('/seller?tab=inventory', 'Choose a CSV file to upload.', 'error');
    if (($f['size'] ?? 0) > 1024 * 1024) redirect('/seller?tab=inventory', 'That file is too large. Keep it under 1 MB.', 'error');
    $res = import_stock($u['id'], (string) file_get_contents($f['tmp_name']));
    if ($res['ok']) redirect('/seller?tab=inventory', 'Stock updated for ' . $res['updated'] . ' item' . ($res['updated'] === 1 ? '' : 's') . '.');
    render('import', ['title' => 'Update stock', 'user' => $u, 'result' => $res, 'categories' => [], 'stock_mode' => true]);
});
