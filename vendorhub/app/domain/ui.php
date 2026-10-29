<?php
declare(strict_types=1);

// Small form helpers so every page renders fields the same way.

function f_text(string $name, string $label, string $value = '', ?string $error = null, array $o = []): string
{
    $id = 'f-' . $name;
    $type = $o['type'] ?? 'text';
    $attrs = '';
    foreach (['maxlength', 'placeholder', 'inputmode', 'min', 'max', 'step', 'autocomplete'] as $k) {
        if (isset($o[$k])) $attrs .= ' ' . $k . '="' . e($o[$k]) . '"';
    }
    if (!empty($o['required'])) $attrs .= ' required';
    if (!empty($o['readonly'])) $attrs .= ' readonly';
    $hint = isset($o['hint']) ? '<span class="hint">' . e($o['hint']) . '</span>' : '';
    $err = $error ? '<p class="err" role="alert">' . e($error) . '</p>' : '';
    return '<div class="field"><label for="' . $id . '">' . e($label) . (!empty($o['required']) ? ' <span class="err">*</span>' : '') . '</label>'
        . '<input id="' . $id . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '"' . $attrs . '>' . $hint . $err . '</div>';
}

function f_area(string $name, string $label, string $value = '', ?string $error = null, array $o = []): string
{
    $id = 'f-' . $name;
    $attrs = isset($o['maxlength']) ? ' maxlength="' . (int) $o['maxlength'] . '"' : '';
    $attrs .= isset($o['rows']) ? ' rows="' . (int) $o['rows'] . '"' : '';
    $hint = isset($o['hint']) ? '<span class="hint">' . e($o['hint']) . '</span>' : '';
    $err = $error ? '<p class="err" role="alert">' . e($error) . '</p>' : '';
    return '<div class="field"><label for="' . $id . '">' . e($label) . (!empty($o['required']) ? ' <span class="err">*</span>' : '') . '</label>'
        . '<textarea id="' . $id . '" name="' . e($name) . '"' . $attrs . '>' . e($value) . '</textarea>' . $hint . $err . '</div>';
}

function f_select(string $name, string $label, array $options, string $value = '', ?string $error = null, array $o = []): string
{
    $id = 'f-' . $name;
    $opts = '<option value="">' . e($o['placeholder'] ?? 'Choose…') . '</option>';
    foreach ($options as $k => $text) {
        $key = is_int($k) ? (string) $text : (string) $k;
        $opts .= '<option value="' . e($key) . '"' . ($key === $value ? ' selected' : '') . '>' . e((string) $text) . '</option>';
    }
    $hint = isset($o['hint']) ? '<span class="hint">' . e($o['hint']) . '</span>' : '';
    $err = $error ? '<p class="err" role="alert">' . e($error) . '</p>' : '';
    return '<div class="field"><label for="' . $id . '">' . e($label) . (!empty($o['required']) ? ' <span class="err">*</span>' : '') . '</label>'
        . '<select id="' . $id . '" name="' . e($name) . '">' . $opts . '</select>' . $hint . $err . '</div>';
}

function f_check(string $name, string $text, bool $checked, ?string $error = null): string
{
    return '<label class="check"><input type="checkbox" name="' . e($name) . '" value="yes"' . ($checked ? ' checked' : '') . '><span>' . e($text) . '</span></label>'
        . ($error ? '<p class="err" role="alert">' . e($error) . '</p>' : '');
}

function pill(string $status, array $labels = [], array $kinds = []): string
{
    $kind = $kinds[$status] ?? (in_array($status, ['approved', 'paid', 'live', 'verified', 'delivered'], true) ? 'ok' : (in_array($status, ['rejected', 'cancelled', 'refunded', 'out'], true) ? 'bad' : (in_array($status, ['draft', 'submitted', 'pending_review', 'changes_requested', 'needs_corrections', 'requested', 'low'], true) ? 'warn' : '')));
    return '<span class="pill' . ($kind ? ' pill-' . $kind : '') . '">' . e($labels[$status] ?? ucfirst(str_replace('_', ' ', $status))) . '</span>';
}

function tabs_nav(array $tabs, string $active, string $base, array $badges = []): string
{
    $out = '<nav class="tabs" aria-label="Sections">';
    foreach ($tabs as $key => $label) {
        $b = (int) ($badges[$key] ?? 0);
        $out .= '<a href="' . e($base . '?tab=' . $key) . '" class="' . ($key === $active ? 'active' : '') . '">' . e($label) . '<span class="badge-count' . ($b > 0 ? '' : ' hidden') . '" data-badge="' . e($label) . '">' . $b . '</span></a>';
    }
    return $out . '</nav>';
}

// Files named by the browser (e.g. "my photo.JPG") never reach disk with that name; this is only for display.
function post_array(string $key): array
{
    $v = $_POST[$key] ?? [];
    return is_array($v) ? $v : [];
}

// datetime-local values are Lagos time; the database and marketplace use UTC.
function local_to_iso(?string $v): ?string
{
    $v = trim((string) $v);
    if ($v === '') return null;
    try {
        return (new DateTimeImmutable($v, new DateTimeZone('Africa/Lagos')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    } catch (Throwable) {
        return null;
    }
}

function iso_to_local(?string $iso): string
{
    if (!$iso) return '';
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return '';
    }
}

// One select with every selectable (most specific) category, grouped by its parents.
function category_select(?string $selected, ?string $error = null): string
{
    $all = categories_all();
    $groups = [];
    foreach (category_flat($all) as $row) {
        $c = $row['category'];
        if (category_children($c['id'], $all)) continue;
        if (!$c['active'] && $c['id'] !== $selected) continue;
        $chain = category_chain($c['id'], $all);
        $label = implode(' › ', array_map(fn ($x) => $x['name'], array_slice($chain, 0, -1)));
        $groups[$label][] = $c;
    }
    $html = '<option value="">Choose a category…</option>';
    foreach ($groups as $label => $items) {
        $html .= $label !== '' ? '<optgroup label="' . e($label) . '">' : '';
        foreach ($items as $c) $html .= '<option value="' . e($c['id']) . '"' . ($c['id'] === $selected ? ' selected' : '') . '>' . e($c['name']) . ($c['active'] ? '' : ' (hidden)') . '</option>';
        $html .= $label !== '' ? '</optgroup>' : '';
    }
    return '<div class="field"><label for="f-category_id">Category <span class="err">*</span></label><select id="f-category_id" name="category_id">' . $html . '</select>'
        . '<span class="hint">Pick the most specific category that fits.</span>' . ($error ? '<p class="err" role="alert">' . e($error) . '</p>' : '') . '</div>';
}

function product_image_url(?string $path): string
{
    return $path ? '/files/product?p=' . rawurlencode($path) : '';
}

// Small inline icons (outline style). Names: store package chart wallet arrow-up-right arrow-right check user building file-check send bell truck undo shield
function icon(string $name, string $class = ''): string
{
    static $p = [
        'store' => '<path d="m3 9 1.5-5h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8h14v-8"/><path d="M10 20v-5h4v5"/>',
        'package' => '<path d="m7.5 4.3 9 5.2"/><path d="M21 8v8a2 2 0 0 1-1 1.7l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.7l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 15v3"/><path d="M12 9v9"/><path d="M17 5v13"/>',
        'wallet' => '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5"/><path d="M16 13h2"/>',
        'arrow-up-right' => '<path d="M7 17 17 7"/><path d="M7 7h10v10"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'building' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4M10 10h4M10 14h4M10 18h4"/>',
        'file-check' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="m9 15 2 2 4-4"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
        'spark' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
        'truck' => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18h-5"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-2"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'undo' => '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
    ];
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

// The DDLX tote bag, drawn in SVG so it needs no downloads and cannot fail to load.
function tote_svg(): string
{
    return '<div class="tote-wrap" data-parallax><svg class="tote" viewBox="0 0 320 380" role="img" aria-label="DDLX tote bag">
  <g class="tote-ring"><ellipse cx="46" cy="270" rx="30" ry="12" fill="none" stroke="#c4cad8" stroke-width="9" transform="rotate(-24 46 270)"/></g>
  <path d="M96 128c0-62 128-62 128 0" fill="none" stroke="#3568f4" stroke-width="13" stroke-linecap="round"/>
  <path d="M118 128c0-44 84-44 84 0" fill="none" stroke="#2a54cc" stroke-width="11" stroke-linecap="round" opacity=".55"/>
  <path d="M66 126h188c8 0 14 6 15 14l22 192c1 10-7 18-17 18H46c-10 0-18-8-17-18l22-192c1-8 7-14 15-14z" fill="#3568f4"/>
  <path d="M66 126h188c8 0 14 6 15 14l3 28H48l3-28c1-8 7-14 15-14z" fill="#4a7bff"/>
  <rect x="86" y="226" width="148" height="76" rx="6" fill="#fff"/>
  <text x="160" y="277" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="800" font-size="46" letter-spacing="2" fill="#111525">DDLX</text>
  <g transform="rotate(14 250 150)"><rect x="238" y="140" width="34" height="62" rx="5" fill="#ff5938"/><circle cx="255" cy="152" r="4" fill="#fff"/></g>
</svg></div>';
}
