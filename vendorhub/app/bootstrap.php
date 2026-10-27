<?php
declare(strict_types=1);

require __DIR__ . '/core.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/storage.php';
require __DIR__ . '/mail.php';

foreach (glob(__DIR__ . '/domain/*.php') ?: [] as $f) {
    require $f;
}

// ---------------------------------------------------------------- routing

function route(string $method, string $path, callable $handler, array $opts = []): void
{
    $GLOBALS['vh_routes'][$method][$path] = [$handler, $opts];
}

function nav_items(array $u): array
{
    $p = $u['pending'];
    if ($u['isAdmin']) {
        return [
            ['Applications', '/admin?tab=applications', $p['applications']],
            ['Verifications', '/admin?tab=verifications', $p['verifications']],
            ['Products', '/admin?tab=products', $p['products']],
            ['Payouts', '/admin?tab=payouts', $p['payouts']],
            ['Categories', '/admin?tab=categories', 0],
            ['Staff', '/admin?tab=staff', 0],
            ['Settings', '/admin?tab=settings', 0],
        ];
    }
    if ($u['canReview']) {
        return [['Product review', '/admin?tab=products', $p['products']]];
    }
    $s = $u['stage'];
    $items = [['Application', '/apply', 0]];
    if (str_starts_with($s, 'verification_') || $s === 'verified') {
        $items[] = ['Verification', '/verification', 0];
    }
    if ($s === 'verified') {
        array_push($items,
            ['Seller Center', '/seller?tab=overview', 0],
            ['Products', '/seller?tab=products', 0],
            ['Inventory', '/seller?tab=inventory', 0],
            ['Sales', '/seller?tab=sales', 0],
            ['Payouts', '/seller?tab=payouts', 0],
            ['Store', '/seller?tab=store', 0],
        );
    }
    return $items;
}

function vh_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function vh_run(): void
{
    ob_start();
    vh_security_headers();
    $file = APP_ROOT . '/config/config.php';
    $GLOBALS['vh_config'] = is_file($file) ? (require $file) : [];
    $installed = !empty($GLOBALS['vh_config']['db']);

    set_exception_handler(function (Throwable $e) {
        log_error(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code(500);
        $detail = cfg('debug', false) ? $e->getMessage() : 'Something went wrong on our side. Please try again in a moment.';
        if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            header('Content-Type: application/json');
            echo json_encode(['error' => $detail]);
            return;
        }
        try {
            render('error', ['title' => 'Something went wrong', 'message' => $detail, 'status' => 500], 'bare');
        } catch (Throwable) {
            echo 'Something went wrong.';
        }
    });

    foreach (glob(__DIR__ . '/routes/*.php') ?: [] as $f) {
        require $f;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $match = $GLOBALS['vh_routes'][$method][$path] ?? null;

    if (!$installed) {
        if (!str_starts_with($path, '/install')) {
            redirect('/install');
        }
    } else {
        date_default_timezone_set('UTC');
        if (str_starts_with($path, '/install') && is_file(APP_ROOT . '/config/installed.lock')) {
            abort(404);
        }
    }
    if (!$match) {
        abort(404);
    }
    [$handler, $opts] = $match;

    if ($installed) {
        $isApi = !empty($opts['api']);
        csrf_boot();
        if ($method === 'POST' && !$isApi) {
            csrf_verify();
        }
        $u = current_user();
        if ($u === null && empty($GLOBALS['vh_csrf'])) {
            csrf_boot();
        }
    } else {
        $GLOBALS['vh_csrf'] = '';
    }
    $handler();
}
