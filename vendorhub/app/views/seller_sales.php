<?php
/** @var array $user */
$sales = sale_list($user['id']);
$open = sale_market_open($user['id']);
$el = $sales || $open ? sale_eligible($user['id']) : [];
$kinds = ['live' => 'ok', 'scheduled' => 'warn', 'paused' => 'warn', 'ended' => '', 'cancelled' => '', 'blocked' => 'bad'];
?>
<?php foreach ($open as $m): ?>
<section class="scard sinvite">
  <div class="grow"><p class="eyebrow" style="margin-bottom:6px">From DDLX</p>
    <h3 style="margin:0"><?= e($m['name']) ?></h3>
    <p class="muted small" style="margin:4px 0 0"><?= e(sale_dates($m)) ?><?= $m['min_discount'] !== null ? ' · at least ' . e(rtrim(rtrim(number_format($m['min_discount'], 2), '0'), '.')) . '% off' : '' ?><?= $m['note'] ? ' · ' . e($m['note']) : '' ?></p></div>
  <?php if ($m['mine']): ?><a class="btn btn-sm btn-outline" href="/sales/view?id=<?= e($m['mine']) ?>">You are in</a><?php else: ?><a class="btn btn-sm" href="/sales/edit?join=<?= e($m['id']) ?>">Join</a><?php endif; ?>
</section>
<?php endforeach; ?>

<div class="row between" style="margin:22px 0 12px"><h2 style="margin:0">Your sales</h2><a class="btn btn-sm" href="/sales/edit">New sale</a></div>
<?php if (!$sales): ?>
<div class="sempty"><p style="margin:0 0 4px"><strong>Plan a sale ahead of time</strong></p>
  <p class="muted small" style="margin:0 0 14px">Pick the dates, the products and how much off. It switches on and off by itself, so December can be ready in November.</p>
  <a class="btn btn-sm" href="/sales/edit">Create a sale</a></div>
<?php else: ?>
<ul class="slist2">
<?php foreach ($sales as $c): $n = count(array_filter(sale_rows($c, $el), fn ($r) => $r['applied'])); ?>
  <li><a href="/sales/view?id=<?= e($c['id']) ?>">
    <div class="grow"><p class="nm"><?= e($c['name']) ?><?= $c['parent_id'] ? ' <span class="muted small">· DDLX sale</span>' : '' ?></p><p class="muted small"><?= e(sale_dates($c)) ?></p></div>
    <div class="rt"><strong><?= e(sale_describe($c)) ?></strong><span class="muted small"><?= $n ?> product<?= $n === 1 ? '' : 's' ?></span></div>
    <?= pill($c['phase'], SALE_PHASE_LABEL, $kinds) ?>
  </a></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<p class="muted small" style="margin-top:16px">Want customers to type a code instead? Use <a href="/seller?tab=promotions&amp;v=codes">Discount codes</a>. A code does not apply on top of a sale price.</p>
