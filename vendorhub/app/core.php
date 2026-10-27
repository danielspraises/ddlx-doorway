<?php
declare(strict_types=1);

// Small helpers used everywhere. No framework: plain PHP 8 so it runs on ordinary cPanel hosting.

define('APP_ROOT', dirname(__DIR__));

function cfg(string $key, mixed $default = null): mixed
{
    return $GLOBALS['vh_config'][$key] ?? $default;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------- time (everything is stored in UTC)

function now_db(): string
{
    $t = microtime(true);
    return gmdate('Y-m-d H:i:s', (int) $t) . '.' . str_pad((string) (int) (($t - floor($t)) * 1000), 3, '0', STR_PAD_LEFT);
}

function db_plus(string $modify, ?string $from = null): string
{
    $d = new DateTimeImmutable($from ?? 'now', new DateTimeZone('UTC'));
    return $d->modify($modify)->format('Y-m-d H:i:s.v');
}

// Database time -> ISO 8601 (what browsers and the marketplace understand).
function iso(?string $db): ?string
{
    if ($db === null || $db === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($db, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    } catch (Throwable) {
        return null;
    }
}

// ISO 8601 from a browser or the marketplace -> database time, or null when empty or invalid.
function from_iso(?string $iso): ?string
{
    $iso = trim((string) $iso);
    if ($iso === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    } catch (Throwable) {
        return null;
    }
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

const UUID_RE = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

function is_uuid(mixed $v): bool
{
    return is_string($v) && preg_match('/^' . UUID_RE . '$/', $v) === 1;
}

// ---------------------------------------------------------------- JSON columns

function jenc(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '[]';
}

function jdec(?string $s, mixed $default = []): mixed
{
    if ($s === null || $s === '') {
        return $default;
    }
    $v = json_decode($s, true);
    return $v === null ? $default : $v;
}

// ---------------------------------------------------------------- requests and responses

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function input_raw(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? $v : '';
}

function base_url(): string
{
    $configured = (string) setting('app_url', cfg('app_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function redirect(string $to, ?string $message = null, string $kind = 'ok'): never
{
    if ($message !== null) {
        flash($message, $kind);
    }
    header('Location: ' . $to, true, 303);
    exit;
}

function flash(string $message, string $kind = 'ok'): void
{
    setcookie('vh_flash', rawurlencode(jenc(['m' => $message, 'k' => $kind])), ['expires' => time() + 60, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
}

function take_flash(): ?array
{
    if (empty($_COOKIE['vh_flash'])) {
        return null;
    }
    $f = json_decode(rawurldecode((string) $_COOKIE['vh_flash']), true);
    setcookie('vh_flash', '', ['expires' => time() - 3600, 'path' => '/']);
    unset($_COOKIE['vh_flash']);
    return is_array($f) && isset($f['m']) ? $f : null;
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function abort(int $status, string $message = ''): never
{
    http_response_code($status);
    $titles = [403 => 'Not allowed', 404 => 'Page not found', 405 => 'Method not allowed', 429 => 'Too many attempts', 500 => 'Something went wrong'];
    $title = $titles[$status] ?? 'Error';
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        json_out(['error' => $message ?: $title], $status);
    }
    render('error', ['title' => $title, 'message' => $message ?: $title, 'status' => $status], 'bare');
    exit;
}

// ---------------------------------------------------------------- views

function render(string $view, array $vars = [], string $layout = 'layout'): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_ROOT . '/app/views/' . $view . '.php';
    $content = (string) ob_get_clean();
    if ($layout === 'none') {
        echo $content;
        return;
    }
    $pageTitle = $title ?? 'Vendor Hub';
    require APP_ROOT . '/app/views/' . $layout . '.php';
}

function csrf_token(): string
{
    return (string) ($GLOBALS['vh_csrf'] ?? '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

// ---------------------------------------------------------------- formatting

const CURRENCY_SYMBOLS = ['NGN' => '₦', 'USD' => '$', 'GBP' => '£', 'EUR' => '€'];
const CURRENCIES = ['NGN', 'USD', 'GBP', 'EUR'];

function money(float|int|string|null $amount, string $currency = 'NGN'): string
{
    $n = (float) $amount;
    $symbol = CURRENCY_SYMBOLS[$currency] ?? ($currency . ' ');
    return $symbol . number_format($n, 2);
}

function human_date(?string $db, bool $time = false): string
{
    $i = iso($db);
    if (!$i) {
        return '—';
    }
    $d = new DateTimeImmutable($i);
    $d = $d->setTimezone(new DateTimeZone('Africa/Lagos'));
    return $d->format($time ? 'j M Y, g:i a' : 'j M Y');
}

function cents(mixed $v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    return (int) round(((float) $v) * 100);
}

function slugify(string $text): string
{
    $t = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);
    $t = trim((string) preg_replace('/[^a-z0-9]+/', '-', $t), '-');
    return substr($t, 0, 60) ?: 'product';
}

function str_limit(string $s, int $n): string
{
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n) : $s;
}

// ---------------------------------------------------------------- settings stored in the database

function setting(string $key, mixed $default = null): mixed
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->all('SELECT k, v FROM settings') as $r) {
                $cache[$r['k']] = $r['v'];
            }
        } catch (Throwable) {
            return $default;
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    $db = db();
    $db->tx(function () use ($db, $key, $value) {
        $n = $db->exec('UPDATE settings SET v = ? WHERE k = ?', [$value, $key]);
        if ($n === 0 && !$db->val('SELECT 1 FROM settings WHERE k = ?', [$key])) {
            $db->exec('INSERT INTO settings (k, v) VALUES (?, ?)', [$key, $value]);
        }
    });
}

function commission_percent(): float
{
    $v = (float) setting('commission_percent', 10);
    return $v >= 0 && $v <= 100 ? $v : 10.0;
}

function hold_days(): int
{
    $v = (int) setting('payout_hold_days', 7);
    return $v >= 0 && $v <= 90 ? $v : 7;
}

function min_withdrawal(): float
{
    $v = (float) setting('min_withdrawal', 1000);
    return $v > 0 ? $v : 1000.0;
}

function withdrawal_fee(): float
{
    $v = (float) setting('withdrawal_fee', 0);
    return $v >= 0 ? $v : 0.0;
}

function log_error(string $message): void
{
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/app.log', gmdate('c') . ' ' . $message . "\n", FILE_APPEND);
}
