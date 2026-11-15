<?php
/** @var array $dash @var array $user @var array $badges */
$cur = $dash['currency'];
$q = fn (array $o) => '/admin?' . http_build_query(array_merge(['tab' => 'overview', 'range' => $dash['range'], 'cur' => $cur, 'm' => $dash['metric']], $o));
$n = $dash['now']; $b = $dash['before'];
$take = $n['gross'] > 0 ? round($n['commission'] / $n['gross'] * 100, 1) : 0;
$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$dmax = max($dash['weekdays'] ?: [0]);
$catTotal = array_sum(array_column($dash['categories'], 'value'));
$pipe = $dash['pipeline'];
$exportFrom = $dash['start']->format('Y-m-d'); $exportTo = $dash['end']->modify('-1 day')->format('Y-m-d');
$empty = $n['orders'] === 0 && $b['orders'] === 0;
$queue = [
    ['Applications', (int) ($badges['applications'] ?? 0), '/admin?tab=applications'],
    ['Verifications', (int) ($badges['verifications'] ?? 0), '/admin?tab=verifications'],
    ['Products to review', (int) ($badges['products'] ?? 0), '/admin?tab=products'],
    ['Orders to pick up', (int) ($badges['orders'] ?? 0), '/admin?tab=orders&filter=ready'],
    ['Withdrawals waiting', (int) ($badges['payouts'] ?? 0), '/admin?tab=payouts'],
    ['Return cases', (int) ($badges['returns'] ?? 0), '/admin?tab=returns'],
];
$open = array_sum(array_column($queue, 1));
?>
<div class="dtop">
  <div><p class="dhello">Howdy, <strong>boss</strong></p>
    <p class="muted small"><?= e($dash['start']->format('j M Y')) ?> to <?= e($dash['end']->modify('-1 day')->format('j M Y')) ?></p></div>
  <div class="row dtools">
    <nav class="tabs" aria-label="Period" style="margin:0"><?php foreach (DASH_RANGES as $k => $l): ?><a href="<?= e($q(['range' => $k])) ?>" class="<?= $k === $dash['range'] ? 'active' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
    <?php if (count($dash['currencies']) > 1): ?><nav class="tabs" aria-label="Currency" style="margin:0"><?php foreach ($dash['currencies'] as $c): ?><a href="<?= e($q(['cur' => $c])) ?>" class="<?= $c === $cur ? 'active' : '' ?>"><?= e($c) ?></a><?php endforeach; ?></nav><?php endif; ?>
    <a class="btn btn-outline btn-sm" href="/admin/export?kind=commission&amp;from=<?= e($exportFrom) ?>&amp;to=<?= e($exportTo) ?>">Export commission</a>
  </div>
</div>

<div class="dgrid">
  <section class="dcard dfeat c4">
    <div class="dhead"><h3>Commission earned</h3><?= dash_chip(dash_delta($n['commission'], $b['commission'])) ?></div>
    <p class="dbig"><?= e(dash_money($n['commission'], $cur)) ?></p>
    <p class="dfoot"><?= e(rtrim(rtrim(number_format($take, 1), '0'), '.')) ?>% of <?= e(dash_money($n['gross'], $cur)) ?> in sales</p>
  </section>
  <section class="dcard c4">
    <div class="dhead"><h3>Marketplace sales</h3><?= dash_chip(dash_delta($n['gross'], $b['gross'])) ?></div>
    <p class="dbig"><?= e(dash_money($n['gross'], $cur)) ?></p>
    <p class="dfoot"><?= number_format($n['orders']) ?> order<?= $n['orders'] === 1 ? '' : 's' ?> · average order <?= e(dash_money($dash['avg_order'], $cur)) ?></p>
  </section>
  <section class="dcard c4">
    <div class="dhead"><h3>Owed to sellers</h3><a class="dlink" href="/admin?tab=payouts">Payouts</a></div>
    <p class="dbig"><?= e(dash_money($dash['owed'], $cur)) ?></p>
    <p class="dkey"><span class="a">Withdrawals waiting <?= e(dash_money($dash['waiting'], $cur)) ?></span><span class="o">Paid out <?= e(dash_money($dash['paid_out'], $cur)) ?></span></p>
  </section>

  <section class="dcard c8">
    <div class="dhead"><h3>Sales performance</h3>
      <nav class="tabs" aria-label="Measure" style="margin:0"><?php foreach (DASH_ADMIN_METRICS as $k => $l): ?><a href="<?= e($q(['m' => $k])) ?>" class="<?= $k === $dash['metric'] ? 'active' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav></div>
    <p class="dkey" style="margin:0 0 6px"><span class="a">This period</span><span class="dash">Period before</span></p>
    <?= dash_chart($dash) ?>
    <?php if ($empty): ?><p class="muted small" style="text-align:center;margin:4px 0 0">No sales in this period yet.</p><?php endif; ?>
  </section>
  <section class="dcard c4">
    <div class="dhead"><h3>Sales by category</h3></div>
    <?= dash_gauge($dash['categories'], number_format($n['units']), 'units sold') ?>
    <?php if ($dash['categories']): ?><ul class="dlegend"><?php foreach ($dash['categories'] as $i => $c): ?><li><span class="sw s<?= $i ?>"></span><span class="nm"><?= e($c['name']) ?></span><span class="pc"><?= $catTotal > 0 ? round($c['value'] / $catTotal * 100) : 0 ?>%</span></li><?php endforeach; ?></ul>
    <?php else: ?><p class="muted small" style="text-align:center">Nothing to split yet.</p><?php endif; ?>
  </section>

  <section class="dcard c7">
    <div class="dhead"><h3>Needs you</h3><span class="dchip <?= $open > 0 ? 'down' : 'up' ?>"><?= $open > 0 ? $open . ' open' : 'All clear' ?></span></div>
    <ul class="dpipe dqueue"><?php foreach ($queue as [$label, $count, $href]): ?>
      <li><a href="<?= e($href) ?>" class="<?= $count > 0 ? 'hot' : '' ?>"><span class="k"><?= e($label) ?></span><strong><?= $count ?></strong></a></li><?php endforeach; ?></ul>
  </section>
  <section class="dcard c5">
    <div class="dhead"><h3>Order pipeline</h3><a class="dlink" href="/admin?tab=orders">Orders</a></div>
    <ul class="dpipe">
      <li><a href="/admin?tab=orders"><span class="k">New</span><strong><?= (int) $pipe['placed'] ?></strong></a></li>
      <li><a href="/admin?tab=orders"><span class="k">Processing</span><strong><?= (int) $pipe['processing'] ?></strong></a></li>
      <li><a href="/admin?tab=orders&amp;filter=ready"><span class="k">Ready for pickup</span><strong><?= (int) $pipe['ready_for_shipping'] ?></strong></a></li>
      <li><a href="/admin?tab=orders"><span class="k">On the way</span><strong><?= (int) $pipe['shipped'] ?></strong></a></li>
    </ul>
    <p class="small muted" style="margin:12px 0 0"><?= e(rtrim(rtrim(number_format($dash['return_rate'], 1), '0'), '.')) ?>% of items returned or refunded in this period</p>
  </section>

  <section class="dcard c5">
    <div class="dhead"><h3>Top sellers</h3><a class="dlink" href="/admin?tab=sellers">All sellers</a></div>
    <?php if ($dash['top_sellers']): $smax = max(array_column($dash['top_sellers'], 'gross')); ?>
    <ol class="dtop5"><?php foreach ($dash['top_sellers'] as $s): ?><li>
      <div class="grow"><p class="nm"><?= e($s['name']) ?></p><div class="meter"><span style="width:<?= $smax > 0 ? round($s['gross'] / $smax * 100) : 0 ?>%"></span></div></div>
      <div class="rt"><strong><?= e(dash_money($s['gross'], $cur)) ?></strong><span class="muted small"><?= (int) $s['orders'] ?> order<?= $s['orders'] === 1 ? '' : 's' ?> · <?= e(dash_money($s['commission'], $cur)) ?> fees</span></div></li><?php endforeach; ?></ol>
    <?php else: ?><p class="muted small">Your best sellers appear here once sales come in.</p><?php endif; ?>
  </section>
  <section class="dcard c7">
    <div class="dhead"><h3>Best selling products</h3></div>
    <?php if ($dash['top']): $tmax = max(array_column($dash['top'], 'gross')); ?>
    <ol class="dtop5"><?php foreach ($dash['top'] as $p): ?><li>
      <?php if ($p['image']): ?><img class="thumb" src="<?= e(product_image_url($p['image'])) ?>" alt=""><?php else: ?><span class="thumb"></span><?php endif; ?>
      <div class="grow"><p class="nm"><?= e($p['name']) ?></p><div class="meter"><span style="width:<?= $tmax > 0 ? round($p['gross'] / $tmax * 100) : 0 ?>%"></span></div></div>
      <div class="rt"><strong><?= e(dash_money($p['gross'], $cur)) ?></strong><span class="muted small"><?= (int) $p['units'] ?> sold</span></div></li><?php endforeach; ?></ol>
    <?php else: ?><p class="muted small">Nothing sold in this period yet.</p><?php endif; ?>
  </section>

  <section class="dcard c4">
    <div class="dhead"><h3>Best days to sell</h3></div>
    <div class="dweek" role="img" aria-label="Orders by day of the week"><?php foreach ($days as $i => $dn): $v = $dash['weekdays'][$i] ?? 0; ?>
      <div class="d<?= $dmax > 0 && $v === $dmax ? ' top' : '' ?>"><span class="bar" style="height:<?= $dmax > 0 ? max(4, round($v / $dmax * 100)) : 4 ?>%" title="<?= e($dn . ': ' . $v . ' order' . ($v === 1 ? '' : 's')) ?>"></span><span class="lb"><?= e($dn) ?></span></div><?php endforeach; ?></div>
  </section>
  <section class="dcard c4">
    <div class="dhead"><h3>Where customers are</h3></div>
    <?php if ($dash['states']): $xmax = max(array_column($dash['states'], 'orders')); ?>
    <ul class="dstates"><?php foreach ($dash['states'] as $s): ?><li><span class="nm"><?= e($s['name']) ?></span><div class="meter"><span style="width:<?= round($s['orders'] / $xmax * 100) ?>%"></span></div><span class="ct"><?= (int) $s['orders'] ?></span></li><?php endforeach; ?></ul>
    <?php else: ?><p class="muted small">Delivery locations appear here once there are orders.</p><?php endif; ?>
  </section>
  <section class="dcard c4">
    <div class="dhead"><h3>Marketplace</h3></div>
    <div class="dmini">
      <div><span class="k">Verified sellers</span><strong><?= (int) $dash['verified_sellers'] ?></strong></div>
      <div><span class="k">Selling this period</span><strong><?= (int) $dash['active_sellers'] ?></strong></div>
      <div><span class="k">New sellers</span><strong><?= (int) $dash['new_sellers'] ?></strong><?= dash_chip(dash_delta((float) $dash['new_sellers'], (float) $dash['new_before'])) ?></div>
      <div><span class="k">Live products</span><strong><?= (int) $dash['live_products'] ?></strong></div>
    </div>
  </section>
</div>
