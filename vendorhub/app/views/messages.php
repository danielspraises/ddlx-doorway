<?php
/** @var bool $team @var array $threads @var ?array $thread @var string $filter @var string $q @var bool $new @var ?string $error */
$draft = $draft ?? ['subject' => '', 'body' => ''];
$activeId = $thread['id'] ?? '';
?>
<p class="eyebrow"><?= $team ? 'Team inbox' : 'Support' ?></p>
<div class="row between"><h1>Messages</h1>
  <?php if ($team): ?><a class="btn" href="/messages/compose">New message</a><?php else: ?><a class="btn" href="/messages?new=1">New message</a><?php endif; ?></div>
<?php if ($team): ?>
  <form method="get" action="/messages" class="row" style="margin:12px 0" data-keep>
    <?php foreach (['open' => 'Open', 'unread' => 'Unread', 'announcements' => 'Announcements', 'closed' => 'Closed'] as $k => $l): ?><button class="btn btn-sm <?= $filter === $k ? '' : 'btn-outline' ?>" name="filter" value="<?= $k ?>" type="submit"><?= e($l) ?></button><?php endforeach; ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search seller or subject" aria-label="Search" style="max-width:260px">
  </form>
<?php endif; ?>
<div class="inbox">
  <aside class="inbox-list" aria-label="Conversations">
    <?php foreach ($threads as $t): $unread = $team ? (int) $t['team_unread'] : (int) $t['seller_unread']; ?>
      <a class="inbox-item<?= $t['id'] === $activeId ? ' active' : '' ?><?= $unread ? ' unread' : '' ?>" href="/messages?id=<?= e($t['id']) ?>&amp;filter=<?= e($filter) ?>">
        <span class="row between" style="gap:8px"><strong><?= e($team ? $t['store'] : $t['subject']) ?></strong><span class="muted small"><?= e(human_date($t['last_message_at'], false)) ?></span></span>
        <?php if ($team): ?><span class="small"><?= e($t['subject']) ?></span><?php endif; ?>
        <span class="muted small clip"><?= e(str_limit(preg_replace('/\s+/', ' ', (string) $t['preview']) ?? '', 90)) ?></span>
        <?php if ($t['broadcast_id']): ?><span class="pill small">Announcement</span><?php endif; ?>
      </a>
    <?php endforeach; if (!$threads): ?><p class="muted" style="padding:24px"><?= $team ? 'Nothing here.' : 'No messages yet. Questions about your application, products, orders or payouts? Start a conversation and the DDLX team will reply here.' ?></p><?php endif; ?>
  </aside>
  <section class="inbox-pane">
  <?php if ($thread): ?>
    <div class="row between"><div><h2 style="margin:0"><?= e($thread['subject']) ?></h2>
      <p class="muted small" style="margin:4px 0 0"><?= $team ? e($thread['store']) . ' · ' . e($thread['seller_email']) : 'Conversation with the DDLX team' ?><?= $thread['status'] === 'closed' ? ' · closed' : '' ?></p></div>
      <?php if ($team): ?><form method="post" action="/messages/status" data-keep><?= csrf_field() ?><input type="hidden" name="thread" value="<?= e($thread['id']) ?>"><input type="hidden" name="status" value="<?= $thread['status'] === 'closed' ? 'open' : 'closed' ?>"><button class="btn btn-outline btn-sm" type="submit"><?= $thread['status'] === 'closed' ? 'Reopen' : 'Close' ?></button></form><?php endif; ?></div>
    <div class="chat" role="log" aria-label="Messages">
    <?php foreach ($thread['messages'] as $m): $mine = $team ? $m['side'] === 'team' : $m['side'] === 'seller'; ?>
      <div class="bubble <?= $mine ? 'mine' : 'theirs' ?>"><div class="who"><?= $m['side'] === 'team' ? 'DDLX team' . ($team && $m['sender_email'] ? ' · ' . e($m['sender_email']) : '') : e($thread['store']) ?> · <?= e(human_date($m['created_at'], true)) ?></div><div class="txt"><?= nl2br(e($m['body'])) ?></div></div>
    <?php endforeach; ?>
    </div>
    <?php if ($team || (int) $thread['allow_reply']): ?>
    <form method="post" action="/messages/reply" data-keep><?= csrf_field() ?><input type="hidden" name="thread" value="<?= e($thread['id']) ?>">
      <?= f_area('body', $team ? 'Reply to the seller' : 'Your reply', '', null, ['rows' => 4, 'maxlength' => MSG_MAX_BODY, 'required' => 1]) ?>
      <button class="btn" type="submit">Send reply</button></form>
    <?php else: ?><p class="notice">This was an announcement, so replies are turned off. <a href="/messages?new=1">Start a new conversation</a> if you need help.</p><?php endif; ?>
  <?php elseif ($new): ?>
    <h2 style="margin-top:0">New message to the DDLX team</h2>
    <?php if (!empty($error)): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="/messages/new" data-keep><?= csrf_field() ?>
      <?= f_text('subject', 'Subject', $draft['subject'], null, ['required' => 1, 'maxlength' => 160, 'placeholder' => 'For example: Question about my payout']) ?>
      <?= f_area('body', 'Message', $draft['body'], null, ['rows' => 7, 'maxlength' => MSG_MAX_BODY, 'required' => 1]) ?>
      <button class="btn" type="submit">Send</button> <a class="btn btn-ghost" href="/messages">Cancel</a></form>
  <?php else: ?>
    <p class="muted" style="padding:32px 8px"><?= $team ? 'Choose a conversation on the left, or write to a seller.' : 'Choose a conversation, or start a new one.' ?></p>
  <?php endif; ?>
  </section>
</div>
