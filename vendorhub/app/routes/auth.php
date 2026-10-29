<?php
declare(strict_types=1);

route('GET', '/', function () {
    $u = current_user();
    if ($u) redirect(home_path($u));
    render('home', ['title' => 'Become a DDLX Seller', 'user' => null, 'full' => true, 'footer' => true]);
});

route('GET', '/auth', function () {
    $u = current_user();
    if ($u) redirect(home_path($u));
    $token = input('token');
    $mode = $token !== '' ? 'reset' : (in_array(input('mode'), ['register', 'forgot'], true) ? input('mode') : 'login');
    render('auth', ['title' => 'Sign in', 'mode' => $mode, 'token' => $token], 'bare');
});

route('POST', '/login', function () {
    $email = strtolower(input('email'));
    $r = attempt_login($email, input_raw('password'));
    if (!$r['ok']) {
        render('auth', ['title' => 'Sign in', 'mode' => 'login', 'error' => $r['error'], 'email' => $email], 'bare');
        return;
    }
    if (!empty($r['challenge'])) {
        render('auth', ['title' => 'Two-step sign-in', 'mode' => 'twofa', 'challenge' => $r['challenge']], 'bare');
        return;
    }
    redirect(home_path(current_user() ?? ['isAdmin' => false, 'canReview' => false, 'stage' => 'no_application']));
});

route('POST', '/login/2fa', function () {
    $token = input('challenge');
    $r = login_challenge_finish($token, input('code'));
    if (!$r['ok']) {
        if (!empty($r['restart'])) { render('auth', ['title' => 'Sign in', 'mode' => 'login', 'error' => $r['error']], 'bare'); return; }
        render('auth', ['title' => 'Two-step sign-in', 'mode' => 'twofa', 'challenge' => $token, 'error' => $r['error']], 'bare');
        return;
    }
    redirect(home_path(current_user() ?? ['isAdmin' => false, 'canReview' => false, 'canOrders' => false, 'stage' => 'no_application']));
});

route('GET', '/account/security', function () {
    $u = require_user();
    $on = twofa_enabled($u['id']);
    $secret = $on ? null : ((string) (twofa_row($u['id'])['totp_secret'] ?? '') ?: twofa_begin($u['id']));
    render('security', ['title' => 'Account security', 'user' => $u, 'on' => $on, 'secret' => $secret, 'uri' => $secret ? totp_uri($u['email'], $secret) : '', 'codes' => null, 'left' => $on ? twofa_codes_left($u['id']) : 0, 'error' => null]);
});

route('POST', '/account/security', function () {
    $u = require_user();
    $act = input('action');
    $show = function (?string $error = null, ?array $codes = null) use ($u) {
        $on = twofa_enabled($u['id']);
        $secret = $on ? null : ((string) (twofa_row($u['id'])['totp_secret'] ?? '') ?: twofa_begin($u['id']));
        render('security', ['title' => 'Account security', 'user' => $u, 'on' => $on, 'secret' => $secret, 'uri' => $secret ? totp_uri($u['email'], $secret) : '', 'codes' => $codes, 'left' => $on ? twofa_codes_left($u['id']) : 0, 'error' => $error]);
    };
    if ($act === 'enable') { $r = twofa_enable($u['id'], input('code')); $show($r['error'], $r['ok'] ? $r['codes'] : null); return; }
    if ($act === 'disable') { $e = twofa_disable($u['id'], input_raw('password')); if ($e) { $show($e); return; } redirect('/account/security', 'Two-step sign-in is off.'); }
    if ($act === 'codes') { $r = twofa_new_recovery($u['id'], input_raw('password')); $show($r['ok'] ? null : 'Password is not correct.', $r['ok'] ? $r['codes'] : null); return; }
    redirect('/account/security');
});

route('POST', '/register', function () {
    $email = strtolower(input('email'));
    $password = input_raw('password');
    $fail = fn (string $m) => render('auth', ['title' => 'Create account', 'mode' => 'register', 'error' => $m, 'email' => $email], 'bare');
    if ($password !== input_raw('confirm')) { $fail('The two passwords do not match.'); return; }
    $r = register_user($email, $password);
    if (!$r['ok']) { $fail($r['error']); return; }
    redirect('/apply', 'Account created. Start your seller application.');
});

route('POST', '/logout', function () {
    destroy_session();
    redirect('/auth');
});

route('POST', '/forgot', function () {
    $email = strtolower(input('email'));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $u = db()->one('SELECT id FROM users WHERE email = ?', [$email]);
        $recent = (int) db()->val('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?', [$u['id'] ?? '', db_plus('-10 minutes')]);
        if ($u && $recent < 3) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            db()->insert('password_resets', ['token_hash' => token_hash($token), 'user_id' => $u['id'], 'expires_at' => db_plus('+1 hour'), 'created_at' => now_db()]);
            send_mail($email, 'Reset your Vendor Hub password', "Use this link within one hour to choose a new password:\n\n" . base_url() . "/auth?token=" . $token . "\n\nIf you did not ask for this, ignore this email.");
        }
    }
    render('auth', ['title' => 'Reset password', 'mode' => 'forgot', 'sent' => true], 'bare');
});

route('POST', '/reset', function () {
    $token = input('token');
    $password = input_raw('password');
    $fail = fn (string $m) => render('auth', ['title' => 'Reset password', 'mode' => 'reset', 'token' => $token, 'error' => $m], 'bare');
    if ($password !== input_raw('confirm')) { $fail('The two passwords do not match.'); return; }
    if ($err = password_ok($password)) { $fail($err); return; }
    $userId = db()->tx(function (Db $db) use ($token, $password) {
        $row = $db->one('SELECT user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [token_hash($token), now_db()]);
        if (!$row) return null;
        $db->exec('UPDATE password_resets SET used_at = ? WHERE token_hash = ?', [now_db(), token_hash($token)]);
        $db->exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
        $db->exec('DELETE FROM sessions WHERE user_id = ?', [$row['user_id']]);
        return $row['user_id'];
    });
    if (!$userId) { $fail('This reset link is invalid or has expired. Request a new one.'); return; }
    create_session($userId);
    redirect(home_path(current_user() ?? ['isAdmin' => false, 'canReview' => false, 'stage' => 'no_application']), 'Password changed.');
});

// Polled every few seconds by the browser: counts for the menu badges and anything new for this person.
route('GET', '/api/pulse', function () {
    $u = require_user();
    $db = db();
    $last = (int) $db->val('SELECT COALESCE(MAX(id), 0) FROM events');
    $out = ['last' => $last];
    if (isset($_GET['since']) && ctype_digit((string) $_GET['since'])) {
        $since = (int) $_GET['since'];
        $rows = $db->all('SELECT id, audience, seller_id, type, message FROM events WHERE id > ? AND id <= ? ORDER BY id LIMIT 30', [$since, $last]);
        $events = [];
        foreach ($rows as $r) {
            $mine = ($r['audience'] === 'seller' && $r['seller_id'] === $u['id'])
                || ($r['audience'] === 'reviewers' && $u['canReview']) || ($r['audience'] === 'orders' && $u['canOrders'])
                || ($r['audience'] === 'admins' && $u['isAdmin']);
            if ($mine) $events[] = ['type' => $r['type'], 'message' => $r['message']];
        }
        $out['events'] = $events;
        $out['changed'] = count($events) > 0;
        $out['counts'] = [];
        foreach (nav_items($u) as [$label, , $badge]) $out['counts'][$label] = $badge;
    }
    json_out($out);
}, ['api' => true]);
