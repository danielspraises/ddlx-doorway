<?php
declare(strict_types=1);

// Sign-in, sessions, roles and the seller's stage.

const SESSION_COOKIE = 'vh_session';
const GUEST_COOKIE = 'vh_guest';
const SESSION_DAYS = 14;

function cookie_opts(int $expires): array
{
    return ['expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()];
}

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

function password_ok(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (strlen($password) > 128) {
        return 'Password is too long.';
    }
    return null;
}

function create_session(string $userId): void
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expires = db_plus('+' . SESSION_DAYS . ' days');
    db()->exec('DELETE FROM sessions WHERE expires_at < ?', [now_db()]);
    db()->insert('sessions', ['token_hash' => token_hash($token), 'user_id' => $userId, 'csrf' => bin2hex(random_bytes(20)), 'expires_at' => $expires, 'created_at' => now_db()]);
    setcookie(SESSION_COOKIE, $token, cookie_opts(time() + SESSION_DAYS * 86400));
    $_COOKIE[SESSION_COOKIE] = $token;
    unset($GLOBALS['vh_user_cache']);
}

function destroy_session(): void
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if (is_string($token) && $token !== '') {
        db()->exec('DELETE FROM sessions WHERE token_hash = ?', [token_hash($token)]);
    }
    setcookie(SESSION_COOKIE, '', cookie_opts(time() - 3600));
    unset($_COOKIE[SESSION_COOKIE], $GLOBALS['vh_user_cache']);
}

// Loads the signed-in person (or null). Cached for the request.
function current_user(): ?array
{
    if (array_key_exists('vh_user_cache', $GLOBALS)) {
        return $GLOBALS['vh_user_cache'];
    }
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    $user = null;
    if (is_string($token) && strlen($token) > 20) {
        $user = user_from_token($token);
    }
    return $GLOBALS['vh_user_cache'] = $user;
}

function seller_stage(?string $application, ?string $verification): string
{
    if (!$application) return 'no_application';
    if ($application === 'draft') return 'application_draft';
    if ($application === 'submitted') return 'application_review';
    if ($application === 'needs_corrections') return 'application_corrections';
    if ($application === 'rejected') return 'application_rejected';
    if (!$verification) return 'verification_needed';
    if ($verification === 'draft') return 'verification_draft';
    if ($verification === 'submitted') return 'verification_review';
    if ($verification === 'needs_corrections') return 'verification_corrections';
    if ($verification === 'rejected') return 'verification_rejected';
    return 'verified';
}

const STAGE_LABEL = [
    'no_application' => 'Not started',
    'application_draft' => 'Application in progress',
    'application_review' => 'Application under review',
    'application_corrections' => 'Application needs corrections',
    'application_rejected' => 'Application not approved',
    'verification_needed' => 'Ready for verification',
    'verification_draft' => 'Verification in progress',
    'verification_review' => 'Verification under review',
    'verification_corrections' => 'Verification needs corrections',
    'verification_rejected' => 'Verification not approved',
    'verified' => 'Verified seller',
];

function user_from_token(string $token): ?array
{
    $db = db();
    $row = $db->one(
        'SELECT s.csrf, u.id, u.email,
           (SELECT COUNT(*) FROM user_roles r WHERE r.user_id = u.id AND r.role = \'admin\') AS is_admin,
           sm.permissions AS permissions, sm.active AS staff_active,
           a.status AS application_status, v.status AS verification_status
         FROM sessions s JOIN users u ON u.id = s.user_id
         LEFT JOIN staff_members sm ON sm.user_id = u.id AND sm.active = 1
         LEFT JOIN seller_applications a ON a.user_id = u.id
         LEFT JOIN seller_verifications v ON v.user_id = u.id
         WHERE s.token_hash = ? AND s.expires_at > ?',
        [token_hash($token), now_db()]
    );
    if (!$row) {
        return null;
    }
    $perms = array_values(array_filter(explode(',', (string) ($row['permissions'] ?? ''))));
    $isAdmin = (int) $row['is_admin'] > 0;
    $canReview = $isAdmin || in_array('products', $perms, true);
    $canOrders = $isAdmin || in_array('orders', $perms, true);
    $pending = ['applications' => 0, 'verifications' => 0, 'products' => 0, 'payouts' => 0, 'orders' => 0];
    if ($canOrders) {
        $pending['orders'] = (int) $db->val("SELECT COUNT(DISTINCT order_id) FROM order_items WHERE status = 'paid' AND fulfilment = 'ready_for_shipping' AND order_id NOT IN (SELECT order_id FROM order_items WHERE status = 'paid' AND fulfilment IN ('placed', 'processing'))");
    }
    if ($canReview) {
        $pending['products'] = (int) $db->val("SELECT COUNT(*) FROM products WHERE status = 'pending_review'");
    }
    if ($isAdmin) {
        $pending['applications'] = (int) $db->val("SELECT COUNT(*) FROM seller_applications WHERE status = 'submitted'");
        $pending['verifications'] = (int) $db->val("SELECT COUNT(*) FROM seller_verifications WHERE status = 'submitted'");
        $pending['payouts'] = (int) $db->val("SELECT COUNT(*) FROM withdrawals WHERE status = 'requested'");
    }
    $GLOBALS['vh_csrf'] = $row['csrf'];
    return [
        'id' => $row['id'],
        'email' => $row['email'],
        'isAdmin' => $isAdmin,
        'isStaff' => count($perms) > 0,
        'canReview' => $canReview,
        'canOrders' => $canOrders,
        'stage' => seller_stage($row['application_status'], $row['verification_status']),
        'pending' => $pending,
    ];
}

// Where a person should land after signing in or finishing an action.
function home_path(array $user): string
{
    if ($user['isAdmin'] || $user['canReview'] || !empty($user['canOrders'])) return '/admin';
    if ($user['stage'] === 'verified') return '/seller';
    if (str_starts_with($user['stage'], 'verification_')) return '/verification';
    return '/apply';
}

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            json_out(['error' => 'Sign in first.'], 401);
        }
        redirect('/auth');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_user();
    if (!$u['isAdmin']) abort(403, 'Only an administrator can do this.');
    return $u;
}

function require_reviewer(): array
{
    $u = require_user();
    if (!$u['canReview']) abort(403, 'You do not have permission to review products.');
    return $u;
}

function require_orders(): array
{
    $u = require_user();
    if (!$u['canOrders']) abort(403, 'You do not have permission to manage orders.');
    return $u;
}

function require_seller(): array
{
    $u = require_user();
    if ($u['stage'] !== 'verified') redirect(home_path($u));
    return $u;
}

// ---------------------------------------------------------------- CSRF

function csrf_boot(): void
{
    $u = current_user();
    if ($u) {
        return; // vh_csrf was set while loading the session
    }
    $g = $_COOKIE[GUEST_COOKIE] ?? '';
    if (!is_string($g) || !preg_match('/^[a-f0-9]{40}$/', $g)) {
        $g = bin2hex(random_bytes(20));
        setcookie(GUEST_COOKIE, $g, cookie_opts(time() + 86400 * 30));
        $_COOKIE[GUEST_COOKIE] = $g;
    }
    $GLOBALS['vh_csrf'] = $g;
}

function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        abort(403, 'Your session expired. Go back, refresh the page and try again.');
    }
}

// ---------------------------------------------------------------- accounts

function register_user(string $email, string $password): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }
    if ($err = password_ok($password)) {
        return ['ok' => false, 'error' => $err];
    }
    if (strtolower((string) cfg('admin_email', '')) === $email) {
        return ['ok' => false, 'error' => 'This email is reserved. Choose another one.'];
    }
    $id = uuid();
    try {
        db()->insert('users', ['id' => $id, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => now_db()]);
    } catch (Throwable $e) {
        if (Db::isDuplicate($e)) {
            return ['ok' => false, 'error' => 'An account with this email already exists.'];
        }
        throw $e;
    }
    create_session($id);
    return ['ok' => true, 'id' => $id];
}

function login_blocked(string $email): bool
{
    $since = db_plus('-15 minutes');
    $byEmail = (int) db()->val('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at > ?', [$email, $since]);
    $byIp = (int) db()->val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?', [client_ip(), $since]);
    return $byEmail >= 6 || $byIp >= 30;
}

function attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    if (login_blocked($email)) {
        return ['ok' => false, 'error' => 'Too many attempts. Wait 15 minutes, or reset your password.'];
    }
    $u = db()->one('SELECT id, password_hash FROM users WHERE email = ?', [$email]);
    static $dummy = null;
    $hash = $u['password_hash'] ?? ($dummy ??= password_hash('not-a-real-password', PASSWORD_DEFAULT));
    $valid = password_verify($password, $hash);
    if (!$u || !$valid) {
        db()->insert('login_attempts', ['email' => $email, 'ip' => client_ip(), 'created_at' => now_db()]);
        return ['ok' => false, 'error' => 'Invalid email or password.'];
    }
    db()->exec('DELETE FROM login_attempts WHERE email = ?', [$email]);
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        db()->exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    create_session($u['id']);
    return ['ok' => true];
}

// ---------------------------------------------------------------- live updates (polling)

function announce(string $audience, string $type, string $message = '', ?string $sellerId = null): void
{
    try {
        db()->insert('events', ['audience' => $audience, 'seller_id' => $sellerId, 'type' => $type, 'message' => str_limit($message, 250), 'created_at' => now_db()]);
        if (random_int(1, 40) === 1) {
            db()->exec('DELETE FROM events WHERE created_at < ?', [db_plus('-3 days')]);
        }
    } catch (Throwable $e) {
        log_error('announce failed: ' . $e->getMessage());
    }
}
