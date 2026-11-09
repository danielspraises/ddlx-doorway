<div class="chat-layout">
<aside class="chat-side"><a class="btn btn-sm chat-new" href="/assistant">+ New chat</a>
  <details class="chat-list" open><summary>Conversations</summary><ul data-chats>
  <?php foreach ($chats as $ch): ?><li class="<?= $chat && $chat['id'] === $ch['id'] ? 'on' : '' ?>"><a href="/assistant?c=<?= e($ch['id']) ?>"><span><?= e($ch['title']) ?></span><small><?= e(human_date($ch['updated_at'])) ?></small></a>
    <form method="post" action="/assistant/delete" data-keep data-confirm="Delete this conversation?"><?= csrf_field() ?><input type="hidden" name="chat" value="<?= e($ch['id']) ?>"><button type="submit" class="chat-del" aria-label="Delete conversation" title="Delete">&times;</button></form></li>
  <?php endforeach; ?>
  <?php if (!$chats): ?><li class="muted small" style="padding:6px 8px" data-empty>No conversations yet.</li><?php endif; ?></ul></details></aside>
<div class="narrow-col">
<p class="eyebrow">Help</p>
<h1>Ask DDLX <?= help('Quick answers about selling on DDLX, from applying to getting paid. It cannot change anything in your account. For anything it cannot answer, write to the team from Messages.') ?></h1>
<div class="card">
  <div class="chat" role="log" aria-live="polite" aria-label="Conversation">
  <?php if (!$history): ?>
    <p class="muted">Try one of these:</p>
    <p class="row"><?php foreach ($suggestions as $s): ?><a class="chip" href="/assistant?ask=<?= e(rawurlencode($s)) ?>"><?= e($s) ?></a><?php endforeach; ?></p>
  <?php endif; ?>
  <?php foreach ($history as $i => $m): $mine = $m['role'] === 'user'; ?>
    <div class="bubble <?= $mine ? 'mine' : 'theirs' ?>"<?= $i === count($history) - 1 ? ' id="latest"' : '' ?>><div class="who"><?= $mine ? 'You' : 'Ask DDLX' ?></div><?php if ($mine): ?><div class="txt" style="white-space:pre-line"><?= e($m['body']) ?></div><?php else: ?><div class="txt rich"><?= assistant_format((string) $m['body']) ?></div><?php endif; ?></div>
  <?php endforeach; ?>
  </div>
  <p class="err<?= empty($error) ? ' hidden' : '' ?>" role="alert" data-aerr><?= e($error ?? '') ?></p>
  <form method="post" action="/assistant" data-keep data-assistant><?= csrf_field() ?><input type="hidden" name="chat" value="<?= e($chat['id'] ?? '') ?>">
    <?= f_area('question', 'Your question', $draft !== '' ? $draft : (string) input('ask'), null, ['rows' => 3, 'maxlength' => AI_MAX_QUESTION, 'required' => 1, 'placeholder' => 'For example: why is my product still in review?']) ?>
    <div class="row between"><button class="btn" type="submit">Ask</button><span class="muted small"><?= $left === PHP_INT_MAX ? '' : (int) $left . ' question' . ($left === 1 ? '' : 's') . ' left today' ?><?= $ai ? '' : ($left === PHP_INT_MAX ? 'Built-in help' : ' · built-in help') ?></span></div>
  </form>
</div>
</div>
</div>
