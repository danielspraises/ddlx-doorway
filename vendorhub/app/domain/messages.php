<?php
declare(strict_types=1);

// Conversations between sellers and the DDLX team, and announcements from the team.
// One thread belongs to one seller. An announcement creates one thread per recipient, so replies stay private.

const MSG_MAX_BODY = 5000;
const MSG_AUDIENCE = ['verified' => 'All verified sellers', 'everyone' => 'Everyone with a seller account', 'selected' => 'Only the sellers I choose'];

function inbox_clean(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
    return trim($s);
}

function inbox_store_name(string $sellerId): string
{
    $b = db()->val('SELECT brand FROM seller_applications WHERE user_id = ?', [$sellerId]);
    return trim((string) (jdec((string) $b, [])['store_name'] ?? ''));
}

// Everyone on the team who answers messages.
function inbox_team_ids(): array
{
    $ids = db()->all("SELECT user_id AS id FROM user_roles WHERE role = 'admin'");
    $staff = db()->all("SELECT user_id AS id, permissions FROM staff_members WHERE active = 1");
    $out = array_column($ids, 'id');
    foreach ($staff as $s) if (in_array('messages', explode(',', (string) $s['permissions']), true)) $out[] = $s['id'];
    return array_values(array_unique($out));
}

function inbox_is_team_member(string $userId): bool
{
    return in_array($userId, inbox_team_ids(), true);
}

function inbox_threads_for_seller(string $sellerId): array
{
    return db()->all('SELECT t.*, (SELECT body FROM messages m WHERE m.thread_id = t.id ORDER BY m.created_at DESC, m.id LIMIT 1) AS preview FROM threads t WHERE t.seller_id = ? ORDER BY t.last_message_at DESC LIMIT 200', [$sellerId]);
}

function inbox_threads_team(string $filter = 'open', string $q = ''): array
{
    $where = [];
    $args = [];
    if ($filter === 'unread') $where[] = 't.team_unread = 1';
    elseif ($filter === 'closed') $where[] = "t.status = 'closed'";
    elseif ($filter === 'announcements') $where[] = 't.broadcast_id IS NOT NULL';
    else $where[] = "t.status = 'open'";
    if ($q !== '') {
        $where[] = '(t.subject LIKE ? OR u.email LIKE ? OR a.brand LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        array_push($args, $like, $like, $like);
    }
    $rows = db()->all('SELECT t.*, u.email AS seller_email, a.brand AS brand_json, (SELECT body FROM messages m WHERE m.thread_id = t.id ORDER BY m.created_at DESC, m.id LIMIT 1) AS preview
        FROM threads t JOIN users u ON u.id = t.seller_id LEFT JOIN seller_applications a ON a.user_id = u.id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.team_unread DESC, t.last_message_at DESC LIMIT 200', $args);
    foreach ($rows as &$r) { $r['store'] = trim((string) (jdec((string) $r['brand_json'], [])['store_name'] ?? '')) ?: $r['seller_email']; unset($r['brand_json']); }
    return $rows;
}

function inbox_thread(string $id): ?array
{
    if (!is_uuid($id)) return null;
    $t = db()->one('SELECT t.*, u.email AS seller_email FROM threads t JOIN users u ON u.id = t.seller_id WHERE t.id = ?', [$id]);
    if (!$t) return null;
    $t['store'] = inbox_store_name($t['seller_id']) ?: $t['seller_email'];
    $t['messages'] = db()->all('SELECT m.*, u.email AS sender_email FROM messages m LEFT JOIN users u ON u.id = m.sender_id WHERE m.thread_id = ? ORDER BY m.created_at, m.id', [$id]);
    return $t;
}

// Opens the thread for whoever is looking at it and clears their unread flag.
function inbox_mark_seen(array $thread, string $side): void
{
    $col = $side === 'team' ? 'team_unread' : 'seller_unread';
    if ((int) $thread[$col] === 1) db()->exec("UPDATE threads SET $col = 0 WHERE id = ?", [$thread['id']]);
}

function inbox_add(string $threadId, ?string $senderId, string $side, string $body): void
{
    $now = now_db();
    db()->insert('messages', ['id' => uuid(), 'thread_id' => $threadId, 'sender_id' => $senderId, 'side' => $side, 'body' => $body, 'created_at' => $now]);
    db()->exec('UPDATE threads SET last_message_at = ?, team_unread = ?, seller_unread = ?, status = ? WHERE id = ?',
        [$now, $side === 'seller' ? 1 : 0, $side === 'team' ? 1 : 0, 'open', $threadId]);
}

function inbox_notify_team(array $thread, string $from, string $text): void
{
    announce('messages', 'message', 'New message from ' . $from);
    foreach (inbox_team_ids() as $id) notify($id, 'message', 'New message from ' . $from . ': ' . $thread['subject'], str_limit($text, 300), '/messages?id=' . $thread['id']);
}

// A seller starts a conversation with the team.
function inbox_seller_start(string $sellerId, string $subject, string $body): array
{
    $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? '');
    $body = inbox_clean($body);
    if ($subject === '' || mb_strlen($subject) > 160) return ['ok' => false, 'error' => 'Add a short subject (up to 160 characters).'];
    if ($body === '') return ['ok' => false, 'error' => 'Write your message.'];
    if (mb_strlen($body) > MSG_MAX_BODY) return ['ok' => false, 'error' => 'That message is too long. Keep it under ' . MSG_MAX_BODY . ' characters.'];
    if ((int) db()->val("SELECT COUNT(*) FROM threads t WHERE t.seller_id = ? AND t.broadcast_id IS NULL AND t.created_at > ?", [$sellerId, db_plus('-1 day')]) >= 10) return ['ok' => false, 'error' => 'You have started a lot of conversations today. Reply in an existing one, or try again tomorrow.'];
    $id = uuid();
    $now = now_db();
    db()->insert('threads', ['id' => $id, 'seller_id' => $sellerId, 'subject' => $subject, 'allow_reply' => 1, 'status' => 'open', 'team_unread' => 1, 'seller_unread' => 0, 'last_message_at' => $now, 'created_at' => $now]);
    inbox_add($id, $sellerId, 'seller', $body);
    $from = inbox_store_name($sellerId) ?: (string) db()->val('SELECT email FROM users WHERE id = ?', [$sellerId]);
    inbox_notify_team(['id' => $id, 'subject' => $subject], $from, $body);
    return ['ok' => true, 'id' => $id];
}

// Replies from either side. $user is the signed-in person.
function inbox_reply(array $user, string $threadId, string $body): ?string
{
    $t = inbox_thread($threadId);
    if (!$t) return 'Conversation not found.';
    $team = !empty($user['canMessages']);
    if (!$team && $t['seller_id'] !== $user['id']) return 'Conversation not found.';
    $body = inbox_clean($body);
    if ($body === '') return 'Write your reply.';
    if (mb_strlen($body) > MSG_MAX_BODY) return 'That reply is too long. Keep it under ' . MSG_MAX_BODY . ' characters.';
    if (!$team && !(int) $t['allow_reply']) return 'This was an announcement. You cannot reply to it, but you can start a new conversation.';
    if (!$team && (int) db()->val('SELECT COUNT(*) FROM messages WHERE thread_id = ? AND side = ? AND created_at > ?', [$threadId, 'seller', db_plus('-1 hour')]) >= 20) return 'You are sending messages very quickly. Wait a little and try again.';
    inbox_add($threadId, $user['id'], $team ? 'team' : 'seller', $body);
    if ($team) {
        announce('seller', 'message', 'New message from the DDLX team: ' . $t['subject'], $t['seller_id']);
        notify($t['seller_id'], 'message', 'New message from the DDLX team', $t['subject'] . "\n\n" . str_limit($body, 300), '/messages?id=' . $threadId);
        announce('messages', 'message_read');
    } else {
        inbox_notify_team($t, $t['store'], $body);
    }
    return null;
}

function inbox_set_status(string $threadId, string $status): ?string
{
    if (!in_array($status, ['open', 'closed'], true)) return 'Unknown action.';
    return db()->exec('UPDATE threads SET status = ? WHERE id = ?', [$status, $threadId]) ? null : 'Conversation not found.';
}

// ---------------------------------------------------------------- team to sellers

function inbox_sellers(string $q = ''): array
{
    $args = [];
    $where = "u.id NOT IN (SELECT user_id FROM user_roles WHERE role = 'admin') AND u.id NOT IN (SELECT user_id FROM staff_members)";
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $where .= ' AND (u.email LIKE ? OR a.brand LIKE ?)';
        $args = [$like, $like];
    }
    $rows = db()->all("SELECT u.id, u.email, a.brand AS brand_json, a.status AS app_status, v.status AS ver_status FROM users u LEFT JOIN seller_applications a ON a.user_id = u.id LEFT JOIN seller_verifications v ON v.user_id = u.id WHERE $where ORDER BY u.created_at DESC LIMIT 300", $args);
    foreach ($rows as &$r) {
        $r['store'] = trim((string) (jdec((string) $r['brand_json'], [])['store_name'] ?? ''));
        $r['stage'] = seller_stage($r['app_status'], $r['ver_status']);
        unset($r['brand_json']);
    }
    return $rows;
}

function inbox_audience_ids(string $audience, array $selected): array
{
    if ($audience === 'selected') {
        $selected = array_values(array_filter(array_unique(array_map('strval', $selected)), 'is_uuid'));
        if (!$selected) return [];
        $ph = db()->placeholders($selected);
        return array_column(db()->all("SELECT id FROM users WHERE id IN ($ph) AND id NOT IN (SELECT user_id FROM user_roles WHERE role = 'admin') AND id NOT IN (SELECT user_id FROM staff_members)", $selected), 'id');
    }
    $base = "SELECT u.id FROM users u WHERE u.id NOT IN (SELECT user_id FROM user_roles WHERE role = 'admin') AND u.id NOT IN (SELECT user_id FROM staff_members)";
    if ($audience === 'verified') $base .= " AND u.id IN (SELECT user_id FROM seller_verifications WHERE status = 'approved')";
    return array_column(db()->all($base), 'id');
}

// Sends a message to one, several or all sellers. Only an admin may send to groups.
function inbox_send(array $team, string $audience, array $selected, string $subject, string $body, bool $allowReply): array
{
    $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? '');
    $body = inbox_clean($body);
    if (!isset(MSG_AUDIENCE[$audience])) return ['ok' => false, 'error' => 'Choose who should get this.'];
    if ($audience !== 'selected' && empty($team['isAdmin'])) return ['ok' => false, 'error' => 'Only an administrator can message all sellers.'];
    if ($audience === 'selected' && count($selected) > 1 && empty($team['isAdmin'])) return ['ok' => false, 'error' => 'Only an administrator can message several sellers at once.'];
    if ($subject === '' || mb_strlen($subject) > 160) return ['ok' => false, 'error' => 'Add a short subject (up to 160 characters).'];
    if ($body === '' || mb_strlen($body) > MSG_MAX_BODY) return ['ok' => false, 'error' => 'Write the message (up to ' . MSG_MAX_BODY . ' characters).'];
    $ids = inbox_audience_ids($audience, $selected);
    if (!$ids) return ['ok' => false, 'error' => $audience === 'selected' ? 'Choose at least one seller.' : 'There is nobody in that group yet.'];
    $multi = count($ids) > 1 || $audience !== 'selected';
    $bid = $multi ? uuid() : null;
    if ($bid) db()->insert('broadcasts', ['id' => $bid, 'subject' => $subject, 'body' => $body, 'audience' => $audience, 'recipients' => count($ids), 'allow_reply' => $allowReply ? 1 : 0, 'sent_by' => $team['id'], 'created_at' => now_db()]);
    $first = null;
    foreach ($ids as $sid) {
        $tid = uuid();
        $now = now_db();
        db()->insert('threads', ['id' => $tid, 'seller_id' => $sid, 'subject' => $subject, 'broadcast_id' => $bid, 'allow_reply' => $allowReply ? 1 : 0, 'status' => 'open', 'team_unread' => 0, 'seller_unread' => 1, 'last_message_at' => $now, 'created_at' => $now]);
        db()->insert('messages', ['id' => uuid(), 'thread_id' => $tid, 'sender_id' => $team['id'], 'side' => 'team', 'body' => $body, 'created_at' => $now]);
        announce('seller', 'message', 'New message from the DDLX team: ' . $subject, $sid);
        notify($sid, 'message', 'New message from the DDLX team', $subject . "\n\n" . str_limit($body, 300), '/messages?id=' . $tid);
        $first ??= $tid;
    }
    audit($team, 'staff', 'Sent "' . str_limit($subject, 80) . '" to ' . count($ids) . ' seller' . (count($ids) === 1 ? '' : 's') . ' (' . MSG_AUDIENCE[$audience] . ')', $bid);
    return ['ok' => true, 'count' => count($ids), 'thread' => $first];
}

function inbox_announcements(int $limit = 20): array
{
    return db()->all('SELECT * FROM broadcasts ORDER BY created_at DESC LIMIT ' . (int) $limit);
}
