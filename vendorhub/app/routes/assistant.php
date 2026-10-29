<?php
declare(strict_types=1);

function assistant_page(array $u, ?string $error = null, string $draft = ''): void
{
    render('assistant', ['title' => 'Ask DDLX', 'user' => $u, 'history' => assistant_history($u['id']), 'left' => assistant_left_today($u['id']), 'ai' => assistant_ready(), 'error' => $error, 'draft' => $draft,
        'suggestions' => !empty($u['isAdmin']) || !empty($u['canReview']) ? ['How do I review a product?', 'How do payouts work?', 'How do I message all sellers?'] : ($u['stage'] === 'verified' ? ['How do I add many products at once?', 'When can I withdraw my earnings?', 'What do the order statuses mean?'] : ['How do I get started selling?', 'What happens during verification?', 'How do I talk to the team?'])]);
}

route('GET', '/assistant', function () {
    assistant_page(require_user());
});

route('POST', '/assistant', function () {
    $u = require_user();
    $q = input_raw('question');
    $r = assistant_ask($u, $q);
    if (!$r['ok']) { assistant_page($u, $r['error'], $q); return; }
    redirect('/assistant#latest');
});
