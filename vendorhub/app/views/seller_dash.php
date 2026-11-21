<?php
/** @var array $dash @var array $user @var array $products @var array $balances @var array $level @var array $rating */
$cur = $dash['currency'];
$q = fn (array $o) => '/seller?' . http_build_query(array_merge(['tab' => 'insights', 'range' => $dash['range'], 'cur' => $cur, 'm' => $dash['metric']], $o));
$n = $dash['now']; $b = $dash['before'];
$bal = current(array_filter($balances, fn ($x) => $x['currency'] === $cur)) ?: ['available' => 0.0, 'pending' => 0.0, 'total' => 0.0];
$balTotal = max(0.01, $bal['available'] + $bal['pending'] + $dash['paid_out']);
$seg = fn (float $v) => round(max(0, $v) / $balTotal * 100, 1);
$store = store_label($user['id'], [$user['id'] => (string) ($user['email'] ?? '')]);
$live = count(array_filter($products, fn ($p) => $p['live']));
$review = count(array_filter($products, fn ($p) => $p['status'] === 'pending_review'));
$low = array_filter($products, fn ($p) => in_array(stock_state($p), ['low', 'out'], true));
$changes = array_filter($products, fn ($p) => in_array($p['status'], ['changes_requested', 'rejected'], true));
$attention = array_slice(array_unique(array_merge(array_values($changes), array_values($low)), SORT_REGULAR), 0, 5);
$curLv = $level['override_level'] ?: $level['auto_level'];
$empty = $n['orders'] === 0 && $b['orders'] === 0;
$exportFrom = $dash['start']->format('Y-m-d'); $exportTo = $dash['end']->modify('-1 day')->format('Y-m-d');
$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$dmax = max($dash['weekdays'] ?: [0]);
$catTotal = array_sum(array_column($dash['categories'], 'value'));
$recent = array_slice(seller_sales($user['id'], 6), 0, 6);
$pipe = $dash['pipeline'];
?>
<h1 class="app-h1">Insights</h1>
<div class="dtop">
  <div class="row dtools">
    <nav class="tabs" aria-label="Period" style="margin:0"><?php foreach (DASH_RANGES as $k => $l): ?><a href="<?= e($q(['range' => $k])) ?>" class="<?= $k === $dash['range'] ? 'active' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
    <?php if (count($dash['currencies']) > 1): ?><nav class="tabs" aria-label="Currency" style="margin:0"><?php foreach ($dash['currencies'] as $c): ?><a href="<?= e($q(['cur' => $c])) ?>" class="<?= $c === $cur ? 'active' : '' ?>"><?= e($c) ?></a><?php endforeach; ?></nav><?php endif; ?>
    <a class="btn btn-outline btn-sm" href="/seller/export?kind=sales&amp;from=<?= e($exportFrom) ?>&amp;to=<?= e($exportTo) ?>">Export sales</a>
  </div>
</div>

<div class="dgrid">
  <section class="dcard dfeat c4">
    <div class="dhead"><h3>Net earnings</h3><?= dash_chip(dash_delta($n['net'], $b['net'])) ?></div>
    <p class="dbig"><?= e(dash_money($n['net'], $cur)) ?></p>
    
  </section>
  <section class="dcard c4">
    <div class="dhead"><h3>Orders</h3><?= dash_chip(dash_delta((float) $n['orders'], (float) $b['orders'])) ?></div>
    <p class="dbig"><?= number_format($n['orders']) ?></p>
    <p class="dfoot"><?= number_format($n['units']) ?> unit<?= $n['units'] === 1 ? '' : 's' ?> sold · average order <?= e(dash_money($dash['avg_order'], $cur)) ?></p>
  </section>
  <section class="dcard c4">
    <div class="dhead"><h3>Balance</h3><a class="dlink" href="/seller?tab=payouts">Withdraw</a></div>
    <p class="dbig"><?= e(dash_money($bal['available'], $cur)) ?></p>
    <div class="dbar" role="img" aria-label="Available, on hold and paid out"><span class="a" style="width:<?= $seg($bal['available']) ?>%"></span><span class="p" style="width:<?= $seg($bal['pending']) ?>%"></span><span class="o" style="width:<?= $seg($dash['paid_out']) ?>%"></span></div>
    <p class="dkey"><span class="a">Available</span><span class="p">On hold <?= e(dash_money($bal['pending'], $cur)) ?></span><span class="o">Paid out <?= e(dash_money($dash['paid_out'], $cur)) ?></span></p>
  </section>

  <section class="dcard c8">
    <div class="dhead"><h3>Sales performance</h3>
      <nav class="tabs" aria-label="Measure" style="margin:0"><?php foreach (DASH_METRICS as $k => $l): ?><a href="<?= e($q(['m' => $k])) ?>" class="<?= $k === $dash['metric'] ? 'active' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav></div>
    <p class="dkey" style="margin:0 0 6px"><span class="a">This period</span><span class="dash">Period before</span></p>
    <?= dash_chart($dash) ?>
    <?php if ($empty): ?><p class="muted small" style="text-align:center;margin:4px 0 0">No sales yet</p><?php endif; ?>
  </section>
  <section class="dcard c4">
    <div class="dhead"><h3>Sales by category</h3></div>
    <?= dash_gauge($dash['categories'], number_format($n['units']), 'units sold') ?>
    <?php if ($dash['categories']): ?><ul class="dlegend"><?php foreach ($dash['categories'] as $i => $c): ?><li><span class="sw s<?= $i ?>"></span><span class="nm"><?= e($c['name']) ?></span><span class="pc"><?= $catTotal > 0 ? round($c['value'] / $catTotal * 100) : 0 ?>%</span></li><?php endforeach; ?></ul>
    <?php else: ?><p class="muted small" style="text-align:center">Nothing yet</p><?php endif; ?>
  </section>

  <section class="dcard c5">
    <div class="dhead"><h3>Top products</h3><a class="dlink" href="/seller?tab=products">All products</a></div>
    <?php if ($dash['top']): $tmax = max(array_column($dash['top'], 'gross')); ?>
    <ol class="dtop5"><?php foreach ($dash['top'] as $p): ?><li>
      <?php if ($p['image']): ?><img class="thumb" src="<?= e(product_image_url($p['image'])) ?>" alt=""><?php else: ?><span class="thumb"></span><?php endif; ?>
      <div class="grow"><p class="nm"><?= $p['product_id'] ? '<a href="/products/edit?id=' . e($p['product_id']) . '">' . e($p['name']) . '</a>' : e($p['name']) ?></p>
        <div class="meter"><span style="width:<?= $tmax > 0 ? round($p['gross'] / $tmax * 100) : 0 ?>%"></span></div></div>
      <div class="rt"><strong><?= e(dash_money($p['gross'], $cur)) ?></strong><span class="muted small"><?= (int) $p['units'] ?> sold</span></div></li><?php endforeach; ?></ol>
    <?php else: ?><p class="muted small">Nothing yet</p><?php endif; ?>
  </section>
  <section class="dcard c7">
    <div class="dhead"><h3>Recent orders</h3><a class="dlink" href="/seller?tab=sales">All sales</a></div>
    <?php if ($recent): ?><ul class="drecent"><?php foreach ($recent as $s): ?><li>
      <div class="grow"><p class="nm"><?= e($s['name']) ?></p><p class="muted small"><?= e($s['customer_name'] ?: 'Customer') ?> · <?= e(human_date($s['placed_at'])) ?></p></div>
      <div class="rt"><strong><?= e(money($s['line_total'], $s['currency'])) ?></strong>
        <?= $s['status'] === 'refunded' ? pill('refunded') : pill($s['fulfilment'], FULFILMENT_LABEL, ['placed' => 'warn', 'processing' => 'warn', 'ready_for_shipping' => 'warn', 'completed' => 'ok']) ?></div></li><?php endforeach; ?></ul>
    <?php else: ?><p class="muted small">No orders yet.</p><?php endif; ?>
  </section>

  <section class="dcard c4">
    <div class="dhead"><h3>Order pipeline</h3><?= $n['refunded'] > 0 || $dash['return_rate'] > 0 ? '<span class="dchip down">' . e(rtrim(rtrim(number_format($dash['return_rate'], 1), '0'), '.')) . '% returned</span>' : '<span class="dchip up">No returns</span>' ?></div>
    <ul class="dpipe">
      <li><a href="/seller?tab=sales"><span class="k">New</span><strong><?= (int) $pipe['placed'] ?></strong></a></li>
      <li><a href="/seller?tab=sales"><span class="k">Processing</span><strong><?= (int) $pipe['processing'] ?></strong></a></li>
      <li><a href="/seller?tab=sales"><span class="k">Ready</span><strong><?= (int) $pipe['ready_for_shipping'] ?></strong></a></li>
      <li><a href="/seller?tab=sales"><span class="k">On the way</span><strong><?= (int) $pipe['shipped'] ?></strong></a></li>
    </ul>
    <?php $oc = (int) ($user['pending']['returns'] ?? 0); if ($oc > 0): ?><p class="small" style="margin:12px 0 0"><a href="/seller?tab=returns"><strong><?= $oc ?> return case<?= $oc === 1 ? '' : 's' ?></strong> need<?= $oc === 1 ? 's' : '' ?> your reply</a></p><?php endif; ?>
  </section>
  <section class="dcard c4">
    <div class="dhead"><h3>Best days to sell</h3></div>
    <div class="dweek" role="img" aria-label="Orders by day of the week"><?php foreach ($days as $i => $dn): $v = $dash['weekdays'][$i] ?? 0; ?>
      <div class="d<?= $dmax > 0 && $v === $dmax ? ' top' : '' ?>"><span class="bar" style="height:<?= $dmax > 0 ? max(4, round($v / $dmax * 100)) : 4 ?>%" title="<?= e($dn . ': ' . $v . ' order' . ($v === 1 ? '' : 's')) ?>"></span><span class="lb"><?= e($dn) ?></span></div><?php endforeach; ?></div>
  </section>
  <section class="dcard c4">
    <div class="dhead"><h3>Where customers are</h3></div>
    <?php if ($dash['states']): $smax = max(array_column($dash['states'], 'orders')); ?>
    <ul class="dstates"><?php foreach ($dash['states'] as $s): ?><li><span class="nm"><?= e($s['name']) ?></span><div class="meter"><span style="width:<?= round($s['orders'] / $smax * 100) ?>%"></span></div><span class="ct"><?= (int) $s['orders'] ?></span></li><?php endforeach; ?></ul>
    <?php else: ?><p class="muted small">Nothing yet</p><?php endif; ?>
  </section>

  <section class="dcard c12">
    <div class="dhead"><h3>Your store</h3>
      <span class="row"><a class="btn btn-sm" href="/products/edit"><?= $products ? 'Add product' : 'Add your first product' ?></a><a class="btn btn-outline btn-sm" href="/seller?tab=inventory">Inventory</a></span></div>
    <div class="dstore">
      <div><span class="k">Products</span><strong><?= count($products) ?></strong></div>
      <div><span class="k">Live</span><strong><?= $live ?></strong></div>
      <div><span class="k">In review</span><strong><?= $review ?></strong></div>
      <div><span class="k">Low or out of stock</span><strong><?= count($low) ?></strong></div>
      <div><span class="k">Rating</span><strong><?= $rating['average'] !== null ? e(number_format((float) $rating['average'], 1)) . ' <small>(' . (int) $rating['count'] . ')</small>' : '–' ?></strong></div>
      <div><span class="k">Level</span><strong><?= e(LEVEL_LABEL[$curLv]) ?></strong></div>
    </div>
    <?php if ($attention): ?><h4 class="dsub">Needs your attention</h4><ul class="list" style="margin-top:8px">
      <?php foreach ($attention as $p): [$k, $l] = product_visibility($p); ?><li class="row between"><a href="/products/edit?id=<?= e($p['id']) ?>"><strong><?= e($p['name']) ?></strong></a><span><?= pill($k, [], ['live' => 'ok', 'hidden' => 'warn']) ?> <?= stock_state($p) !== 'ok' ? pill(stock_state($p)) : '' ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
</div>
