<?php
declare(strict_types=1);

// Admin actions: reviewing applications and verifications, staff accounts, settings.

function review_application(array $admin, string $id, string $status, string $comment, string $expectedRaw): ?string
{
    $comment = trim($comment);
    if (!in_array($status, ['approved', 'rejected', 'needs_corrections'], true)) return 'Unknown decision.';
    if ($status !== 'approved' && $comment === '') return 'Add feedback for the applicant first.';
    $now = now_db();
    $n = db()->exec("UPDATE seller_applications SET status = ?, admin_comment = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND status = 'submitted' AND updated_at = ?",
        [$status, $comment ?: null, $admin['id'], $now, $now, $id, $expectedRaw]);
    if (!$n) return 'This application was already decided or has changed. Refresh and try again.';
    $a = db()->one('SELECT user_id, applicant_email FROM seller_applications WHERE id = ?', [$id]);
    if ($a) {
        $subject = ['approved' => 'Your seller application was approved', 'rejected' => 'Update on your seller application', 'needs_corrections' => 'Your seller application needs corrections'][$status];
        $to = $a['applicant_email'] ?: db()->val('SELECT email FROM users WHERE id = ?', [$a['user_id']]);
        notify($a['user_id'], 'application', $subject, trim($comment), '/apply', false);
        if ($to) send_mail((string) $to, $subject, ($status === 'approved' ? "Good news: your application was approved. Sign in to continue with verification:\n" : "Please sign in to see our feedback:\n") . base_url() . "/apply\n" . ($comment ? "\nNote from our team:\n" . $comment . "\n" : ''));
    }
    return null;
}

function review_verification(array $admin, string $id, string $status, string $comment, string $expectedRaw): ?string
{
    $comment = trim($comment);
    if (!in_array($status, ['approved', 'rejected', 'needs_corrections'], true)) return 'Unknown decision.';
    if ($status !== 'approved' && $comment === '') return 'Add feedback for the seller first.';
    $now = now_db();
    $n = db()->exec("UPDATE seller_verifications SET status = ?, admin_comment = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND status = 'submitted' AND updated_at = ?",
        [$status, $comment ?: null, $admin['id'], $now, $now, $id, $expectedRaw]);
    if (!$n) return 'This verification was already decided or has changed. Refresh and try again.';
    $to = db()->val('SELECT u.email FROM seller_verifications v JOIN users u ON u.id = v.user_id WHERE v.id = ?', [$id]);
    $vu = db()->val('SELECT user_id FROM seller_verifications WHERE id = ?', [$id]);
    if ($to) {
        $subject = ['approved' => 'You are verified: start selling on DDLX', 'rejected' => 'Update on your seller verification', 'needs_corrections' => 'Your seller verification needs corrections'][$status];
        if ($vu) notify((string) $vu, 'verification', $subject, trim($comment), $status === 'approved' ? '/seller' : '/verification', false);
        send_mail((string) $to, $subject, ($status === 'approved' ? "You are now a verified seller. Open your Seller Center:\n" . base_url() . "/seller\n" : "Please sign in to see our feedback:\n" . base_url() . "/verification\n") . ($comment ? "\nNote from our team:\n" . $comment . "\n" : ''));
    }
    return null;
}

function application_review_list(): array
{
    $rows = db()->all("SELECT * FROM seller_applications WHERE status <> 'draft' ORDER BY (status = 'submitted') DESC, updated_at DESC LIMIT 300");
    foreach ($rows as &$r) foreach (['personal', 'business', 'brand', 'registration', 'declaration'] as $k) $r[$k] = jdec($r[$k] ?? '', []);
    return $rows;
}

function verification_review_list(): array
{
    $rows = db()->all("SELECT v.*, a.applicant_email, a.personal AS a_personal, a.brand AS a_brand, a.business AS a_business, a.registration AS a_registration FROM seller_verifications v LEFT JOIN seller_applications a ON a.id = v.application_id WHERE v.status <> 'draft' ORDER BY (v.status = 'submitted') DESC, v.updated_at DESC LIMIT 300");
    foreach ($rows as &$r) {
        $r['details'] = jdec($r['details'] ?? '', []);
        $r['documents'] = jdec($r['documents'] ?? '', []);
        foreach (['personal', 'brand', 'business', 'registration'] as $k) { $r['app_' . $k] = jdec($r['a_' . $k] ?? '', []); unset($r['a_' . $k]); }
    }
    return $rows;
}

// ---------------------------------------------------------------- staff

const STAFF_PERMISSIONS = ['products' => 'Review and publish products', 'orders' => 'Manage orders (pickup, shipping, delivery)'];

function staff_list(): array
{
    $rows = db()->all('SELECT s.user_id, u.email, s.permissions, s.active, s.created_at FROM staff_members s JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC');
    foreach ($rows as &$r) $r['permissions'] = array_values(array_filter(explode(',', (string) $r['permissions'])));
    return $rows;
}

function clean_permissions(array $posted): array
{
    return array_values(array_intersect(array_keys(STAFF_PERMISSIONS), array_map('strval', $posted)));
}

function staff_create(array $admin, string $email, string $password, array $perms): ?string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) return 'Enter a valid email.';
    if ($e = password_ok($password)) return $e;
    if (!$perms) return 'Choose at least one permission.';
    $db = db();
    try {
        $db->tx(function () use ($db, $admin, $email, $password, $perms) {
            $id = uuid();
            $db->insert('users', ['id' => $id, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => now_db()]);
            $db->insert('staff_members', ['user_id' => $id, 'permissions' => implode(',', $perms), 'active' => 1, 'created_by' => $admin['id'], 'created_at' => now_db()]);
        });
    } catch (Throwable $e) {
        if (Db::isDuplicate($e)) return 'An account with this email already exists.';
        throw $e;
    }
    return null;
}

function staff_update(string $userId, bool $active, array $perms): ?string
{
    if (!$perms) return 'Choose at least one permission.';
    $n = db()->exec('UPDATE staff_members SET active = ?, permissions = ? WHERE user_id = ?', [$active ? 1 : 0, implode(',', $perms), $userId]);
    if (!$active) db()->exec('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    return $n || db()->val('SELECT 1 FROM staff_members WHERE user_id = ?', [$userId]) ? null : 'Staff member not found.';
}

function staff_set_password(string $userId, string $password): ?string
{
    if ($e = password_ok($password)) return $e;
    if (!db()->val('SELECT 1 FROM staff_members WHERE user_id = ?', [$userId])) return 'Staff member not found.';
    db()->exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
    db()->exec('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    return null;
}

// ---------------------------------------------------------------- settings

function save_settings(array $in): ?string
{
    $num = fn (string $k, float $min, float $max) => isset($in[$k]) && is_numeric($in[$k]) && (float) $in[$k] >= $min && (float) $in[$k] <= $max ? (string) (float) $in[$k] : null;
    $map = ['commission_percent' => $num('commission_percent', 0, 100), 'payout_hold_days' => $num('payout_hold_days', 0, 90), 'min_withdrawal' => $num('min_withdrawal', 1, 1000000000), 'withdrawal_fee' => $num('withdrawal_fee', 0, 1000000000)];
    foreach ($map as $k => $v) if ($v === null) return 'Check the number in "' . str_replace('_', ' ', $k) . '".';
    $url = trim((string) ($in['marketplace_url'] ?? ''));
    if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) return 'The marketplace address must start with https://';
    $app = trim((string) ($in['app_url'] ?? ''));
    if ($app !== '' && !preg_match('#^https?://[^\s]+$#i', $app)) return 'The Vendor Hub address must start with https://';
    $mail = trim((string) ($in['mail_from'] ?? ''));
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) return 'Enter a valid sender email.';
    foreach ($map as $k => $v) set_setting($k, $k === 'payout_hold_days' ? (string) (int) $v : $v);
    set_setting('marketplace_url', rtrim($url, '/'));
    set_setting('app_url', rtrim($app, '/'));
    set_setting('mail_from', $mail);
    return null;
}
