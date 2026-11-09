<?php
declare(strict_types=1);

function assistant_page(array $u, ?string $error = null, string $draft = '', string $chatId = ''): void
{
    $chat = assistant_chat($u['id'], $chatId);
    render('assistant', ['title' => 'Ask DDLX', 'scripts' => ['assistant.js'], 'user' => $u, 'chat' => $chat, 'chats' => assistant_chats($u['id']), 'history' => $chat ? assistant_history($u['id'], $chat['id']) : [], 'left' => assistant_left_today($u['id']), 'ai' => assistant_ready(), 'error' => $error, 'draft' => $draft,
        'suggestions' => !empty($u['isAdmin']) || !empty($u['canReview']) ? ['How do I review a product?', 'How do payouts work?', 'How do I message all sellers?'] : ($u['stage'] === 'verified' ? ['How do I add many products at once?', 'When can I withdraw my earnings?', 'What do the order statuses mean?'] : ['How do I get started selling?', 'What happens during verification?', 'How do I talk to the team?'])]);
}

route('GET', '/assistant', function () {
    assistant_page(require_user(), null, '', input('c'));
});

route('POST', '/assistant/delete', function () {
    $u = require_user();
    assistant_chat_delete($u['id'], input('chat'));
    redirect('/assistant', 'Conversation deleted.');
});

route('POST', '/assistant', function () {
    $u = require_user();
    $q = input_raw('question');
    $r = assistant_ask($u, $q, input('chat'));
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        json_out($r['ok'] ? ['ok' => true, 'reply' => $r['reply'], 'html' => assistant_format((string) $r['reply']), 'chat' => $r['chat'], 'title' => $r['title'], 'left' => assistant_left_today($u['id']) === PHP_INT_MAX ? null : assistant_left_today($u['id'])] : ['ok' => false, 'error' => $r['error']], $r['ok'] ? 200 : 422);
    }
    if (!$r['ok']) { assistant_page($u, $r['error'], $q, input('chat')); return; }
    redirect('/assistant?c=' . $r['chat'] . '#latest');
});
