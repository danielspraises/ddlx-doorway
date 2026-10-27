<p class="eyebrow">Inbox</p>
<h1>Notifications</h1>
<div class="row between" style="margin:16px 0">
  <form method="post" action="/notifications/alerts" class="row" data-keep><?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="email_alerts" value="yes" <?= $alerts ? 'checked' : '' ?>><span>Also email me</span></label>
    <button class="btn btn-outline btn-sm" type="submit">Save</button></form>
  <?php if (array_filter($items, fn ($n) => $n['read_at'] === null)): ?><form method="post" action="/notifications/read"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Mark all as read</button></form><?php endif; ?>
</div>
<ul class="list">
<?php foreach ($items as $n): ?>
  <li><a href="/notifications/open?id=<?= e($n['id']) ?>" style="display:block;text-decoration:none;color:inherit">
    <strong<?= $n['read_at'] === null ? '' : ' class="muted"' ?>><?= $n['read_at'] === null ? '● ' : '' ?><?= e($n['title']) ?></strong>
    <?php if ($n['body'] !== ''): ?><div class="muted small" style="white-space:pre-line"><?= e($n['body']) ?></div><?php endif; ?>
    <div class="muted small"><?= e(human_date($n['created_at'], true)) ?></div></a></li>
<?php endforeach; if (!$items): ?><li class="muted">Nothing yet. New orders, order progress, product decisions and payouts will show up here.</li><?php endif; ?>
</ul>
