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
        $out .= '<a href="' . e($base . '?tab=' . $key) . '" class="' . ($key === $active ? 'active' : '') . '">' . e($label) . ($b > 0 ? '<span class="badge-count">' . $b . '</span>' : '') . '</a>';
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
