<?php
declare(strict_types=1);

// Seller promotion tools: promo files sent to chosen sellers, each seller's QR code and flyer, and invite links.

const PROMO_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4'];
const PROMO_MAX_BYTES = 12 * 1048576;

// ---------------------------------------------------------------- share links (QR code and invite)

/** The seller's permanent short code, created on first use. */
function share_row(string $sellerId): array
{
    $db = db();
    $r = $db->one('SELECT * FROM seller_share WHERE seller_id = ?', [$sellerId]);
    if ($r) return $r;
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    for ($try = 0; $try < 20; $try++) {
        $code = '';
        for ($i = 0; $i < 7; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        if ($db->val('SELECT 1 FROM seller_share WHERE code = ?', [$code])) continue;
        try {
            $db->insert('seller_share', ['seller_id' => $sellerId, 'code' => $code, 'scans' => 0, 'created_at' => now_db()]);
        } catch (Throwable) {
            $again = $db->one('SELECT * FROM seller_share WHERE seller_id = ?', [$sellerId]);
            if ($again) return $again;
            continue;
        }
        break;
    }
    return $db->one('SELECT * FROM seller_share WHERE seller_id = ?', [$sellerId]) ?: ['seller_id' => $sellerId, 'code' => '', 'store_url' => null, 'scans' => 0, 'last_scan_at' => null];
}

function share_url(string $code): string { return base_url() . '/s/' . $code; }
function join_url(string $code): string { return base_url() . '/join/' . $code; }

/** The seller's store page as the marketplace itself reports it (cached for a day). Empty when it cannot be found. */
function share_marketplace_url(string $sellerId): string
{
    $r = share_row($sellerId);
    $fresh = $r['auto_at'] && strtotime((string) $r['auto_at'] . ' UTC') > time() - 86400;
    if ($fresh && trim((string) ($r['auto_url'] ?? '')) !== '') return (string) $r['auto_url'];
    $link = seller_link($sellerId);
    $d = marketplace_call('/store-url?' . ($link ? 'user_id=' . $link['uid'] : 'seller_id=' . rawurlencode($sellerId)), 'GET', null, 5);
    $url = trim((string) ($d['url'] ?? ''));
    if ($url !== '' && preg_match('#^https?://#i', $url)) {
        db()->exec('UPDATE seller_share SET auto_url = ?, auto_at = ? WHERE seller_id = ?', [str_limit($url, 500), now_db(), $sellerId]);
        return $url;
    }
    return trim((string) ($r['auto_url'] ?? '')); // an older answer is better than none
}

/** Where the QR code sends customers: the seller's store page on the marketplace (or the admin's override). */
function share_target(string $sellerId): string
{
    $r = db()->one('SELECT store_url FROM seller_share WHERE seller_id = ?', [$sellerId]);
    $custom = trim((string) ($r['store_url'] ?? ''));
    if ($custom !== '' && preg_match('#^https?://#i', $custom)) return $custom;
    $real = share_marketplace_url($sellerId);
    if ($real !== '') return $real;
    $market = rtrim((string) setting('marketplace_url', cfg('marketplace_url', '')), '/');
    if ($market === '') return '';
    return $market . '/store/' . slugify(inbox_store_name($sellerId) ?: 'seller') . '-' . substr($sellerId, 0, 6);
}

/** A customer scanned a QR code: count it and say where to go (null when the code is unknown). */
function share_scan(string $code): ?string
{
    $r = db()->one('SELECT seller_id FROM seller_share WHERE code = ?', [$code]);
    if (!$r) return null;
    db()->exec('UPDATE seller_share SET scans = scans + 1, last_scan_at = ? WHERE code = ?', [now_db(), $code]);
    $t = share_target($r['seller_id']);
    return $t !== '' ? $t : null;
}

function share_set_target(array $admin, string $sellerId, string $url): ?string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) return 'Enter a full web address starting with https://.';
    if (!db()->val('SELECT 1 FROM users WHERE id = ?', [$sellerId])) return 'Seller not found.';
    share_row($sellerId);
    db()->exec('UPDATE seller_share SET store_url = ? WHERE seller_id = ?', [$url === '' ? null : str_limit($url, 500), $sellerId]);
    audit($admin, 'staff', ($url === '' ? 'Reset the QR code destination of ' : 'Set the QR code destination of ') . (string) db()->val('SELECT email FROM users WHERE id = ?', [$sellerId]), $sellerId);
    return null;
}

// ---------------------------------------------------------------- invites (seller refers seller)

function referral_attach(string $userId): void
{
    $code = (string) ($_COOKIE['vh_ref'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{6,12}$/', $code)) return;
    $ref = db()->val('SELECT seller_id FROM seller_share WHERE code = ?', [$code]);
    if (!$ref || $ref === $userId) return;
    db()->exec('UPDATE seller_applications SET referred_by = ? WHERE user_id = ? AND referred_by IS NULL', [$ref, $userId]);
}

function referral_stats(string $sellerId): array
{
    $db = db();
    return [
        'applied' => (int) $db->val('SELECT COUNT(*) FROM seller_applications WHERE referred_by = ?', [$sellerId]),
        'verified' => (int) $db->val("SELECT COUNT(*) FROM seller_applications a JOIN seller_verifications v ON v.user_id = a.user_id AND v.status = 'approved' WHERE a.referred_by = ?", [$sellerId]),
    ];
}

/** Who has brought in sellers, most first (admin view). */
function referral_leaders(int $limit = 20): array
{
    $rows = db()->all("SELECT a.referred_by AS id, COUNT(*) AS applied, SUM(CASE WHEN v.status = 'approved' THEN 1 ELSE 0 END) AS verified FROM seller_applications a LEFT JOIN seller_verifications v ON v.user_id = a.user_id AND v.status = 'approved' WHERE a.referred_by IS NOT NULL GROUP BY a.referred_by ORDER BY verified DESC, applied DESC LIMIT " . (int) $limit);
    foreach ($rows as &$r) { $r['store'] = inbox_store_name($r['id']) ?: (string) db()->val('SELECT email FROM users WHERE id = ?', [$r['id']]); $r['applied'] = (int) $r['applied']; $r['verified'] = (int) $r['verified']; }
    return $rows;
}

// ---------------------------------------------------------------- flyer template

const FLYER_W = 1080;
const FLYER_H = 1350;

function flyer_defaults(): array
{
    return ['bg_color' => '#0f1b2d', 'text_color' => '#ffffff', 'accent_color' => '#6fb1f0', 'headline' => 'Shop {store} on DDLX', 'subline' => 'Scan the code to visit our store', 'footer' => 'ddlx.shop',
        'qr_pos' => 'center', 'qr_size' => 46, 'bg_file' => ''];
}

function flyer_template(): array
{
    $t = jdec((string) setting('flyer_template', ''), []);
    return array_merge(flyer_defaults(), is_array($t) ? $t : []);
}

function flyer_color(string $v, string $fallback): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', trim($v)) ? strtolower(trim($v)) : $fallback;
}

function flyer_save(array $admin, array $in, ?array $file, bool $removeBg): ?string
{
    $d = flyer_defaults();
    $t = flyer_template();
    foreach (['bg_color', 'text_color', 'accent_color'] as $k) $t[$k] = flyer_color((string) ($in[$k] ?? ''), $d[$k]);
    $t['headline'] = str_limit(trim((string) ($in['headline'] ?? '')), 80) ?: $d['headline'];
    $t['subline'] = str_limit(trim((string) ($in['subline'] ?? '')), 80);
    $t['footer'] = str_limit(trim((string) ($in['footer'] ?? '')), 60);
    $t['qr_pos'] = in_array($in['qr_pos'] ?? '', ['upper', 'center', 'lower'], true) ? $in['qr_pos'] : 'center';
    $t['qr_size'] = max(30, min(62, (int) ($in['qr_size'] ?? 46)));
    if ($removeBg && $t['bg_file'] !== '') { Storage::remove('promos', $t['bg_file']); $t['bg_file'] = ''; }
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $r = Storage::fromUpload($file, Storage::IMAGE_TYPES, PROMO_MAX_BYTES);
        if (is_string($r)) return $r;
        [$bytes, $mime] = $r;
        $name = 'flyer-' . uuid() . '.' . Storage::EXT[$mime];
        Storage::save('promos', $name, Storage::cleanImage($bytes, $mime, 1600));
        if ($t['bg_file'] !== '') Storage::remove('promos', $t['bg_file']);
        $t['bg_file'] = $name;
    }
    set_setting('flyer_template', jenc($t));
    audit($admin, 'settings', 'Updated the seller flyer template');
    return null;
}

/** The flyer as an SVG (1080 x 1350). $store and $url are the seller's own. */
function flyer_svg(array $t, string $store, string $url): string
{
    $W = FLYER_W; $H = FLYER_H;
    $bg = flyer_color((string) $t['bg_color'], '#0f1b2d'); $fg = flyer_color((string) $t['text_color'], '#ffffff'); $ac = flyer_color((string) $t['accent_color'], '#6fb1f0');
    $x = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" font-family="Arial, Helvetica, sans-serif">';
    $svg .= '<rect width="' . $W . '" height="' . $H . '" fill="' . $bg . '"/>';
    if (!empty($t['bg_file']) && preg_match(Storage::promoRe(), (string) $t['bg_file'])) {
        $bytes = Storage::read('promos', (string) $t['bg_file']);
        $mime = $bytes !== null ? Storage::sniff($bytes) : null;
        if ($bytes !== null && $mime && str_starts_with($mime, 'image/')) $svg .= '<image x="0" y="0" width="' . $W . '" height="' . $H . '" preserveAspectRatio="xMidYMid slice" href="data:' . $mime . ';base64,' . base64_encode($bytes) . '"/>';
    }
    // QR on a white plate.
    $size = (int) round($W * max(30, min(62, (int) $t['qr_size'])) / 100);
    $pad = 34; $plate = $size + 2 * $pad;
    $px = (int) (($W - $plate) / 2);
    $py = match ($t['qr_pos']) { 'upper' => 300, 'lower' => 640, default => 470 };
    $py = (int) min($py, $H - $plate - 190);
    $m = qr_matrix($url);
    $svg .= '<rect x="' . $px . '" y="' . $py . '" width="' . $plate . '" height="' . $plate . '" rx="36" fill="#ffffff"/>';
    if ($m) {
        $n = count($m); $cell = $size / $n; $d = '';
        foreach ($m as $r => $row) {
            $c = 0;
            while ($c < $n) {
                if (!$row[$c]) { $c++; continue; }
                $s = $c; while ($c < $n && $row[$c]) $c++;
                $d .= 'M' . round($px + $pad + $s * $cell, 2) . ' ' . round($py + $pad + $r * $cell, 2) . 'h' . round(($c - $s) * $cell, 2) . 'v' . round($cell, 2) . 'h-' . round(($c - $s) * $cell, 2) . 'z';
            }
        }
        $svg .= '<path fill="#000000" d="' . $d . '"/>';
    }
    // Text: the headline above the QR, the store name and line below, the footer at the bottom.
    $headline = str_replace('{store}', $store, (string) $t['headline']);
    $lines = flyer_wrap($headline, 20);
    $fs = count($lines) > 2 ? 64 : 78;
    $above = $py - 70 - (count($lines) - 1) * ($fs + 10);
    foreach ($lines as $i => $ln) $svg .= '<text x="' . ($W / 2) . '" y="' . ($above + $i * ($fs + 10)) . '" text-anchor="middle" font-size="' . $fs . '" font-weight="700" fill="' . $fg . '">' . $x($ln) . '</text>';
    $y = $py + $plate + 90;
    if (trim((string) $t['subline']) !== '') { $svg .= '<text x="' . ($W / 2) . '" y="' . $y . '" text-anchor="middle" font-size="40" fill="' . $ac . '">' . $x(str_replace('{store}', $store, (string) $t['subline'])) . '</text>'; $y += 70; }
    $svg .= '<text x="' . ($W / 2) . '" y="' . $y . '" text-anchor="middle" font-size="' . (mb_strlen($store) > 22 ? 44 : 56) . '" font-weight="700" fill="' . $fg . '">' . $x($store) . '</text>';
    if (trim((string) $t['footer']) !== '') $svg .= '<text x="' . ($W / 2) . '" y="' . ($H - 70) . '" text-anchor="middle" font-size="34" letter-spacing="3" fill="' . $fg . '" opacity="0.85">' . $x((string) $t['footer']) . '</text>';
    return $svg . '</svg>';
}

function flyer_wrap(string $s, int $width): array
{
    $total = mb_strlen(trim($s));
    if ($total > $width) $width = (int) ceil($total / ceil($total / $width)) + 3; // even lines, no lonely last word
    $words = preg_split('/\s+/', trim($s)) ?: [];
    $lines = []; $cur = '';
    foreach ($words as $w) {
        if ($cur !== '' && mb_strlen($cur . ' ' . $w) > $width) { $lines[] = $cur; $cur = $w; } else $cur = $cur === '' ? $w : $cur . ' ' . $w;
    }
    if ($cur !== '') $lines[] = $cur;
    return array_slice($lines ?: [''], 0, 4);
}

function flyer_for_seller(string $sellerId): string
{
    $share = share_row($sellerId);
    return flyer_svg(flyer_template(), inbox_store_name($sellerId) ?: 'our store', share_url($share['code']));
}

// ---------------------------------------------------------------- promo files

/** audience = ['mode' => all|levels|sellers, 'levels' => [], 'sellers' => []] */
function promo_audience(array $in): array
{
    $mode = in_array($in['mode'] ?? '', ['all', 'levels', 'sellers'], true) ? $in['mode'] : 'all';
    return ['mode' => $mode,
        'levels' => array_values(array_intersect(LEVELS, array_map('strval', (array) ($in['levels'] ?? [])))),
        'sellers' => array_values(array_filter(array_map('strval', (array) ($in['sellers'] ?? [])), 'is_uuid'))];
}

function promo_can(array $asset, string $sellerId): bool
{
    if ((int) $asset['active'] !== 1) return false;
    if (!is_verified_seller($sellerId)) return false;
    $a = promo_audience((array) jdec((string) $asset['audience'], []));
    return match ($a['mode']) { 'levels' => in_array(seller_level($sellerId), $a['levels'], true), 'sellers' => in_array($sellerId, $a['sellers'], true), default => true };
}

function promo_audience_label(array $a): string
{
    $a = promo_audience($a);
    return match ($a['mode']) {
        'levels' => $a['levels'] ? implode(', ', array_map(fn ($l) => LEVEL_LABEL[$l], $a['levels'])) . ' sellers' : 'No level chosen',
        'sellers' => count($a['sellers']) . ' chosen seller' . (count($a['sellers']) === 1 ? '' : 's'),
        default => 'All verified sellers',
    };
}

function promo_save(array $admin, array $in, ?array $file, bool $notify): ?string
{
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') return 'Give the file a title.';
    $aud = promo_audience($in);
    if ($aud['mode'] === 'levels' && !$aud['levels']) return 'Choose at least one level.';
    if ($aud['mode'] === 'sellers' && !$aud['sellers']) return 'Choose at least one seller.';
    $id = (string) ($in['id'] ?? '');
    $row = ['title' => str_limit($title, 120), 'description' => str_limit(trim((string) ($in['description'] ?? '')), 500), 'audience' => jenc($aud), 'active' => !empty($in['active']) ? 1 : 0];
    if ($id !== '') {
        if (!db()->val('SELECT 1 FROM promo_assets WHERE id = ?', [$id])) return 'File not found.';
        db()->exec('UPDATE promo_assets SET title = ?, description = ?, audience = ?, active = ? WHERE id = ?', [$row['title'], $row['description'], $row['audience'], $row['active'], $id]);
        audit($admin, 'settings', 'Updated the promo file "' . $row['title'] . '"');
        return null;
    }
    $r = Storage::fromUpload($file ?? ['error' => UPLOAD_ERR_NO_FILE], PROMO_TYPES, PROMO_MAX_BYTES);
    if (is_string($r)) return $r;
    [$bytes, $mime] = $r;
    if (str_starts_with($mime, 'image/')) $bytes = Storage::cleanImage($bytes, $mime, 3000);
    $id = uuid();
    $name = $id . '.' . Storage::EXT[$mime];
    Storage::save('promos', $name, $bytes);
    db()->insert('promo_assets', ['id' => $id] + $row + ['file' => $name, 'mime' => $mime, 'bytes' => strlen($bytes), 'created_by' => $admin['id'] ?? null, 'created_at' => now_db()]);
    audit($admin, 'settings', 'Uploaded the promo file "' . $row['title'] . '" for ' . promo_audience_label($aud));
    if ($notify && $row['active'] === 1) {
        $asset = db()->one('SELECT * FROM promo_assets WHERE id = ?', [$id]);
        foreach (db()->all("SELECT user_id FROM seller_verifications WHERE status = 'approved'") as $s) {
            if (promo_can($asset, $s['user_id'])) notify($s['user_id'], 'promo', 'New promo material: ' . $row['title'], 'You can download it from the Promo kit tab.', '/seller?tab=promo');
        }
    }
    return null;
}

function promo_delete(array $admin, string $id): void
{
    $a = db()->one('SELECT * FROM promo_assets WHERE id = ?', [$id]);
    if (!$a) return;
    Storage::remove('promos', $a['file']);
    db()->exec('DELETE FROM promo_downloads WHERE asset_id = ?', [$id]);
    db()->exec('DELETE FROM promo_assets WHERE id = ?', [$id]);
    audit($admin, 'settings', 'Deleted the promo file "' . $a['title'] . '"');
}

function promo_admin_list(): array
{
    $rows = db()->all('SELECT a.*, (SELECT COUNT(*) FROM promo_downloads d WHERE d.asset_id = a.id) AS downloads, (SELECT COUNT(DISTINCT d.user_id) FROM promo_downloads d WHERE d.asset_id = a.id) AS people FROM promo_assets a ORDER BY a.created_at DESC');
    foreach ($rows as &$r) { $r['aud'] = promo_audience((array) jdec((string) $r['audience'], [])); $r['aud_label'] = promo_audience_label($r['aud']); }
    return $rows;
}

function promo_for_seller(string $sellerId): array
{
    return array_values(array_filter(db()->all('SELECT * FROM promo_assets WHERE active = 1 ORDER BY created_at DESC'), fn ($a) => promo_can($a, $sellerId)));
}

function promo_download_log(string $assetId, string $userId): void
{
    db()->insert('promo_downloads', ['id' => uuid(), 'asset_id' => $assetId, 'user_id' => $userId, 'created_at' => now_db()]);
}

/** Verified sellers for the "chosen sellers" picker: id => store name. */
function promo_seller_options(): array
{
    $out = [];
    foreach (db()->all("SELECT v.user_id FROM seller_verifications v WHERE v.status = 'approved'") as $r) $out[$r['user_id']] = inbox_store_name($r['user_id']) ?: (string) db()->val('SELECT email FROM users WHERE id = ?', [$r['user_id']]);
    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function promo_kind_label(string $mime): string
{
    return match (true) { $mime === 'application/pdf' => 'PDF', $mime === 'video/mp4' => 'Video', default => 'Image' };
}

/** Admin: every verified seller with their code and scan count (optionally filtered). */
function share_admin_list(string $q = ''): array
{
    $rows = [];
    foreach (promo_seller_options() as $id => $name) {
        if ($q !== '' && stripos($name, $q) === false) continue;
        $s = share_row($id);
        $rows[] = ['id' => $id, 'name' => $name, 'code' => $s['code'], 'scans' => (int) $s['scans'], 'last' => $s['last_scan_at'], 'override' => (string) ($s['store_url'] ?? ''), 'target' => share_target($id)];
    }
    usort($rows, fn ($a, $b) => $b['scans'] <=> $a['scans'] ?: strcasecmp($a['name'], $b['name']));
    return array_slice($rows, 0, 100);
}
