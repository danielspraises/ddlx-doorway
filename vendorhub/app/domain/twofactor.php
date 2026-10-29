<?php
declare(strict_types=1);

// Two-step sign-in with an authenticator app (TOTP, RFC 6238) and one-time backup codes.

const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function b32_encode(string $bin): string
{
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= B32[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function b32_decode(string $s): string
{
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s) ?? '');
    $bits = '';
    foreach (str_split($s) as $c) $bits .= str_pad(decbin((int) strpos(B32, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_at(string $secretB32, int $step): string
{
    $h = hash_hmac('sha1', pack('N2', 0, $step), b32_decode($secretB32), true);
    $o = ord($h[19]) & 15;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

// Returns the matched time step (so a code cannot be used twice), or null.
function totp_check(string $secretB32, string $code, int $lastStep = 0, ?int $now = null): ?int
{
    $code = preg_replace('/\s+/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) return null;
    $cur = intdiv($now ?? time(), 30);
    for ($s = $cur - 1; $s <= $cur + 1; $s++) {
        if ($s > $lastStep && hash_equals(totp_at($secretB32, $s), $code)) return $s;
    }
    return null;
}

function totp_uri(string $email, string $secret): string
{
    return 'otpauth://totp/' . rawurlencode('DDLX Vendor Hub:' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode('DDLX Vendor Hub') . '&digits=6&period=30';
}

function twofa_row(string $userId): array
{
    return db()->one('SELECT totp_secret, totp_enabled, totp_last_step, totp_recovery FROM users WHERE id = ?', [$userId]) ?? [];
}

function twofa_enabled(string $userId): bool
{
    return (int) (twofa_row($userId)['totp_enabled'] ?? 0) === 1;
}

// A new secret that is not active until the person proves their app works.
function twofa_begin(string $userId): string
{
    $secret = b32_encode(random_bytes(20));
    db()->exec('UPDATE users SET totp_secret = ?, totp_enabled = 0, totp_last_step = 0, totp_recovery = NULL WHERE id = ? AND totp_enabled = 0', [$secret, $userId]);
    return (string) twofa_row($userId)['totp_secret'];
}

function recovery_codes_make(): array
{
    $plain = [];
    for ($i = 0; $i < 8; $i++) $plain[] = strtolower(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
    return $plain;
}

// Returns ['ok'=>bool,'error'=>?string,'codes'=>array] — codes are shown once.
function twofa_enable(string $userId, string $code): array
{
    $r = twofa_row($userId);
    if (!$r || empty($r['totp_secret']) || (int) $r['totp_enabled'] === 1) return ['ok' => false, 'error' => 'Start the setup again.', 'codes' => []];
    $step = totp_check((string) $r['totp_secret'], $code);
    if ($step === null) return ['ok' => false, 'error' => 'That code did not match. Check the time on your phone and try the next code.', 'codes' => []];
    $codes = recovery_codes_make();
    db()->exec('UPDATE users SET totp_enabled = 1, totp_last_step = ?, totp_recovery = ? WHERE id = ?', [$step, jenc(array_map(fn ($c) => hash('sha256', $c), $codes)), $userId]);
    audit(['id' => $userId, 'email' => (string) db()->val('SELECT email FROM users WHERE id = ?', [$userId])], 'staff', 'Turned on two-step sign-in', $userId);
    return ['ok' => true, 'error' => null, 'codes' => $codes];
}

function twofa_disable(string $userId, string $password): ?string
{
    $hash = (string) db()->val('SELECT password_hash FROM users WHERE id = ?', [$userId]);
    if (!password_verify($password, $hash)) return 'Password is not correct.';
    db()->exec('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0, totp_recovery = NULL WHERE id = ?', [$userId]);
    audit(['id' => $userId, 'email' => (string) db()->val('SELECT email FROM users WHERE id = ?', [$userId])], 'staff', 'Turned off two-step sign-in', $userId);
    return null;
}

function twofa_new_recovery(string $userId, string $password): array
{
    $hash = (string) db()->val('SELECT password_hash FROM users WHERE id = ?', [$userId]);
    if (!password_verify($password, $hash) || !twofa_enabled($userId)) return ['ok' => false, 'codes' => []];
    $codes = recovery_codes_make();
    db()->exec('UPDATE users SET totp_recovery = ? WHERE id = ?', [jenc(array_map(fn ($c) => hash('sha256', $c), $codes)), $userId]);
    return ['ok' => true, 'codes' => $codes];
}

// Accepts an app code or an unused backup code.
function twofa_verify(string $userId, string $input): bool
{
    $r = twofa_row($userId);
    if (!$r || (int) ($r['totp_enabled'] ?? 0) !== 1) return false;
    $input = strtolower(trim($input));
    if ($step = totp_check((string) $r['totp_secret'], $input, (int) $r['totp_last_step'])) {
        return db()->exec('UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?', [$step, $userId, $step]) === 1;
    }
    $codes = jdec((string) $r['totp_recovery'], []);
    $h = hash('sha256', $input);
    $i = array_search($h, $codes, true);
    if ($i === false) return false;
    unset($codes[$i]);
    return db()->exec('UPDATE users SET totp_recovery = ? WHERE id = ? AND totp_recovery = ?', [jenc(array_values($codes)), $userId, $r['totp_recovery']]) === 1;
}

function twofa_codes_left(string $userId): int
{
    return count(jdec((string) (twofa_row($userId)['totp_recovery'] ?? ''), []));
}

// ---- the step between password and session

function login_challenge_make(string $userId): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    db()->exec('DELETE FROM login_challenges WHERE expires_at < ?', [now_db()]);
    db()->insert('login_challenges', ['token_hash' => token_hash($token), 'user_id' => $userId, 'tries' => 0, 'expires_at' => db_plus('+10 minutes'), 'created_at' => now_db()]);
    return $token;
}

// Returns ['ok'=>bool,'error'=>?string]; on success the session is created.
function login_challenge_finish(string $token, string $code): array
{
    $h = token_hash($token);
    $c = db()->one('SELECT user_id, tries FROM login_challenges WHERE token_hash = ? AND expires_at > ?', [$h, now_db()]);
    if (!$c) return ['ok' => false, 'error' => 'That sign-in expired. Start again.', 'restart' => true];
    if ((int) $c['tries'] >= 5) { db()->exec('DELETE FROM login_challenges WHERE token_hash = ?', [$h]); return ['ok' => false, 'error' => 'Too many wrong codes. Sign in again.', 'restart' => true]; }
    if (!twofa_verify($c['user_id'], $code)) {
        db()->exec('UPDATE login_challenges SET tries = tries + 1 WHERE token_hash = ?', [$h]);
        return ['ok' => false, 'error' => 'That code is not right.', 'restart' => false];
    }
    db()->exec('DELETE FROM login_challenges WHERE token_hash = ?', [$h]);
    create_session($c['user_id']);
    return ['ok' => true, 'error' => null];
}
