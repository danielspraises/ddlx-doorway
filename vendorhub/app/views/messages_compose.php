<?php /** @var array $sellers @var array $announcements @var array $form @var ?string $error */ ?>
<p class="eyebrow">Team inbox</p>
<h1>New message</h1>
<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" action="/messages/compose" data-keep class="card" style="max-width:820px"><?= csrf_field() ?>
  <fieldset style="border:0;padding:0;margin:0 0 14px"><legend class="label">Who is this for?</legend>
    <?php foreach (MSG_AUDIENCE as $k => $l): if ($k !== 'selected' && !$user['isAdmin']) continue; ?>
      <label class="check"><input type="radio" name="audience" value="<?= e($k) ?>"<?= $form['audience'] === $k ? ' checked' : '' ?> data-audience><span><?= e($l) ?></span></label>
    <?php endforeach; ?>
  </fieldset>
  <div id="seller-pick" class="field"<?= $form['audience'] === 'selected' ? '' : ' hidden' ?>>
    <label for="seller-filter">Choose sellers</label>
    <input id="seller-filter" type="search" placeholder="Filter by store or email" data-filter-list="seller-list" autocomplete="off">
    <div class="pick" id="seller-list">
      <?php foreach ($sellers as $s): ?>
        <label class="check" data-filter-item><input type="checkbox" name="sellers[]" value="<?= e($s['id']) ?>"<?= in_array($s['id'], $form['selected'], true) ? ' checked' : '' ?>><span><strong><?= e($s['store'] ?: $s['email']) ?></strong> <span class="muted small"><?= e($s['store'] ? $s['email'] . ' · ' : '') ?><?= e(STAGE_LABEL[$s['stage']] ?? '') ?></span></span></label>
      <?php endforeach; if (!$sellers): ?><p class="muted">No sellers yet.</p><?php endif; ?>
    </div>
    <?php if (!$user['isAdmin']): ?><span class="hint">Staff can message one seller at a time.</span><?php endif; ?>
  </div>
  <?= f_text('subject', 'Subject', $form['subject'], null, ['required' => 1, 'maxlength' => 160]) ?>
  <?= f_area('body', 'Message', $form['body'], null, ['rows' => 8, 'maxlength' => MSG_MAX_BODY, 'required' => 1]) ?>
  <label class="check"><input type="checkbox" name="allow_reply" value="yes"<?= $form['allow_reply'] ? ' checked' : '' ?>><span>Let sellers reply</span></label>
  <p class="row" style="margin-top:16px"><button class="btn" type="submit" data-confirm="Send this message?">Send</button><a class="btn btn-ghost" href="/messages">Cancel</a></p>
</form>
<?php if ($announcements): ?>
  <h2>Recent announcements</h2>
  <ul class="list"><?php foreach ($announcements as $a): ?><li class="row between"><span><strong><?= e($a['subject']) ?></strong><br><span class="muted small"><?= e(MSG_AUDIENCE[$a['audience']] ?? $a['audience']) ?> · <?= (int) $a['recipients'] ?> sellers</span></span><span class="muted small"><?= e(human_date($a['created_at'], true)) ?></span></li><?php endforeach; ?></ul>
<?php endif; ?>
