<?php
declare(strict_types=1);

// ---------------------------------------------------------------- public: QR code scans and seller invites

route('GET', '/s', function () {
    $to = share_scan(input('c'));
    if ($to === null) abort(404, 'This store link is not active.');
    header('Cache-Control: no-store');
    redirect($to);
});

route('GET', '/join', function () {
    $code = input('c');
    $ref = preg_match('/^[A-Za-z0-9]{6,12}$/', $code) ? db()->one('SELECT seller_id FROM seller_share WHERE code = ?', [$code]) : null;
    if ($ref) {
        setcookie('vh_ref', $code, ['expires' => time() + 60 * 86400, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        $by = inbox_store_name($ref['seller_id']);
        redirect('/auth?mode=register', 'You were invited' . ($by !== '' ? ' by ' . $by : '') . ' to sell on DDLX. Create your account to start.');
    }
    redirect('/auth?mode=register');
});

// ---------------------------------------------------------------- seller: flyer and files

route('GET', '/seller/flyer', function () {
    $u = require_seller();
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    if (input('download') === '1') header('Content-Disposition: attachment; filename="ddlx-flyer.svg"');
    echo flyer_for_seller($u['id']);
    exit;
});

route('GET', '/admin/flyer', function () {
    require_admin();
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo flyer_svg(flyer_template(), 'Sample Store', base_url() . '/s/example');
    exit;
});

route('GET', '/files/promo', function () {
    $u = require_user();
    $a = db()->one('SELECT * FROM promo_assets WHERE id = ?', [input('id')]);
    if (!$a) abort(404);
    $admin = !empty($u['isAdmin']);
    if (!$admin && !promo_can($a, $u['id'])) abort(403, 'This file is not available to you.');
    $bytes = Storage::read('promos', $a['file']);
    if ($bytes === null) abort(404);
    $inline = input('preview') === '1' && str_starts_with($a['mime'], 'image/');
    if (!$inline && !$admin && empty($u['viewAs'])) promo_download_log($a['id'], $u['id']);
    $ext = pathinfo($a['file'], PATHINFO_EXTENSION);
    $name = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $a['title']) ?? '', '-') ?: 'ddlx-promo';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment; filename="' . $name . '.' . $ext . '"'));
    header('Content-Type: ' . $a['mime']);
    header('Content-Length: ' . strlen($bytes));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
});

// ---------------------------------------------------------------- admin

route('POST', '/admin/promo/save', function () {
    $u = require_admin();
    $err = promo_save($u, $_POST, $_FILES['file'] ?? null, !empty($_POST['notify']));
    redirect('/admin?tab=promo', $err ?? (input('id') !== '' ? 'Saved.' : 'Uploaded. Sellers in that group can now download it.'), $err ? 'error' : 'ok');
});

route('POST', '/admin/promo/delete', function () {
    $u = require_admin();
    promo_delete($u, input('id'));
    redirect('/admin?tab=promo', 'Deleted.');
});

route('POST', '/admin/flyer/save', function () {
    $u = require_admin();
    $err = flyer_save($u, $_POST, $_FILES['bg'] ?? null, !empty($_POST['remove_bg']));
    redirect('/admin?tab=promo', $err ?? 'Flyer template saved. Every seller\'s flyer now uses it.', $err ? 'error' : 'ok');
});

route('POST', '/admin/share/target', function () {
    $u = require_admin();
    $err = share_set_target($u, input('seller'), input('url'));
    redirect('/admin?tab=promo&q=' . rawurlencode(input('q')), $err ?? 'Saved.', $err ? 'error' : 'ok');
});

route('POST', '/admin/legal/save', function () {
    $u = require_admin();
    $err = legal_save($u, input('doc'), input_raw('text'), !empty($_POST['ask_again']));
    redirect('/admin?tab=legal&doc=' . rawurlencode(input('doc')), $err ?? 'Saved.', $err ? 'error' : 'ok');
});

route('POST', '/admin/legal/reset', function () {
    $u = require_admin();
    legal_reset($u, input('doc'));
    redirect('/admin?tab=legal&doc=' . rawurlencode(input('doc')), 'The built-in wording is back.');
});

route('POST', '/admin/legal/company', function () {
    $u = require_admin();
    $email = trim(input('email'));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) redirect('/admin?tab=legal', 'Enter a valid email.', 'error');
    set_setting('legal_company', str_limit(trim(input('company')), 120));
    set_setting('legal_email', $email);
    audit($u, 'settings', 'Updated the company details on the legal pages');
    redirect('/admin?tab=legal', 'Saved.');
});
