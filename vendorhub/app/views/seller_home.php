<?php
/** @var array $dash @var array $user @var array $products @var array $balances @var array $level */
$cur = $dash['currency'];
$n = $dash['now']; $b = $dash['before'];
$bal = current(array_filter($balances, fn ($x) => $x['currency'] === $cur)) ?: ['available' => 0.0, 'pending' => 0.0, 'total' => 0.0];
$store = store_label($user['id'], [$user['id'] => (string) ($user['email'] ?? '')]);
$live = count(array_filter($products, fn ($p) => $p['live']));
$low = array_filter($products, fn ($p) => in_array(stock_state($p), ['low', 'out'], true));
$changes = array_filter($products, fn ($p) => in_array($p['status'], ['changes_requested', 'rejected'], true));
$attention = array_slice(array_unique(array_merge(array_values($changes), array_values($low)), SORT_REGULAR), 0, 4);
$curLv = $level['override_level'] ?: $level['auto_level'];
$recent = array_slice(seller_sales($user['id'], 5), 0, 5);
$oc = (int) ($user['pending']['returns'] ?? 0);
?>
<section class="hero-wallet">
  <div class="hw-top"><p class="hw-hello">Howdy, <strong><?= e($store) ?></strong><?= verified_tick($user['id']) ?></p><span class="hw-lv"><?= e(LEVEL_LABEL[$curLv]) ?></span><button type="button" class="hw-eye" data-hide-bal aria-label="Hide balances" aria-pressed="false"><?= icon('eye') ?></button></div>
  <p class="hw-lab">Available balance</p>
  <p class="hw-amt" data-bal><?= e(dash_money($bal['available'], $cur)) ?></p>
  <p class="hw-sub" data-bal>On hold <?= e(dash_money($bal['pending'], $cur)) ?> · Paid out <?= e(dash_money($dash['paid_out'], $cur)) ?></p>
  <div class="hw-act"><a class="hbtn hbtn-solid" href="/seller?tab=payouts"><?= icon('wallet') ?> Withdraw</a><a class="hbtn" href="/products/edit"><?= icon('plus') ?> Add product</a></div>
</section>

<div class="hsheet">
  <?php if (!empty($level['below_since']) && !$level['override_level']): $pol = level_policy(); ?>
    <p class="notice"><strong>Your <?= e(LEVEL_LABEL[$curLv]) ?> level is at risk.</strong> <?= $pol['mode'] === 'grace' ? 'Meet its requirements again by ' . e(human_date(db_plus('+' . $pol['grace_days'] . ' days', $level['below_since']), false)) . ' to keep it.' : 'You no longer meet its requirements.' ?></p>
  <?php endif; ?>
  <?php if (!empty($to_process)): ?><a class="todo" href="/seller?tab=sales"><span class="todo-ic"><?= icon('bag') ?></span><span class="grow"><strong><?= (int) $to_process ?> order<?= $to_process === 1 ? '' : 's' ?> to pack</strong><span class="muted small">Start preparing and mark them ready for pickup</span></span><?= icon('chev') ?></a><?php endif; ?>
  <?php if ($oc > 0): ?><a class="todo" href="/seller?tab=returns"><span class="todo-ic warn"><?= icon('undo') ?></span><span class="grow"><strong><?= $oc ?> return case<?= $oc === 1 ? '' : 's' ?> need<?= $oc === 1 ? 's' : '' ?> your reply</strong><span class="muted small">DDLX is waiting to hear from you</span></span><?= icon('chev') ?></a><?php endif; ?>

  <div class="sec-h"><h2>This week</h2><a href="/seller?tab=insights">See all</a></div>
  <div class="tiles">
    <a class="tile" href="/seller?tab=insights"><span class="tile-ic"><?= icon('bag') ?></span><strong><?= number_format($n['orders']) ?></strong><span class="muted small">Orders</span><?= dash_chip(dash_delta((float) $n['orders'], (float) $b['orders'])) ?></a>
    <a class="tile" href="/seller?tab=insights"><span class="tile-ic"><?= icon('wallet') ?></span><strong><?= e(dash_short($n['net'], $cur)) ?></strong><span class="muted small">Earnings</span><?= dash_chip(dash_delta($n['net'], $b['net'])) ?></a>
    <a class="tile" href="/seller?tab=products"><span class="tile-ic"><?= icon('package') ?></span><strong><?= $live ?></strong><span class="muted small">Live products</span></a>
    <a class="tile" href="/seller?tab=inventory"><span class="tile-ic<?= $low ? ' warn' : '' ?>"><?= icon('tag') ?></span><strong><?= count($low) ?></strong><span class="muted small">Low or out of stock</span></a>
  </div>

  <div class="sec-h"><h2>Recent orders</h2><a href="/seller?tab=sales">See all</a></div>
  <?php if ($recent): ?><ul class="rows"><?php foreach ($recent as $s): ?><li><a href="/seller?tab=sales">
    <span class="grow"><strong><?= e($s['name']) ?></strong><span class="muted small"><?= e($s['customer_name'] ?: 'Customer') ?> · <?= e(human_date($s['placed_at'])) ?></span></span>
    <span class="rt"><strong><?= e(money($s['line_total'], $s['currency'])) ?></strong><?= $s['status'] === 'refunded' ? pill('refunded') : pill($s['fulfilment'], FULFILMENT_LABEL, ['placed' => 'warn', 'processing' => 'warn', 'ready_for_shipping' => 'warn', 'completed' => 'ok']) ?></span></a></li><?php endforeach; ?></ul>
  <?php else: ?><div class="empty"><span class="empty-ic"><?= icon('bag') ?></span><strong>Nothing here yet</strong><span class="muted small">Orders show up here as customers buy from you on the marketplace.</span><?php if (!$products): ?><a class="btn" href="/products/edit">Add your first product</a><?php endif; ?></div><?php endif; ?>

  <?php if ($attention): ?><div class="sec-h"><h2>Needs attention</h2><a href="/seller?tab=products">Products</a></div>
  <ul class="rows"><?php foreach ($attention as $p): [$k, $l] = product_visibility($p); ?><li><a href="/products/edit?id=<?= e($p['id']) ?>"><span class="grow"><strong><?= e($p['name'] ?: 'Untitled') ?></strong><span class="muted small"><?= e($l) ?></span></span><span class="rt"><?= pill($k, [], ['live' => 'ok', 'hidden' => 'warn']) ?> <?= stock_state($p) !== 'ok' ? pill(stock_state($p)) : '' ?></span></a></li><?php endforeach; ?></ul><?php endif; ?>

  <div class="sec-h"><h2>Grow your sales</h2></div>
  <div class="promo-strip">
    <a class="pcard c1" href="/sales/edit"><span class="pc-ic"><?= icon('tag') ?></span><strong>Schedule a sale</strong><span>Set it up now, it starts and ends on its own</span></a>
    <a class="pcard c2" href="/seller?tab=promotions&amp;v=codes"><span class="pc-ic"><?= icon('gift') ?></span><strong>Discount codes</strong><span>Reward your customers</span></a>
    <a class="pcard c3" href="/seller?tab=promo"><span class="pc-ic"><?= icon('image') ?></span><strong>Promo kit</strong><span>Share your store anywhere</span></a>
  </div>
</div>
