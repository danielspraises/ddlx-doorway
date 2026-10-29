<p class="eyebrow">Help</p>
<h1>Ask DDLX</h1>
<p class="muted" style="max-width:640px">Quick answers about selling on DDLX, from applying to getting paid. It cannot change anything in your account. For anything it cannot answer, <a href="/messages<?= !empty($user['canMessages']) ? '' : '?new=1' ?>">write to the team</a>.</p>
<div class="card" style="max-width:760px">
  <div class="chat" role="log" aria-live="polite" aria-label="Conversation">
  <?php if (!$history): ?>
    <p class="muted">Try one of these:</p>
    <p class="row"><?php foreach ($suggestions as $s): ?><a class="chip" href="/assistant?ask=<?= e(rawurlencode($s)) ?>"><?= e($s) ?></a><?php endforeach; ?></p>
  <?php endif; ?>
  <?php foreach ($history as $i => $m): $mine = $m['role'] === 'user'; ?>
    <div class="bubble <?= $mine ? 'mine' : 'theirs' ?>"<?= $i === count($history) - 1 ? ' id="latest"' : '' ?>><div class="who"><?= $mine ? 'You' : 'Ask DDLX' ?></div><div class="txt" style="white-space:pre-line"><?= e($m['body']) ?></div></div>
  <?php endforeach; ?>
  </div>
  <?php if (!empty($error)): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="/assistant" data-keep><?= csrf_field() ?>
    <?= f_area('question', 'Your question', $draft !== '' ? $draft : (string) input('ask'), null, ['rows' => 3, 'maxlength' => AI_MAX_QUESTION, 'required' => 1, 'placeholder' => 'For example: why is my product still in review?']) ?>
    <div class="row between"><button class="btn" type="submit">Ask</button><span class="muted small"><?= (int) $left ?> question<?= $left === 1 ? '' : 's' ?> left today<?= $ai ? '' : ' · built-in help' ?></span></div>
  </form>
</div>
