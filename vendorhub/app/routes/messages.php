<?php
declare(strict_types=1);

function messages_page(array $u, array $extra = []): void
{
    $team = !empty($u['canMessages']);
    $filter = in_array(input('filter'), ['open', 'unread', 'closed', 'announcements'], true) ? input('filter') : 'open';
    $q = mb_substr(input('q'), 0, 80);
    $thread = input('id') !== '' ? inbox_thread(input('id')) : null;
    if ($thread && !$team && $thread['seller_id'] !== $u['id']) $thread = null;
    if ($thread) {
        inbox_mark_seen($thread, $team ? 'team' : 'seller');
        $thread['team_unread'] = $team ? 0 : $thread['team_unread'];
        $thread['seller_unread'] = $team ? $thread['seller_unread'] : 0;
        unset($GLOBALS['vh_user_cache']);
        $u = current_user() ?? $u;
    }
    render('messages', $extra + ['title' => 'Messages', 'user' => $u, 'team' => $team, 'threads' => $team ? inbox_threads_team($filter, $q) : inbox_threads_for_seller($u['id']), 'thread' => $thread,
        'filter' => $filter, 'q' => $q, 'new' => input('new') === '1' && !$team, 'error' => null, 'live_refresh' => true]);
}

route('GET', '/messages', function () {
    messages_page(require_user());
});

route('POST', '/messages/new', function () {
    $u = require_user();
    if (!empty($u['canMessages'])) redirect('/messages/compose');
    $r = inbox_seller_start($u['id'], input('subject'), input_raw('body'));
    if (!$r['ok']) { messages_page($u, ['error' => $r['error'], 'new' => true, 'draft' => ['subject' => input('subject'), 'body' => input_raw('body')]]); return; }
    redirect('/messages?id=' . $r['id'], 'Message sent. We will reply here.');
});

route('POST', '/messages/reply', function () {
    $u = require_user();
    $id = input('thread');
    $err = inbox_reply($u, $id, input_raw('body'));
    if ($err) redirect('/messages?id=' . urlencode($id), $err, 'error');
    redirect('/messages?id=' . urlencode($id), 'Sent.');
});

route('POST', '/messages/status', function () {
    $u = require_user();
    if (empty($u['canMessages'])) abort(403, 'Only the team can do this.');
    $id = input('thread');
    $err = is_uuid($id) ? inbox_set_status($id, input('status')) : 'Conversation not found.';
    redirect('/messages?id=' . urlencode($id), $err ?? (input('status') === 'closed' ? 'Conversation closed. It reopens if the seller writes back.' : 'Conversation reopened.'), $err ? 'error' : 'ok');
});

route('GET', '/messages/compose', function () {
    $u = require_user();
    if (empty($u['canMessages'])) abort(403, 'Only the team can do this.');
    render('messages_compose', ['title' => 'New message', 'user' => $u, 'sellers' => inbox_sellers(), 'announcements' => $u['isAdmin'] ? inbox_announcements() : [], 'error' => null, 'form' => ['audience' => input('to') !== '' ? 'selected' : ($u['isAdmin'] ? 'verified' : 'selected'), 'selected' => is_uuid(input('to')) ? [input('to')] : [], 'subject' => '', 'body' => '', 'allow_reply' => true]]);
});

route('POST', '/messages/compose', function () {
    $u = require_user();
    if (empty($u['canMessages'])) abort(403, 'Only the team can do this.');
    $form = ['audience' => input('audience'), 'selected' => array_map('strval', (array) ($_POST['sellers'] ?? [])), 'subject' => input('subject'), 'body' => input_raw('body'), 'allow_reply' => input('allow_reply') === 'yes'];
    $r = inbox_send($u, $form['audience'], $form['selected'], $form['subject'], $form['body'], $form['allow_reply']);
    if (!$r['ok']) {
        render('messages_compose', ['title' => 'New message', 'user' => $u, 'sellers' => inbox_sellers(), 'announcements' => $u['isAdmin'] ? inbox_announcements() : [], 'error' => $r['error'], 'form' => $form]);
        return;
    }
    if ($r['count'] === 1) redirect('/messages?id=' . $r['thread'], 'Message sent.');
    redirect('/messages', 'Sent to ' . $r['count'] . ' sellers. Their replies arrive as separate conversations.');
});
