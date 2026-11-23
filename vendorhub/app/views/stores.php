<?php
/** @var array $stores @var string $current @var bool $canAdd */
?>
<div class="h1-row"><h1>Your stores</h1></div>
<ul class="rows">
<?php foreach ($stores as $s): ?><li>
  <form method="post" action="/stores/switch" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($s['id']) ?>">
    <button type="submit" class="srow<?= $s['id'] === $current ? ' on' : '' ?>"><span class="avatar"><?= e(strtoupper(substr($s['name'], 0, 1))) ?></span>
      <span class="grow"><strong><?= e($s['name']) ?></strong></span>
      <span class="chip-s<?= $s['stage'] === 'verified' ? ' ok' : '' ?>"><?= e($s['stage'] === 'verified' ? 'Verified' : (STAGE_LABEL[$s['stage']] ?? '')) ?></span></button></form></li>
<?php endforeach; ?>
</ul>
<?php if ($canAdd): ?>
<form method="post" action="/stores/new" data-keep style="margin-top:14px"><?= csrf_field() ?><button type="submit" class="btn btn-outline"><?= icon('plus') ?> Add another store</button></form>
<?php endif; ?>
