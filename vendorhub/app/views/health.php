<p class="eyebrow">Admin</p>
<h1>System check</h1>
<?php $bad = count(array_filter($rows, fn ($r) => $r[1] === 'fail')); $warn = count(array_filter($rows, fn ($r) => $r[1] === 'warn')); ?>
<p class="<?= $bad ? 'notice error' : 'notice' ?>"><?= $bad ? $bad . ' problem' . ($bad === 1 ? '' : 's') . ' to fix' : 'No blocking problems' ?><?= $warn ? ', ' . $warn . ' thing' . ($warn === 1 ? '' : 's') . ' worth a look' : '' ?>.</p>
<ul class="list">
<?php foreach ($rows as [$label, $st, $detail]): ?>
  <li class="row between"><span><strong><?= e($label) ?></strong><br><span class="muted small"><?= e($detail) ?></span></span><?= pill($st === 'ok' ? 'OK' : ($st === 'warn' ? 'Check' : 'Fix'), [], ['OK' => 'ok', 'Check' => 'warn', 'Fix' => 'bad']) ?></li>
<?php endforeach; ?>
</ul>
<p class="row"><a class="btn" href="/admin/health">Run again</a><a class="btn btn-ghost" href="/admin?tab=settings">Back to settings</a></p>
