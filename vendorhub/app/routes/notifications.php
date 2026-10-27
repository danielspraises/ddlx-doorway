<?php
declare(strict_types=1);

route('GET', '/notifications', function () {
    $u = require_user();
    $alerts = (int) db()->val('SELECT email_alerts FROM users WHERE id = ?', [$u['id']]) === 1;
    render('notifications', ['title' => 'Notifications', 'user' => $u, 'items' => notifications_list($u['id']), 'alerts' => $alerts, 'live_refresh' => true]);
});

// Opening a notification marks it read and goes to where it points.
route('GET', '/notifications/open', function () {
    $u = require_user();
    $id = input('id');
    $link = (string) db()->val('SELECT link FROM notifications WHERE id = ? AND user_id = ?', [$id, $u['id']]);
    notifications_mark_read($u['id'], $id);
    redirect($link !== '' && str_starts_with($link, '/') && !str_starts_with($link, '//') ? $link : '/notifications');
});

route('POST', '/notifications/read', function () {
    $u = require_user();
    notifications_mark_read($u['id']);
    redirect('/notifications', 'All marked as read.');
});

route('POST', '/notifications/alerts', function () {
    $u = require_user();
    db()->exec('UPDATE users SET email_alerts = ? WHERE id = ?', [input('email_alerts') === 'yes' ? 1 : 0, $u['id']]);
    redirect('/notifications', input('email_alerts') === 'yes' ? 'Email alerts are on.' : 'Email alerts are off. You will still see everything here.');
});
