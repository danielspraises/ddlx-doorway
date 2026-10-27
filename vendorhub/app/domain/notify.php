<?php
declare(strict_types=1);

// Notifications: a permanent inbox in the app, plus an email when the person has alerts switched on.

function notify(string $userId, string $type, string $title, string $body = '', string $link = '', bool $email = true): void
{
    try {
        db()->insert('notifications', ['id' => uuid(), 'user_id' => $userId, 'type' => $type, 'title' => str_limit($title, 200), 'body' => str_limit($body, 1000), 'link' => str_limit($link, 250), 'created_at' => now_db()]);
        if (random_int(1, 50) === 1) db()->exec('DELETE FROM notifications WHERE created_at < ?', [db_plus('-90 days')]);
        if ($email) {
            $u = db()->one('SELECT email, email_alerts FROM users WHERE id = ?', [$userId]);
            if ($u && (int) $u['email_alerts'] === 1) {
                send_mail((string) $u['email'], $title, ($body !== '' ? $body . "\n\n" : '') . ($link !== '' ? base_url() . $link . "\n\n" : '') . "You get these emails because alerts are on. Change this under Notifications in Vendor Hub.");
            }
        }
    } catch (Throwable $e) {
        log_error('notify failed: ' . $e->getMessage());
    }
}

function notifications_list(string $userId, int $limit = 60): array
{
    return db()->all('SELECT id, type, title, body, link, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id LIMIT ' . (int) $limit, [$userId]);
}

function notifications_unread(string $userId): int
{
    return (int) db()->val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
}

function notifications_mark_read(string $userId, ?string $id = null): void
{
    if ($id !== null && is_uuid($id)) {
        db()->exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND id = ? AND read_at IS NULL', [now_db(), $userId, $id]);
        return;
    }
    db()->exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now_db(), $userId]);
}
