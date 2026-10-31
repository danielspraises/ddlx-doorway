<?php
declare(strict_types=1);

// "Is this install healthy?" Checks the things that most often go wrong on a new shared host.
// Each row: [label, status ok|warn|fail, detail].

function ini_bytes(string $v): int
{
    $n = (int) $v;
    return match (strtolower(substr(trim($v), -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

function marketplace_probe(): array
{
    $url = rtrim((string) setting('marketplace_url', ''), '/');
    $key = (string) setting('marketplace_api_key', '');
    if ($url === '') return ['warn', 'Marketplace address is not set (Settings). SKUs will not be checked against the store.'];
    if (!function_exists('curl_init')) return ['fail', 'PHP curl is not enabled.'];
    $ch = curl_init($url . '/wp-json/vendor-hub/v1/sku/next');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['fail', 'Could not reach ' . $url . ': ' . $err];
    if ($code === 404) return ['fail', 'Reached the site but the Vendor Hub plugin is not active there (HTTP 404). Install and activate the plugin on WordPress.'];
    if ($code === 401 || $code === 403) return ['fail', 'The marketplace rejected the API key (HTTP ' . $code . '). Paste the key from Settings into WooCommerce → Vendor Hub.'];
    if ($code < 200 || $code >= 300) return ['fail', 'Unexpected answer from the marketplace (HTTP ' . $code . ').'];
    return ['ok', 'Connected. The marketplace accepted the API key.'];
}

function system_checks(bool $probeMarketplace = true): array
{
    $rows = [];
    $add = function (string $label, string $status, string $detail) use (&$rows) { $rows[] = [$label, $status, $detail]; };

    $add('PHP version', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail', PHP_VERSION . (version_compare(PHP_VERSION, '8.1.0', '>=') ? '' : ' (8.1 or newer is needed)'));
    foreach (['pdo_mysql' => true, 'curl' => true, 'mbstring' => true, 'fileinfo' => true, 'gd' => false, 'openssl' => true] as $ext => $required) {
        $on = extension_loaded($ext);
        if ($ext === 'pdo_mysql' && db()->driver !== 'mysql') { $add('Extension ' . $ext, 'warn', 'Not in use (database driver is ' . db()->driver . ').'); continue; }
        $add('Extension ' . $ext, $on ? 'ok' : ($required ? 'fail' : 'warn'), $on ? 'Enabled' : 'Missing. Tick it in cPanel → Select PHP Version.' . ($ext === 'gd' ? ' Needed to check and resize photos.' : ''));
    }

    try {
        $ver = (string) db()->val(db()->driver === 'mysql' ? 'SELECT VERSION()' : 'SELECT sqlite_version()');
        $add('Database', 'ok', db()->driver . ' ' . $ver);
        $t = now_db();
        $back = (string) db()->val('SELECT ?', [$t]);
        $add('Time values', $back === $t ? 'ok' : 'warn', 'Round trip ' . ($back === $t ? 'matches' : 'changed: ' . $t . ' became ' . $back));
        db()->tx(fn () => db()->val('SELECT 1' . db()->lock()));
        $add('Transactions and row locks', 'ok', 'Work');
        $tables = ['users', 'products', 'orders', 'order_items', 'wallet_entries', 'return_cases', 'notifications', 'settings'];
        $missing = array_filter($tables, function ($tb) { try { db()->val("SELECT COUNT(*) FROM $tb"); return false; } catch (Throwable) { return true; } });
        $add('Tables', $missing ? 'fail' : 'ok', $missing ? 'Missing: ' . implode(', ', $missing) : 'All present');
    } catch (Throwable $e) {
        $add('Database', 'fail', $e->getMessage());
    }

    foreach (['config', 'storage', 'storage/logs', 'storage/products', 'storage/verification'] as $d) {
        $p = APP_ROOT . '/' . $d;
        if (!is_dir($p)) { $add('Folder ' . $d, $d === 'config' ? 'warn' : 'warn', 'Does not exist yet. It is created on first use.'); continue; }
        $add('Folder ' . $d, is_writable($p) ? 'ok' : 'fail', is_writable($p) ? 'Writable' : 'Not writable. Set permissions to 755 (or 775) in cPanel File Manager.');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $add('HTTPS', $https ? 'ok' : 'fail', $https ? 'This page is served over HTTPS' : 'Not HTTPS. Turn on AutoSSL for the subdomain; sign-in cookies are only safe over HTTPS.');
    $app = (string) setting('app_url', '');
    $add('Vendor Hub address', $app === '' ? 'warn' : (str_starts_with($app, 'https://') ? 'ok' : 'warn'), $app === '' ? 'Not set in Settings. Emails will not contain links.' : $app);

    $up = min(ini_bytes((string) ini_get('upload_max_filesize')), ini_bytes((string) ini_get('post_max_size')));
    $add('Upload size limit', $up >= 8 * 1048576 ? 'ok' : 'warn', round($up / 1048576, 1) . ' MB per file. Product photos and verification documents can be several MB; 8 MB or more is comfortable.');
    $add('Email', (string) setting('smtp_host', '') !== '' ? ((string) setting('mail_from', '') !== '' ? 'ok' : 'warn') : (function_exists('mail') && (string) setting('mail_from', '') !== '' ? 'ok' : 'warn'), (string) setting('mail_from', '') === '' ? 'No sender address set in Settings. Email alerts and password resets need one.' : 'Sender ' . setting('mail_from', '') . ((string) setting('smtp_host', '') !== '' ? ' via ' . setting('smtp_host', '') : ' (host mail)'));

    $add('API key and webhook secret', (setting('marketplace_api_key', '') !== '' && setting('marketplace_webhook_secret', '') !== '') ? 'ok' : 'fail', (setting('marketplace_api_key', '') !== '' && setting('marketplace_webhook_secret', '') !== '') ? 'Both are set' : 'Missing. Create them in Settings.');
    $last = db()->val("SELECT MAX(created_at) FROM orders");
    $add('Orders received from the marketplace', $last ? 'ok' : 'warn', $last ? 'Latest order ' . human_date((string) $last, true) : 'None yet. Place a test order on the marketplace to confirm sales arrive.');

    if ($probeMarketplace) { [$s, $d] = marketplace_probe(); $add('Marketplace connection', $s, $d); }
    return $rows;
}
