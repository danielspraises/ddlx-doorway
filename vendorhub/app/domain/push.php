<?php
declare(strict_types=1);

// Push notifications to the phone app, through Firebase Cloud Messaging (HTTP v1).
// The admin pastes a Firebase service-account key once; after that every notification
// a person gets in Vendor Hub is also sent to their signed-in phones. Failures never break a page.

function push_account(): ?array
{
    $raw = (string) setting('fcm_service_account', '');
    $d = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($d) && !empty($d['project_id']) && !empty($d['client_email']) && !empty($d['private_key']) ? $d : null;
}

function push_configured(): bool
{
    return push_account() !== null;
}

function save_push_settings(array $in): ?string
{
    if (($in['push_clear'] ?? '') === 'yes') { set_setting('fcm_service_account', ''); set_setting('fcm_token', ''); return null; }
    $raw = trim((string) ($in['fcm_service_account'] ?? ''));
    if ($raw === '') return null; // keep what is saved
    $d = json_decode($raw, true);
    if (!is_array($d) || ($d['type'] ?? '') !== 'service_account' || empty($d['project_id']) || empty($d['client_email']) || empty($d['private_key'])) {
        return 'That is not a Firebase service-account key. In Firebase: Project settings → Service accounts → Generate new private key, then paste the whole file here.';
    }
    if (!@openssl_pkey_get_private((string) $d['private_key'])) return 'The private key inside that file could not be read. Download a fresh key and paste it again.';
    set_setting('fcm_service_account', json_encode(['type' => 'service_account', 'project_id' => $d['project_id'], 'client_email' => $d['client_email'], 'private_key' => $d['private_key']]));
    set_setting('fcm_token', '');
    return null;
}

function push_register_device(string $userId, string $token, string $platform): ?string
{
    $token = trim($token);
    if (strlen($token) < 20 || strlen($token) > 255 || preg_match('/\s/', $token)) return 'Invalid device token.';
    $platform = in_array($platform, ['android', 'ios'], true) ? $platform : 'android';
    $db = db();
    $db->tx(function () use ($db, $userId, $token, $platform) {
        $row = $db->one('SELECT id FROM push_devices WHERE token = ?', [$token]);
        if ($row) $db->exec('UPDATE push_devices SET user_id = ?, platform = ?, last_seen_at = ? WHERE id = ?', [$userId, $platform, now_db(), $row['id']]);
        else $db->insert('push_devices', ['id' => uuid(), 'user_id' => $userId, 'token' => $token, 'platform' => $platform, 'created_at' => now_db(), 'last_seen_at' => now_db()]);
    });
    return null;
}

function push_unregister_device(string $userId, string $token): void
{
    db()->exec('DELETE FROM push_devices WHERE user_id = ? AND token = ?', [$userId, $token]);
}

function push_device_count(string $userId): int
{
    return (int) db()->val('SELECT COUNT(*) FROM push_devices WHERE user_id = ?', [$userId]);
}

/** Who a staff announcement is meant for: admins always, plus staff with the matching permission. */
function push_audience_users(string $audience): array
{
    $db = db();
    $ids = array_column($db->all("SELECT user_id FROM user_roles WHERE role = 'admin'"), 'user_id');
    $perm = ['reviewers' => 'products', 'orders' => 'orders', 'messages' => 'messages'][$audience] ?? null;
    if ($perm) {
        foreach ($db->all('SELECT user_id, permissions FROM staff_members WHERE active = 1') as $s) {
            if (in_array($perm, array_map('trim', explode(',', (string) $s['permissions'])), true)) $ids[] = $s['user_id'];
        }
    }
    return array_values(array_unique($ids));
}

function push_http(string $url, array $headers, string $body): array
{
    if (!empty($GLOBALS['vh_push_stub'])) return ($GLOBALS['vh_push_stub'])($url, $headers, $body);
    if (defined('VH_TESTING') || !function_exists('curl_init')) return [0, ''];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$res === false ? 0 : $code, $res === false ? '' : (string) $res];
}

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** A Google access token (cached for most of an hour). */
function push_access_token(): ?string
{
    $acc = push_account();
    if (!$acc) return null;
    $cached = json_decode((string) setting('fcm_token', ''), true);
    if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 120 && !empty($cached['t'])) return (string) $cached['t'];
    $now = time();
    $head = b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = b64url(json_encode(['iss' => $acc['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    $sig = '';
    if (!@openssl_sign($head . '.' . $claims, $sig, $acc['private_key'], OPENSSL_ALGO_SHA256)) return null;
    [$code, $res] = push_http('https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $head . '.' . $claims . '.' . b64url($sig)]));
    $d = json_decode($res, true);
    if ($code !== 200 || empty($d['access_token'])) { log_error('push token failed: ' . $code . ' ' . substr($res, 0, 200)); return null; }
    set_setting('fcm_token', json_encode(['t' => $d['access_token'], 'exp' => $now + (int) ($d['expires_in'] ?? 3000)]));
    return (string) $d['access_token'];
}

/** Sends one notification to every phone this person has signed in on. Returns how many accepted it. */
function push_send_user(string $userId, string $title, string $body = '', string $link = ''): int
{
    $acc = push_account();
    if (!$acc) return 0;
    $devices = db()->all('SELECT id, token FROM push_devices WHERE user_id = ?', [$userId]);
    if (!$devices) return 0;
    $access = push_access_token();
    if (!$access) return 0;
    $unread = (int) notifications_unread($userId);
    $sent = 0;
    foreach ($devices as $d) {
        $msg = ['message' => ['token' => $d['token'], 'notification' => ['title' => mb_substr($title, 0, 100), 'body' => mb_substr($body !== '' ? $body : 'Open Vendor Hub to see it.', 0, 200)],
            'data' => ['link' => $link !== '' && $link[0] === '/' ? $link : '/notifications'], 'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => 'default', 'sound' => 'default', 'notification_count' => max(1, $unread)]]]];
        [$code, $res] = push_http('https://fcm.googleapis.com/v1/projects/' . rawurlencode($acc['project_id']) . '/messages:send', ['Authorization: Bearer ' . $access, 'Content-Type: application/json'], json_encode($msg));
        if ($code === 200) { $sent++; continue; }
        if ($code === 404 || ($code === 400 && str_contains($res, 'INVALID_ARGUMENT')) || str_contains($res, 'UNREGISTERED')) db()->exec('DELETE FROM push_devices WHERE id = ?', [$d['id']]); // the phone no longer has the app
        else log_error('push failed: ' . $code . ' ' . substr($res, 0, 200));
    }
    return $sent;
}

/** Sends after the page has been delivered, so nobody waits for Google. */
function push_defer(array $userIds, string $title, string $body = '', string $link = ''): void
{
    if (!$userIds || !push_configured()) return;
    if (!empty($GLOBALS['vh_push_stub'])) { foreach ($userIds as $u) push_send_user($u, $title, $body, $link); return; }
    if (defined('VH_TESTING')) return;
    static $queue = [], $armed = false;
    $queue[] = [$userIds, $title, $body, $link];
    if ($armed) return;
    $armed = true;
    register_shutdown_function(function () use (&$queue) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @set_time_limit(30);
        foreach ($queue as [$ids, $t, $b, $l]) foreach ($ids as $u) { try { push_send_user($u, $t, $b, $l); } catch (Throwable $e) { log_error('push: ' . $e->getMessage()); } }
    });
}
