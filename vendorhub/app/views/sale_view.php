<?php
/** @var array $c @var array $rows @var ?array $results @var ?array $parent @var array $user */
$phase = $c['phase'];
$kinds = ['live' => 'ok', 'scheduled' => 'warn', 'paused' => 'warn', 'ended' => '', 'cancelled' => '', 'blocked' => 'bad'];
$applied = array_filter($rows, fn ($r) => $r['applied']);
$open = in_array($phase, ['scheduled', 'live', 'paused'], true);
?>
<p><a href="/seller?tab=promotions" class="small">← Promotions</a></p>
<div class="row between" style="margin-bottom:6px"><h1 style="margin:0"><?= e($c['name']) ?></h1><?= pill($phase, SALE_PHASE_LABEL, $kinds) ?></div>
<p class="muted" style="margin:0 0 18px"><?= e(sale_dates($c)) ?> · <?= e(sale_describe($c)) ?><?= $parent ? ' · DDLX sale' : '' ?></p>

<?php if ($phase === 'blocked'): ?><p class="notice">DDLX switched this sale off. Your prices are back to normal. Message the team if you have questions.</p><?php endif; ?>
<?php if ($phase === 'paused'): ?><p class="notice">This sale is paused. Prices are back to normal until you resume it.</p><?php endif; ?>

<div class="row" style="margin-bottom:20px">
  <?php if ($open && !$c['parent_id'] || $open && $parent): ?><a class="btn btn-sm" href="/sales/edit?id=<?= e($c['id']) ?>">Edit</a><?php endif; ?>
  <form method="post" action="/sales/action" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
    <?php if ($phase === 'paused'): ?><button class="btn btn-sm btn-outline" name="action" value="resume">Resume</button><?php endif; ?>
    <?php if (in_array($phase, ['scheduled', 'live'], true)): ?><button class="btn btn-sm btn-outline" name="action" value="pause">Pause</button><?php endif; ?>
    <?php if ($open): ?><button class="btn btn-sm btn-ghost" name="action" value="cancel" data-confirm="End this sale now? Prices go back to normal.">End sale</button><?php endif; ?>
    <?php if (in_array($phase, ['ended', 'cancelled'], true)): ?><button class="btn btn-sm btn-ghost" name="action" value="delete" data-confirm="Delete this sale from your list?">Delete</button><?php endif; ?>
  </form>
</div>

<?php if ($results): $n = $results['now']; $b = $results['before']; $cur = $c['currency']; ?>
<div class="dgrid" style="margin-bottom:20px">
  <section class="dcard c4"><div class="dhead"><h3>Sales</h3><?= dash_chip(dash_delta($n['gross'], $b['gross'])) ?></div><p class="dbig"><?= e(dash_money($n['gross'], $cur)) ?></p><p class="dfoot">Before the sale: <?= e(dash_money($b['gross'], $cur)) ?> in the same <?= (int) $results['days'] ?> days</p></section>
  <section class="dcard c4"><div class="dhead"><h3>Orders</h3><?= dash_chip(dash_delta((float) $n['orders'], (float) $b['orders'])) ?></div><p class="dbig"><?= number_format($n['orders']) ?></p><p class="dfoot">Before: <?= number_format($b['orders']) ?></p></section>
  <section class="dcard c4"><div class="dhead"><h3>Units</h3><?= dash_chip(dash_delta((float) $n['units'], (float) $b['units'])) ?></div><p class="dbig"><?= number_format($n['units']) ?></p><p class="dfoot">Before: <?= number_format($b['units']) ?></p></section>
</div>
<?php endif; ?>

<section class="scard">
  <div class="dhead"><h3>Products</h3><span class="muted small"><?= count($applied) ?> on sale</span></div>
  <?php include __DIR__ . '/sale_rows.php'; ?>
</section>
