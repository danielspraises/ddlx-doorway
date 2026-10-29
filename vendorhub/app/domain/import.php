<?php
declare(strict_types=1);

// Bulk product upload. Simple products only; they arrive as drafts so the seller can add photos and submit them.

const IMPORT_MAX_ROWS = 100;
const IMPORT_COLUMNS = ['name', 'sku', 'category', 'brand', 'tags', 'short_description', 'description', 'currency', 'regular_price', 'sale_price', 'stock', 'low_stock_threshold', 'weight_kg', 'length_cm', 'width_cm', 'height_cm'];
const IMPORT_REQUIRED = ['name', 'category', 'regular_price', 'weight_kg', 'length_cm', 'width_cm', 'height_cm'];

function import_template_rows(): array
{
    $leaf = array_keys(import_category_map());
    $example = $leaf ? implode(' > ', array_map('ucfirst', explode(' > ', (string) $leaf[0]))) : 'Category > Sub category';
    return [IMPORT_COLUMNS, ['Ankara Shirt', '', $example, 'My Brand', 'ankara, shirt', 'Short line about it', 'Longer description', 'NGN', '12000', '9500', '20', '3', '0.4', '30', '22', '4']];
}

// Leaf categories by full path ("fashion > men > clothing > shirts") and by name when the name is unique.
function import_category_map(): array
{
    $all = categories_all();
    $byPath = [];
    $byName = [];
    foreach ($all as $c) {
        if (!$c['active'] || category_children($c['id'], $all)) continue;
        $byPath[mb_strtolower(implode(' > ', category_path_names($c['id'])))] = $c['id'];
        $byName[mb_strtolower($c['name'])][] = $c['id'];
    }
    foreach ($byName as $n => $ids) if (count($ids) === 1) $byPath[$n] = $ids[0];
    return $byPath;
}

function import_parse_csv(string $text): array|string
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    if (trim($text) === '') return 'The file is empty.';
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $first = (string) fgets($fh);
    rewind($fh);
    $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($r === [null] || !array_filter($r, fn ($v) => trim((string) $v) !== '')) continue;
        $rows[] = array_map(fn ($v) => trim((string) $v), $r);
    }
    fclose($fh);
    if (count($rows) < 2) return 'Add at least one product row under the header row.';
    $head = array_map(fn ($h) => mb_strtolower(str_replace([' ', '-'], '_', $h)), array_shift($rows));
    foreach (IMPORT_REQUIRED as $req) if (!in_array($req, $head, true)) return 'The file is missing the "' . $req . '" column. Download the template to see the columns.';
    if (count($rows) > IMPORT_MAX_ROWS) return 'Upload up to ' . IMPORT_MAX_ROWS . ' products at a time.';
    $out = [];
    foreach ($rows as $i => $r) {
        $row = [];
        foreach ($head as $j => $h) if (in_array($h, IMPORT_COLUMNS, true)) $row[$h] = $r[$j] ?? '';
        $out[$i + 2] = $row; // spreadsheet row number
    }
    return $out;
}

function import_number(string $v, bool $int = false): float|int|null|false
{
    $v = str_replace([',', ' '], '', $v);
    if ($v === '') return null;
    if (!is_numeric($v) || (float) $v < 0) return false;
    return $int ? (int) $v : round((float) $v, 2);
}

// Turns every row into a product, or explains what is wrong. Nothing is saved here.
function import_prepare(string $sellerId, array $rows): array
{
    $cats = import_category_map();
    $prefix = sku_prefix($sellerId);
    $nextNo = (int) substr(local_next_sku($prefix), strlen($prefix) + 1);
    $seen = [];
    $products = [];
    $errors = [];
    foreach ($rows as $line => $r) {
        $e = [];
        $name = $r['name'] ?? '';
        $catKey = mb_strtolower(preg_replace('/\s*>\s*/', ' > ', $r['category'] ?? '') ?? '');
        $cat = $cats[$catKey] ?? null;
        if ($name === '') $e[] = 'name is empty';
        if (!$cat) $e[] = 'category "' . ($r['category'] ?? '') . '" was not found (use the full path of the most specific category)';
        $sku = $r['sku'] ?? '';
        if ($sku === '') { $sku = $prefix . '-' . str_pad((string) $nextNo++, 4, '0', STR_PAD_LEFT); }
        if (isset($seen[mb_strtolower($sku)])) $e[] = 'SKU ' . $sku . ' appears twice in the file';
        $seen[mb_strtolower($sku)] = true;
        $cur = strtoupper($r['currency'] ?? '') ?: 'NGN';
        if (!in_array($cur, CURRENCIES, true)) $e[] = 'currency ' . $cur . ' is not supported';
        $num = [];
        foreach (['regular_price', 'sale_price', 'weight_kg', 'length_cm', 'width_cm', 'height_cm'] as $k) {
            $n = import_number($r[$k] ?? '');
            if ($n === false) $e[] = $k . ' must be a number';
            $num[$k] = $n === false ? null : $n;
        }
        foreach (['regular_price', 'weight_kg', 'length_cm', 'width_cm', 'height_cm'] as $k) if (($num[$k] ?? 0) <= 0 && !in_array($k . ' must be a number', $e, true)) $e[] = $k . ' is required';
        $stock = import_number($r['stock'] ?? '', true);
        $low = import_number($r['low_stock_threshold'] ?? '', true);
        if ($stock === false || $low === false) $e[] = 'stock must be a whole number';
        $p = ['id' => null, 'name' => $name, 'sku' => $sku, 'product_type' => 'simple', 'brand' => $r['brand'] ?? '', 'category_id' => $cat, 'tags' => array_values(array_filter(array_map('trim', explode(',', $r['tags'] ?? '')))),
            'short_description' => $r['short_description'] ?? '', 'description' => $r['description'] ?? '', 'currency' => $cur, 'regular_price' => (float) ($num['regular_price'] ?? 0), 'sale_price' => $num['sale_price'] ?? null,
            'sale_starts_at' => null, 'sale_ends_at' => null, 'manage_stock' => $stock !== null && $stock !== false, 'stock' => (int) ($stock ?: 0), 'low_stock_threshold' => (int) ($low === null || $low === false ? 3 : $low),
            'allow_backorders' => false, 'weight_kg' => $num['weight_kg'], 'length_cm' => $num['length_cm'], 'width_cm' => $num['width_cm'], 'height_cm' => $num['height_cm'], 'attributes' => [], 'variations' => []];
        if (!$e && ($msg = validate_product($p))) $e[] = rtrim($msg, '.');
        if ($e) $errors[$line] = $e; else $products[$line] = $p;
    }
    return ['products' => $products, 'errors' => $errors];
}

// All rows must be valid or nothing is created. A problem found while saving (for example a SKU already used on the
// marketplace) stops the import and says how many were created.
function import_products(string $sellerId, string $csv): array
{
    $rows = import_parse_csv($csv);
    if (is_string($rows)) return ['ok' => false, 'error' => $rows, 'errors' => [], 'created' => []];
    $prep = import_prepare($sellerId, $rows);
    if ($prep['errors']) return ['ok' => false, 'error' => 'Nothing was imported. Fix these rows and upload again.', 'errors' => $prep['errors'], 'created' => []];
    $created = [];
    foreach ($prep['products'] as $line => $p) {
        $r = save_product($sellerId, $p);
        if (!$r['ok']) return ['ok' => false, 'error' => 'Stopped at row ' . $line . ': ' . $r['error'] . ($created ? ' The ' . count($created) . ' products before it were created as drafts.' : ''), 'errors' => [], 'created' => $created];
        $created[$line] = $r['id'];
    }
    return ['ok' => true, 'error' => null, 'errors' => [], 'created' => $created];
}
