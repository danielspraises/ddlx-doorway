<?php
/** @var string $tab @var array $tabs @var array $user @var string $sel */
$badges = $user['pending'];
$nav = [];
foreach ($tabs as $k => $l) $nav[$k] = $l;
$m = fn ($x, $c = 'NGN') => money($x, $c);
$decision = function (string $action, string $id, string $expected, array $options, string $confirm = '') : string {
    $h = '<form method="post" action="' . e($action) . '" class="card" style="margin-top:16px" data-keep>' . csrf_field()
        . '<input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="expected" value="' . e($expected) . '">'
        . '<div class="field"><label for="c-' . e($id) . '">Feedback</label><textarea id="c-' . e($id) . '" name="comment" rows="3" maxlength="2000"></textarea><span class="hint">Required unless you approve.</span></div><div class="row">';
    foreach ($options as [$name, $value, $label, $cls]) $h .= '<button class="btn ' . $cls . '" type="submit" name="' . $name . '" value="' . $value . '" data-confirm="' . e($label) . ' — confirm this decision?">' . e($label) . '</button>';
    return $h . '</div></form>';
};
$docLinks = function (array $docs): string {
    $out = '';
    foreach (VERIFY_STEPS as $s) {
        if ($s['file'] === '') continue;
        $p = $docs[$s['file']] ?? null;
        $out .= '<dt>' . e($s['label']) . '</dt><dd>' . ($p ? '<a href="/files/verification?p=' . e(rawurlencode($p)) . '" data-preview="/files/verification?p=' . e(rawurlencode($p)) . '" data-title="' . e($s['label']) . '" data-type="' . (str_ends_with($p, '.pdf') ? 'pdf' : 'image') . '">Open</a>' : '<span class="muted">Not provided</span>') . '</dd>';
    }
    return $out;
};
?>
<p class="eyebrow"><?= $user['isAdmin'] ? 'Admin' : 'Staff desk' ?></p>
<h1><?= e($tabs[$tab]) ?></h1>
<?= tabs_nav($nav, $tab, '/admin', ['applications' => $badges['applications'], 'verifications' => $badges['verifications'], 'products' => $badges['products'], 'payouts' => $badges['payouts'], 'orders' => $badges['orders'] ?? 0, 'returns' => $badges['returns'] ?? 0]) ?>

<?php if ($tab === 'overview'): $top = $o['sales'][0] ?? null; $cur = $top['currency'] ?? 'NGN'; ?>
  <p class="muted">The last 30 days across the whole marketplace.</p>
  <div class="grid g4" style="margin-top:16px">
    <div class="stat"><p class="muted small">Sales (<?= e($cur) ?>)</p><p class="n"><?= e($m($top['gross'] ?? 0, $cur)) ?></p></div>
    <div class="stat"><p class="muted small">Your commission</p><p class="n"><?= e($m($top['commission'] ?? 0, $cur)) ?></p></div>
    <div class="stat"><p class="muted small">Orders</p><p class="n"><?= (int) ($top['orders'] ?? 0) ?></p></div>
    <div class="stat"><p class="muted small">Verified sellers</p><p class="n"><?= (int) $o['sellers'] ?></p></div>
  </div>
  <h2>Sales per day</h2>
  <div class="card"><?= bar_chart($o['chart']['series'], $o['chart']['currency']) ?></div>
  <h2>Needs you</h2>
  <div class="grid g4">
    <a class="stat" href="/admin?tab=applications"><p class="muted small">Applications</p><p class="n"><?= (int) $badges['applications'] ?></p></a>
    <a class="stat" href="/admin?tab=verifications"><p class="muted small">Verifications</p><p class="n"><?= (int) $badges['verifications'] ?></p></a>
    <a class="stat" href="/admin?tab=products"><p class="muted small">Products to review</p><p class="n"><?= (int) $badges['products'] ?></p></a>
    <a class="stat" href="/admin?tab=orders&filter=ready"><p class="muted small">Orders to pick up</p><p class="n"><?= (int) $badges['orders'] ?></p></a>
    <a class="stat" href="/admin?tab=orders"><p class="muted small">New, not yet processed</p><p class="n"><?= (int) $o['new_orders'] ?></p></a>
    <a class="stat" href="/admin?tab=payouts"><p class="muted small">Withdrawals waiting</p><p class="n"><?= (int) $badges['payouts'] ?></p></a>
    <div class="stat"><p class="muted small">Owed to sellers</p><p class="n"><?= e($m($o['owed'][$cur] ?? 0, $cur)) ?></p></div>
    <div class="stat"><p class="muted small">Live products</p><p class="n"><?= (int) $o['live_products'] ?></p></div>
  </div>
  <?php if ($o['by_category']): ?><h2>Commission by category <a class="small" href="/admin/export?kind=commission&from=<?= e(gmdate('Y-m-d', strtotime('-30 days'))) ?>&to=<?= e(gmdate('Y-m-d')) ?>">Download CSV</a></h2><div class="table-wrap"><table><thead><tr><th>Category</th><th class="t-right">Units</th><th class="t-right">Sales</th><th class="t-right">Commission</th></tr></thead><tbody>
    <?php foreach ($o['by_category'] as $t): ?><tr><td><?= e($t['category']) ?></td><td class="t-right"><?= (int) $t['units'] ?></td><td class="t-right"><?= e($m($t['gross'], $t['currency'])) ?></td><td class="t-right"><?= e($m($t['commission'], $t['currency'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <?php if ($o['top']): ?><h2>Best sellers</h2><div class="table-wrap"><table><thead><tr><th>Product</th><th class="t-right">Units</th><th class="t-right">Revenue</th></tr></thead><tbody>
    <?php foreach ($o['top'] as $t): ?><tr><td><?= e($t['name']) ?></td><td class="t-right"><?= (int) $t['units'] ?></td><td class="t-right"><?= e($m($t['revenue'], $t['currency'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'applications'):
    $filters = ['pending' => 'Under review', 'all' => 'All'];
    $f = $filter ?: 'pending';
    $list = array_values(array_filter($items, fn ($a) => $f === 'all' || $a['status'] === 'submitted'));
    $selected = null; foreach ($items as $a) if ($a['id'] === $sel) $selected = $a; ?>
  <?php if ($selected): $a = $selected; ?>
    <p><a href="/admin?tab=applications">← Applications</a></p>
    <h2 style="margin-top:0"><?= e(($a['personal']['first_name'] ?? '') . ' ' . ($a['personal']['last_name'] ?? '')) ?> <?= pill($a['status'], APP_STATUS_LABEL) ?></h2>
    <p class="muted"><?= e((string) $a['applicant_email']) ?> · submitted <?= e(human_date($a['submitted_at'], true)) ?></p>
    <?php for ($n = 1; $n <= 4; $n++): ?><h3><?= e(APP_STEPS[$n][1]) ?></h3><dl class="dl"><?php foreach (summary_rows($n, $a[APP_STEPS[$n][0]]) as [$k, $val]): ?><dt><?= e($k) ?></dt><dd><?= e($val) ?></dd><?php endforeach; ?></dl><?php endfor; ?>
    <?php if ($a['admin_comment']): ?><div class="notice"><h3>Previous feedback</h3><p><?= nl2br(e($a['admin_comment'])) ?></p></div><?php endif; ?>
    <?php if ($a['status'] === 'submitted') echo $decision('/admin/application', $a['id'], $a['updated_at'], [['status', 'approved', 'Approve', ''], ['status', 'needs_corrections', 'Request corrections', 'btn-outline'], ['status', 'rejected', 'Reject', 'btn-danger']]); ?>
  <?php else: ?>
    <div class="row" style="margin:16px 0"><?php foreach ($filters as $k => $l): ?><a class="btn btn-sm <?= $f === $k ? '' : 'btn-outline' ?>" href="/admin?tab=applications&filter=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
    <ul class="list"><?php foreach ($list as $a): ?><li class="row between"><div><a href="/admin?tab=applications&id=<?= e($a['id']) ?>"><strong><?= e(($a['personal']['first_name'] ?? '') . ' ' . ($a['personal']['last_name'] ?? '')) ?></strong></a><div class="muted small"><?= e((string) $a['applicant_email']) ?> · <?= e($a['brand']['store_name'] ?? '') ?> · <?= e(human_date($a['submitted_at'])) ?></div></div><?= pill($a['status'], APP_STATUS_LABEL) ?></li><?php endforeach; ?>
      <?php if (!$list): ?><li class="muted">Nothing here.</li><?php endif; ?></ul>
  <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'verifications'):
    $f = $filter ?: 'pending';
    $list = array_values(array_filter($items, fn ($a) => $f === 'all' || $a['status'] === 'submitted'));
    $selected = null; foreach ($items as $a) if ($a['id'] === $sel) $selected = $a; ?>
  <?php if ($selected): $v = $selected; ?>
    <p><a href="/admin?tab=verifications">← Verifications</a></p>
    <h2 style="margin-top:0"><?= e($v['app_brand']['store_name'] ?? 'Seller') ?> <?= pill($v['status'], APP_STATUS_LABEL) ?></h2>
    <p class="muted"><?= e((string) $v['applicant_email']) ?></p>
    <h3>Documents</h3><dl class="dl"><?= $docLinks($v['documents']) ?></dl>
    <h3>Details</h3><dl class="dl"><dt>Business presence</dt><dd><?= e($v['details']['business_presence'] ?? '—') ?></dd><dt>Social accounts confirmed</dt><dd><?= ($v['details']['social_verified'] ?? '') === 'yes' ? 'Yes' : 'No' ?></dd><dt>Address confirmed</dt><dd><?= ($v['details']['address_confirmed'] ?? '') === 'yes' ? 'Yes' : 'No' ?></dd><dt>Registered business</dt><dd><?= e($v['app_registration']['registered'] ?? '—') ?> <?= e($v['app_registration']['reg_number'] ?? '') ?></dd></dl>
    <h3>Application</h3><dl class="dl"><?php foreach (summary_rows(1, $v['app_personal']) as [$k, $val]): ?><dt><?= e($k) ?></dt><dd><?= e($val) ?></dd><?php endforeach; ?></dl>
    <?php if ($v['admin_comment']): ?><div class="notice"><h3>Previous feedback</h3><p><?= nl2br(e($v['admin_comment'])) ?></p></div><?php endif; ?>
    <?php if ($v['status'] === 'submitted') echo $decision('/admin/verification', $v['id'], $v['updated_at'], [['status', 'approved', 'Approve', ''], ['status', 'needs_corrections', 'Request corrections', 'btn-outline'], ['status', 'rejected', 'Reject', 'btn-danger']]); ?>
  <?php else: ?>
    <div class="row" style="margin:16px 0"><?php foreach (['pending' => 'Under review', 'all' => 'All'] as $k => $l): ?><a class="btn btn-sm <?= $f === $k ? '' : 'btn-outline' ?>" href="/admin?tab=verifications&filter=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
    <ul class="list"><?php foreach ($list as $v): ?><li class="row between"><div><a href="/admin?tab=verifications&id=<?= e($v['id']) ?>"><strong><?= e($v['app_brand']['store_name'] ?? 'Seller') ?></strong></a><div class="muted small"><?= e((string) $v['applicant_email']) ?> · <?= e(human_date($v['submitted_at'])) ?></div></div><?= pill($v['status'], APP_STATUS_LABEL) ?></li><?php endforeach; ?>
      <?php if (!$list): ?><li class="muted">Nothing here.</li><?php endif; ?></ul>
  <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'products'):
    $f = $filter ?: 'pending';
    $list = array_values(array_filter($items, fn ($p) => $f === 'all' || ($f === 'live' ? $p['live'] : $p['status'] === 'pending_review')));
    $selected = null; foreach ($items as $p) if ($p['id'] === $sel) $selected = $p; ?>
  <?php if ($selected): $p = $selected; $problems = readiness_problems($p); ?>
    <p><a href="/admin?tab=products">← Products</a></p>
    <h2 style="margin-top:0"><?= e($p['name']) ?> <?= pill($p['status'], PRODUCT_STATUS_LABEL) ?> <?= $p['live'] ? pill('live') : '' ?></h2>
    <p class="muted"><?= e($p['store_name']) ?> · <?= e($p['seller_email']) ?></p>
    <div class="imgs"><?php foreach (array_filter(array_merge([$p['featured_image']], $p['gallery'])) as $path): ?><a href="<?= e(product_image_url($path)) ?>" data-preview="<?= e(product_image_url($path)) ?>" data-title="<?= e($p['name']) ?>"><img class="thumb" style="width:90px;height:90px" src="<?= e(product_image_url($path)) ?>" alt=""></a><?php endforeach; ?></div>
    <dl class="dl"><dt>SKU</dt><dd><?= e($p['sku']) ?></dd><dt>Category</dt><dd><?= e(category_label($p)) ?></dd><dt>Brand</dt><dd><?= e($p['brand'] ?: '—') ?></dd><dt>Price</dt><dd><?= e(price_label($p)) ?><?= $p['sale_price'] !== null && $p['product_type'] === 'simple' ? ' (regular ' . e($m($p['regular_price'], $p['currency'])) . ')' : '' ?></dd>
      <dt>Stock</dt><dd><?= $p['product_type'] === 'variable' ? array_sum(array_column($p['variations'], 'stock')) : (int) $p['stock'] ?></dd>
      <dt>Shipping</dt><dd><?= e((string) $p['weight_kg']) ?> kg · <?= e((string) $p['length_cm']) ?> × <?= e((string) $p['width_cm']) ?> × <?= e((string) $p['height_cm']) ?> cm</dd>
      <dt>Description</dt><dd><?= nl2br(e($p['short_description'] . ($p['short_description'] && $p['description'] ? "\n\n" : '') . $p['description'])) ?></dd></dl>
    <?php if ($p['variations']): ?><h3>Variations</h3><div class="table-wrap"><table><thead><tr><th>Options</th><th>SKU</th><th class="t-right">Price</th><th class="t-right">Stock</th></tr></thead><tbody><?php foreach ($p['variations'] as $v): ?><tr><td><?= e(implode(' / ', $v['options'])) ?><?= $v['enabled'] ? '' : ' (off)' ?></td><td><?= e($v['sku']) ?></td><td class="t-right"><?= e($m($v['sale_price'] ?? $v['regular_price'] ?? 0, $p['currency'])) ?></td><td class="t-right"><?= (int) $v['stock'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php if ($p['admin_comment']): ?><div class="notice"><h3>Previous note</h3><p><?= nl2br(e($p['admin_comment'])) ?></p></div><?php endif; ?>
    <?php if ($p['status'] === 'pending_review'): ?>
      <?php if ($problems): ?><div class="notice"><h3>Not ready to approve</h3><p><?= e(implode(' ', $problems)) ?></p></div><?php endif; ?>
      <?= $decision('/admin/product', $p['id'], $p['updated_raw'], array_merge($problems ? [] : [['decision', 'approve', 'Approve and publish', '']], [['decision', 'request_changes', 'Request changes', 'btn-outline'], ['decision', 'reject', 'Reject', 'btn-danger']])) ?>
    <?php elseif ($p['live']): ?>
      <?= $decision('/admin/product', $p['id'], $p['updated_raw'], [['decision', 'take_down', 'Take off marketplace', 'btn-danger']]) ?>
    <?php endif; ?>
  <?php else: ?>
    <div class="row" style="margin:16px 0"><?php foreach (['pending' => 'Waiting', 'live' => 'Live', 'all' => 'All'] as $k => $l): ?><a class="btn btn-sm <?= $f === $k ? '' : 'btn-outline' ?>" href="/admin?tab=products&filter=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
    <ul class="list"><?php foreach ($list as $p): ?><li class="row between"><div class="row" style="flex-wrap:nowrap"><?php if ($p['featured_image']): ?><img class="thumb" src="<?= e(product_image_url($p['featured_image'])) ?>" alt=""><?php else: ?><span class="thumb"></span><?php endif; ?>
      <div><a href="/admin?tab=products&id=<?= e($p['id']) ?>"><strong><?= e($p['name']) ?></strong></a><div class="muted small"><?= e($p['store_name']) ?> · <?= e(category_label($p)) ?> · <?= e(price_label($p)) ?></div></div></div><span><?= pill($p['status'], PRODUCT_STATUS_LABEL) ?> <?= $p['live'] ? pill('live') : '' ?></span></li><?php endforeach; ?>
      <?php if (!$list): ?><li class="muted">Nothing here.</li><?php endif; ?></ul>
  <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'returns'): ?>
  <p class="muted">Customer complaints. Open a case from the Orders tab; the seller answers here, you decide.</p>
  <div class="row" style="margin:16px 0"><?php foreach (['open' => 'Open', 'done' => 'Closed', 'all' => 'All'] as $k => $l): ?><a class="btn btn-sm <?= $filter === $k ? '' : 'btn-outline' ?>" href="/admin?tab=returns&filter=<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?></div>
  <ul class="list"><?php foreach ($items as $c): ?><li>
    <div class="row between" style="align-items:flex-start;gap:16px"><div>
      <strong><?= e($c['item_name']) ?></strong> · order <?= e($c['number'] ?: $c['marketplace_order_id']) ?> · <?= e($m($c['line_total'], $c['currency'])) ?> <?= pill($c['status'], RETURN_LABEL, RETURN_KINDS) ?>
      <div class="muted small"><?= e($c['seller_email']) ?> · <?= e($c['customer_name'] ?: 'Customer') ?> · opened <?= e(human_date($c['created_at'], true)) ?></div>
      <div class="small" style="margin-top:6px"><strong><?= e($c['reason']) ?></strong><?= $c['details'] !== '' ? ' — ' . e($c['details']) : '' ?></div>
      <?php if ($c['seller_response'] !== ''): ?><div class="small" style="margin-top:6px"><span class="muted">Seller:</span> <?= e($c['seller_response']) ?></div><?php endif; ?>
      <?php if ($c['resolution_note'] !== ''): ?><div class="small" style="margin-top:6px"><span class="muted">Decision:</span> <?= e($c['resolution_note']) ?></div><?php endif; ?></div>
    <?php if (!in_array($c['status'], RETURN_FINAL, true)): ?><form method="post" action="/admin/return/resolve" class="stack" style="min-width:260px" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
      <input name="note" maxlength="500" placeholder="Note to the seller (required to reject)" aria-label="Note">
      <label class="check"><input type="checkbox" name="restock" value="yes"><span>Item comes back to stock</span></label>
      <div class="row"><button class="btn btn-sm" name="decision" value="refund" type="submit" data-confirm="Refund this item and take the earnings back from the seller?">Refund</button><button class="btn btn-sm btn-outline" name="decision" value="reject" type="submit" data-confirm="Reject this case?">Reject</button></div>
      <span class="muted small">Refund reverses the seller's earnings. Return the customer's money in WooCommerce.</span></form><?php endif; ?></div></li><?php endforeach; ?>
    <?php if (!$items): ?><li class="muted">No cases here.</li><?php endif; ?></ul>
<?php endif; ?>

<?php if ($tab === 'orders'): ?>
  <p class="muted">Move orders from here or from WooCommerce. Either way both places stay in step. Sellers pack their items in Vendor Hub; you collect, ship and close the order.</p>
  <form method="get" action="/admin/export" class="row" style="margin:8px 0" data-keep><input type="hidden" name="kind" value="orders"><label class="small muted" for="oe-from">Download as spreadsheet, from</label><input id="oe-from" type="date" name="from" style="width:auto"><label class="small muted" for="oe-to">to</label><input id="oe-to" type="date" name="to" style="width:auto"><button class="btn btn-outline btn-sm" type="submit">Download CSV</button></form>
  <div class="row" style="margin:16px 0"><?php foreach (ADMIN_ORDER_FILTERS as $k => $l): ?><a class="btn btn-sm <?= $filter === $k ? '' : 'btn-outline' ?>" href="/admin?tab=orders&filter=<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?></div>
  <ul class="list"><?php foreach ($items as $o): $state = $o['state']; ?><li>
    <div class="row between" style="align-items:flex-start;gap:16px"><div>
      <strong><?= $o['number'] ? '#' . e($o['number']) : 'Order ' . e($o['marketplace_order_id']) ?></strong> · <?= e($m($o['total'], $o['currency'])) ?>
      <?= $state === 'refunded' ? pill('refunded') : pill($state, FULFILMENT_LABEL, ['placed' => 'warn', 'processing' => 'warn', 'ready_for_shipping' => 'warn', 'completed' => 'ok']) ?>
      <div class="muted small"><?= e(human_date($o['placed_at'], true)) ?> · <?= e($o['customer_name'] ?: 'Customer') ?> · <?= e(trim($o['ship_city'] . ', ' . $o['ship_state'], ', ')) ?></div>
      <div class="small" style="margin-top:6px"><?php foreach ($o['items'] as $it): ?><?= (int) $it['quantity'] ?> × <?= e($it['name']) ?><?= $it['status'] === 'refunded' ? ' (refunded)' : ($it['fulfilment'] !== $state && $state !== 'refunded' ? ' <span class="muted">(' . e(strtolower(FULFILMENT_LABEL[$it['fulfilment']])) . ')</span>' : '') ?><br><?php endforeach; ?></div>
      <div class="muted small">Seller<?= count($o['seller_labels']) > 1 ? 's' : '' ?>: <?= e(implode(', ', $o['seller_labels'])) ?><?= $o['carrier'] ? ' · ' . e(trim($o['carrier'] . ' ' . $o['tracking'])) : '' ?></div></div>
    <?php if ($state !== 'refunded' && $state !== 'completed'): ?><div style="min-width:280px">
      <?php if (FULFILMENT_RANK[$state] < FULFILMENT_RANK['shipped']): ?>
        <form method="post" action="/admin/order" class="stack" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($o['id']) ?>"><input type="hidden" name="to" value="shipped"><input type="hidden" name="filter" value="<?= e($filter) ?>">
          <?php if ($state !== 'ready_for_shipping'): ?><span class="muted small">Not packed by every seller yet. You can still ship it.</span><?php endif; ?>
          <select name="carrier" aria-label="Carrier"><?php foreach (CARRIERS as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select>
          <input name="tracking" maxlength="100" placeholder="Tracking number" aria-label="Tracking number">
          <button class="btn btn-sm" type="submit" data-confirm="Mark this order as picked up and shipped?">Picked up &amp; shipped</button></form>
      <?php elseif ($state === 'shipped'): ?>
        <form method="post" action="/admin/order" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($o['id']) ?>"><input type="hidden" name="to" value="delivered"><input type="hidden" name="filter" value="<?= e($filter) ?>"><button class="btn btn-sm" type="submit" data-confirm="Mark as delivered? This starts the sellers' payout clock.">Mark delivered</button></form>
      <?php elseif ($state === 'delivered'): ?>
        <form method="post" action="/admin/order" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($o['id']) ?>"><input type="hidden" name="to" value="completed"><input type="hidden" name="filter" value="<?= e($filter) ?>"><button class="btn btn-sm" type="submit" data-confirm="Close this order as completed?">Mark completed</button></form>
      <?php endif; ?>
      <details style="margin-top:8px"><summary class="muted small">Open a return case</summary>
        <form method="post" action="/admin/return/open" class="stack" data-keep style="margin-top:8px"><?= csrf_field() ?>
          <select name="item_id" aria-label="Item"><?php foreach ($o['items'] as $it): if ($it['status'] === 'refunded') continue; ?><option value="<?= e($it['id']) ?>"><?= e($it['name']) ?></option><?php endforeach; ?></select>
          <select name="reason" aria-label="Reason"><?php foreach (RETURN_REASONS as $rr): ?><option><?= e($rr) ?></option><?php endforeach; ?></select>
          <textarea name="details" rows="2" maxlength="2000" placeholder="What the customer reported" aria-label="Details"></textarea>
          <button class="btn btn-sm btn-outline" type="submit">Open case and tell the seller</button></form></details>
      <details style="margin-top:8px"><summary class="muted small"><?= FULFILMENT_RANK[$state] < FULFILMENT_RANK['shipped'] ? 'Cancel or refund' : 'Refund' ?></summary>
        <form method="post" action="/admin/order" class="stack" data-keep style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($o['id']) ?>"><input type="hidden" name="filter" value="<?= e($filter) ?>">
          <input name="note" maxlength="200" placeholder="Reason (optional)" aria-label="Reason">
          <label class="check"><input type="checkbox" name="restock" value="yes" checked><span>Put the items back in stock</span></label>
          <p class="muted small" style="margin:0">This reverses the sellers' earnings and updates WooCommerce. Returning the customer's money is still done in your payment gateway in WooCommerce.</p>
          <div class="row"><?php if (FULFILMENT_RANK[$state] < FULFILMENT_RANK['shipped']): ?><button class="btn btn-sm btn-outline" name="to" value="cancelled" type="submit" data-confirm="Cancel this order?">Cancel order</button><?php endif; ?>
          <button class="btn btn-sm btn-danger" name="to" value="refunded" type="submit" data-confirm="Mark this order as refunded and reverse the sellers' earnings?">Refund</button></div></form></details>
    </div><?php endif; ?></div></li><?php endforeach; ?>
    <?php if (!$items): ?><li class="muted">No orders here.</li><?php endif; ?></ul>
<?php endif; ?>

<?php if ($tab === 'payouts'): ?>
  <p class="muted">Pay the seller from your bank or Paystack dashboard, then enter the transfer reference here. <a href="/admin/export?kind=payouts">Download all withdrawals (CSV)</a></p>
  <ul class="list"><?php foreach ($items as $w): ?><li>
    <div class="row between"><div><strong><?= e($w['store_name']) ?></strong> <span class="muted">· <?= e($w['seller_email']) ?></span>
      <div>Pay <strong><?= e($m((float) $w['amount'] - (float) $w['fee'], $w['currency'])) ?></strong><?= (float) $w['fee'] > 0 ? ' <span class="muted small">(' . e($m($w['amount'], $w['currency'])) . ' less ' . e($m($w['fee'], $w['currency'])) . ' fee)</span>' : '' ?></div>
      <div class="muted small"><?= e($w['bank_name']) ?> · <?= e($w['account_number']) ?> · <?= e($w['account_name']) ?></div>
      <div class="muted small">Requested <?= e(human_date($w['requested_at'], true)) ?><?= $w['reference'] ? ' · Ref ' . e($w['reference']) : '' ?><?= $w['admin_note'] ? ' · ' . e($w['admin_note']) : '' ?></div></div>
      <?php if ($w['status'] === 'requested'): ?>
      <form method="post" action="/admin/payout" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($w['id']) ?>"><input name="text" placeholder="Transfer reference or reason" maxlength="500" style="width:230px" aria-label="Transfer reference or reason" required>
        <button class="btn btn-sm" name="decision" value="paid" data-confirm="Mark as paid?">Mark paid</button><button class="btn btn-sm btn-danger" name="decision" value="rejected" data-confirm="Reject and return the money to the seller's wallet?">Reject</button></form>
      <?php else: echo pill($w['status'], WITHDRAWAL_LABEL); endif; ?></div></li><?php endforeach; ?>
    <?php if (!$items): ?><li class="muted">No withdrawal requests yet.</li><?php endif; ?></ul>
<?php endif; ?>

<?php if ($tab === 'categories'): $flat = category_flat($all); ?>
  <p class="muted"><?= count($all) ?> categories · up to <?= MAX_CATEGORY_DEPTH ?> levels. Sellers pick the most specific one.</p>
  <form method="post" action="/admin/category" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="create"><input name="name" placeholder="New top-level category" maxlength="80" required style="max-width:300px"><button class="btn">Add</button></form>
  <ul class="tree" style="margin-top:16px"><?php foreach ($flat as $row): $c = $row['category']; $d = $row['depth']; ?>
    <li style="padding-left:<?= $d * 22 ?>px"><details><summary><span class="name<?= $c['active'] ? '' : ' off' ?>"><?= e($c['name']) ?></span> <span class="muted small"><?= (int) $c['product_count'] ?> product<?= $c['product_count'] === 1 ? '' : 's' ?><?= $c['active'] ? '' : ' · hidden' ?><?= $c['commission_percent'] !== null ? ' · ' . e((string) $c['commission_percent']) . '% commission' : '' ?></span></summary>
      <div class="row" style="margin:10px 0">
        <?php if ($d < MAX_CATEGORY_DEPTH - 1): ?><form method="post" action="/admin/category" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="parent_id" value="<?= e($c['id']) ?>"><input name="name" placeholder="New sub-category" maxlength="80" required><button class="btn btn-sm btn-outline">Add sub</button></form><?php endif; ?>
        <form method="post" action="/admin/category" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="commission"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><input name="commission" value="<?= $c['commission_percent'] === null ? '' : e((string) $c['commission_percent']) ?>" placeholder="<?= e((string) commission_rate_for($c['parent_id'])) ?>% (inherited)" inputmode="decimal" aria-label="Commission percent" style="max-width:150px"><button class="btn btn-sm btn-outline">Set commission</button></form>
        <form method="post" action="/admin/category" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><input name="name" value="<?= e($c['name']) ?>" maxlength="80" required><button class="btn btn-sm btn-outline">Rename</button></form>
        <form method="post" action="/admin/category" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
          <select name="to" aria-label="Move to"><option value="root">Top level</option><?php $ban = category_descendant_ids($c['id'], $all); foreach ($flat as $o) { if (in_array($o['category']['id'], $ban, true)) continue; echo '<option value="' . e($o['category']['id']) . '">' . e(str_repeat('— ', $o['depth']) . $o['category']['name']) . '</option>'; } ?></select><button class="btn btn-sm btn-outline">Move</button></form>
        <form method="post" action="/admin/category" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn btn-sm btn-ghost" name="action" value="<?= $c['active'] ? 'hide' : 'show' ?>"><?= $c['active'] ? 'Hide' : 'Show' ?></button>
          <?php if (!category_children($c['id'], $all) && !$c['product_count']): ?><button class="btn btn-sm btn-danger" name="action" value="delete" data-confirm="Delete “<?= e($c['name']) ?>”?">Delete</button><?php endif; ?></form>
      </div></details></li><?php endforeach; ?></ul>
<?php endif; ?>

<?php if ($tab === 'codes'): ?>
  <p class="muted">Codes created by sellers. The seller funds each discount. Switch a code off if it is being misused.</p>
  <div class="table-wrap"><table><thead><tr><th>Code</th><th>Seller</th><th>Discount</th><th>Status</th><th class="t-right">Used</th><th class="t-right">Given away</th><th></th></tr></thead><tbody>
  <?php foreach ($items as $c): ?><tr><td><strong><?= e($c['code']) ?></strong></td><td><?= e($c['store']) ?></td>
    <td><?= $c['kind'] === 'percent' ? e((string) $c['amount']) . '%' : e($c['currency'] . ' ' . number_format($c['amount'], 2)) . ' each' ?></td>
    <td><?= e(COUPON_STATE_LABEL[$c['state']] ?? $c['state']) ?></td><td class="t-right"><?= (int) $c['used_count'] ?></td><td class="t-right"><?= e(number_format($c['discount_total'], 2)) ?></td>
    <td><form method="post" action="/admin/code" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn btn-sm btn-outline" name="action" value="<?= $c['admin_disabled'] ? 'allow' : 'block' ?>"><?= $c['admin_disabled'] ? 'Switch on' : 'Switch off' ?></button></form></td></tr>
  <?php endforeach; if (!$items): ?><tr><td colspan="7" class="muted">No codes yet.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>

<?php if ($tab === 'sellers'): ?>
  <form method="get" action="/admin" class="row" style="margin:12px 0" data-keep><input type="hidden" name="tab" value="sellers"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search sellers" style="max-width:260px"><button class="btn btn-sm btn-outline">Search</button>
    </form>
  <form method="post" action="/admin/level" data-keep style="margin-bottom:12px"><?= csrf_field() ?><input type="hidden" name="action" value="recalculate"><button class="btn btn-sm btn-outline" type="submit">Recalculate all levels</button> <span class="muted small">Levels also update when orders are delivered and ratings change.</span></form>
  <div class="table-wrap"><table><thead><tr><th>Seller</th><th>Level</th><th class="t-right">Delivered (90d)</th><th class="t-right">Returns</th><th class="t-right">Packed in 48h</th><th class="t-right">Rating</th><th>Pin a level</th></tr></thead><tbody>
  <?php foreach ($items as $s): $m = $s['m']; ?>
    <tr><td><strong><?= e($s['store']) ?></strong><br><span class="muted small"><?= e($s['email']) ?></span></td>
      <td><?= level_badge($s['level']) ?><?= $s['override_level'] ? '<br><span class="muted small">pinned' . ($s['override_note'] ? ': ' . e($s['override_note']) : '') . '</span>' : '' ?></td>
      <td class="t-right"><?= (int) ($m['delivered'] ?? 0) ?></td><td class="t-right"><?= isset($m['return_pct']) ? e((string) $m['return_pct']) . '%' : '–' ?></td>
      <td class="t-right"><?= isset($m['dispatch_pct']) && $m['dispatch_pct'] !== null ? e((string) $m['dispatch_pct']) . '%' : '–' ?></td><td class="t-right"><?= !empty($m['rating']) ? e((string) $m['rating']) . ' (' . (int) $m['ratings'] . ')' : '–' ?></td>
      <td><form method="post" action="/admin/level" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="seller" value="<?= e($s['id']) ?>">
        <select name="level" aria-label="Level"><option value="">Automatic</option><?php foreach (LEVEL_LABEL as $k => $l): ?><option value="<?= e($k) ?>"<?= $s['override_level'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <input name="note" placeholder="Reason (optional)" value="<?= e($s['override_note']) ?>" maxlength="250" style="max-width:170px"><button class="btn btn-sm" type="submit">Save</button></form></td></tr>
  <?php endforeach; if (!$items): ?><tr><td colspan="7" class="muted">No verified sellers yet.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>

<?php if ($tab === 'staff'): ?>
  <p class="muted">Staff are created by you. They can sign in to review and publish seller products, nothing else.</p>
  <form method="post" action="/admin/staff" class="card" style="max-width:520px" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="create"><h3 style="margin-top:0">Add a staff account</h3>
    <?= f_text('email', 'Email', '', null, ['type' => 'email', 'required' => 1]) ?>
    <div class="field"><label for="f-staffpw">Temporary password</label><div class="pw"><input id="f-staffpw" name="password" type="password" minlength="8" required autocomplete="new-password"><button type="button" data-pw="f-staffpw">Show</button></div></div>
    <?php foreach (STAFF_PERMISSIONS as $k => $l): ?><label class="check"><input type="checkbox" name="permissions[]" value="<?= e($k) ?>" checked><span><?= e($l) ?></span></label><?php endforeach; ?>
    <button class="btn">Create account</button></form>
  <ul class="list" style="margin-top:20px"><?php foreach ($items as $s): ?><li><div class="row between"><strong><?= e($s['email']) ?></strong><?= pill($s['active'] ? 'approved' : 'rejected', ['approved' => 'Active', 'rejected' => 'Disabled']) ?></div>
    <form method="post" action="/admin/staff" class="row" style="margin-top:8px" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= e($s['user_id']) ?>">
      <?php foreach (STAFF_PERMISSIONS as $k => $l): ?><label class="check" style="margin:0"><input type="checkbox" name="permissions[]" value="<?= e($k) ?>"<?= in_array($k, $s['permissions'], true) ? ' checked' : '' ?>><span><?= e($l) ?></span></label><?php endforeach; ?>
      <label class="check" style="margin:0"><input type="checkbox" name="active" value="1"<?= $s['active'] ? ' checked' : '' ?>><span>Active</span></label><button class="btn btn-sm btn-outline">Save</button></form>
    <form method="post" action="/admin/staff" class="row" style="margin-top:8px" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="user_id" value="<?= e($s['user_id']) ?>"><input name="password" type="password" minlength="8" placeholder="New password" aria-label="New password" autocomplete="new-password" required><button class="btn btn-sm btn-ghost">Change password</button></form></li><?php endforeach; ?>
    <?php if (!$items): ?><li class="muted">No staff accounts yet.</li><?php endif; ?></ul>
<?php endif; ?>

<?php if ($tab === 'activity'): ?>
  <p class="muted">Every decision made in the admin area, newest first.</p>
  <form method="get" action="/admin" class="row" data-keep><input type="hidden" name="tab" value="activity"><select name="filter" aria-label="Type"><option value="">All activity</option><?php foreach (AUDIT_LABEL as $k => $l): ?><option value="<?= e($k) ?>"<?= $filter === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline">Filter</button></form>
  <div class="table-wrap" style="margin-top:12px"><table><thead><tr><th>When</th><th>Who</th><th>Type</th><th>What</th></tr></thead><tbody>
  <?php foreach ($items as $a): ?><tr><td><?= e(human_date($a['created_at'], true)) ?></td><td><?= e($a['actor_email']) ?></td><td><?= e(AUDIT_LABEL[$a['action']] ?? $a['action']) ?></td><td><?= e($a['summary']) ?></td></tr><?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="4" class="muted">Nothing recorded yet.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>

<?php if ($tab === 'settings'): ?>
  <form method="post" action="/admin/settings" class="card" style="max-width:640px" data-keep><?= csrf_field() ?>
    <h3 style="margin-top:0">Money</h3>
    <div class="grid g2"><?= f_text('commission_percent', 'Marketplace commission (%)', (string) setting('commission_percent', '10'), null, ['inputmode' => 'decimal']) ?><?= f_text('payout_hold_days', 'Payout hold (days)', (string) setting('payout_hold_days', '7'), null, ['inputmode' => 'numeric', 'hint' => 'Sales become withdrawable after this many days.']) ?>
      <?= f_text('min_withdrawal', 'Smallest withdrawal', (string) setting('min_withdrawal', '1000'), null, ['inputmode' => 'decimal']) ?><?= f_text('withdrawal_fee', 'Fee per withdrawal', (string) setting('withdrawal_fee', '0'), null, ['inputmode' => 'decimal']) ?></div>
    <h3>Addresses</h3>
    <?= f_text('app_url', 'Vendor Hub address', (string) setting('app_url', ''), null, ['type' => 'url', 'placeholder' => 'https://vendor.ddlx.shop']) ?>
    <?= f_text('marketplace_url', 'Marketplace address', (string) setting('marketplace_url', ''), null, ['type' => 'url', 'placeholder' => 'https://ddlx.shop', 'hint' => 'Used to check SKUs against the store. Leave blank to skip.']) ?>
    <?= f_text('mail_from', 'Email sender address', (string) setting('mail_from', ''), null, ['type' => 'email', 'placeholder' => 'no-reply@ddlx.shop']) ?>
    <h3>Ask DDLX assistant</h3>
    <p class="muted small">An AI helper for sellers and staff. Create a key at console.anthropic.com and paste it here. Without a key the assistant still answers from built-in help.</p>
    <label class="check"><input type="checkbox" name="ai_enabled" value="yes" <?= setting('ai_enabled', '1') === '1' ? 'checked' : '' ?>><span>Use the AI assistant</span></label>
    <?= f_text('ai_api_key', 'Anthropic API key', '', null, ['type' => 'password', 'autocomplete' => 'off', 'placeholder' => setting('ai_api_key', '') !== '' ? 'Saved (ends ' . substr((string) setting('ai_api_key', ''), -4) . '). Paste a new key to replace it.' : 'sk-ant-...']) ?>
    <?php if (setting('ai_api_key', '') !== ''): ?><label class="check"><input type="checkbox" name="ai_clear_key" value="yes"><span>Remove the saved key</span></label><?php endif; ?>
    <?= f_text('ai_model', 'Model', (string) setting('ai_model', 'claude-sonnet-5-5'), null, ['hint' => 'Leave as is unless told otherwise.']) ?>
    <button class="btn">Save settings</button>
  </form>
  <p style="max-width:640px"><a class="btn btn-outline" href="/admin/health">Run system check</a> <span class="muted small">Checks PHP, the database, folders, HTTPS, email and the marketplace connection.</span></p>
  <h2>Seller levels</h2>
  <p class="muted" style="max-width:700px">Sellers earn Bronze, Silver, Gold and Blue from the last 90 days. The badge and marketplace placement always apply. Perks are optional: leave them at 0 to start, and raise them later as a reward.</p>
  <?php $rules = level_rules(); ?>
  <form method="post" action="/admin/level-rules" data-keep class="card" style="max-width:900px"><?= csrf_field() ?>
    <div class="table-wrap"><table><thead><tr><th>Level</th><th>Delivered orders</th><th>Max returns %</th><th>Packed in 48h %</th><th>Min rating</th><th>Ratings needed</th><th>Commission points off</th><th>Payout days sooner</th></tr></thead><tbody>
    <?php foreach ($rules as $lv => $r): ?><tr><td><?= level_badge($lv) ?></td>
      <?php foreach (['orders', 'returns', 'dispatch', 'rating', 'min_ratings', 'commission_off', 'hold_off'] as $k): ?><td><input name="lv[<?= e($lv) ?>][<?= e($k) ?>]" value="<?= e((string) $r[$k]) ?>" inputmode="decimal" style="width:84px" aria-label="<?= e(LEVEL_LABEL[$lv] . ' ' . $k) ?>"></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <button class="btn" type="submit">Save level rules</button></form>
  <h2>Backup</h2>
  <p class="muted" style="max-width:640px">Download a copy of all sellers, products, orders and balances. Do this weekly and before any update. Uploaded photos and documents are in the <code>storage</code> folder; download that from cPanel File Manager too. Keep backups private: they contain personal data.</p>
  <form method="post" action="/admin/backup" class="row" data-keep><?= csrf_field() ?><input type="password" name="password" placeholder="Your password" autocomplete="current-password" required style="max-width:240px"><button class="btn btn-outline" type="submit">Download database backup</button></form>
  <h2>Connect the marketplace</h2>
  <p class="muted">Paste these into WordPress under WooCommerce → Vendor Hub. Treat them like passwords.</p>
  <dl class="dl">
    <dt>Vendor Hub address</dt><dd><code><?= e(base_url()) ?></code></dd>
    <dt>API key</dt><dd><code><?= e((string) setting('marketplace_api_key', '')) ?></code>
      <form method="post" action="/admin/settings" style="display:inline" data-confirm="Create a new API key? The marketplace stops working until you paste the new one."><?= csrf_field() ?><input type="hidden" name="action" value="regenerate"><input type="hidden" name="which" value="key"><button class="btn btn-sm btn-ghost">New key</button></form></dd>
    <dt>Webhook secret</dt><dd><code><?= e((string) setting('marketplace_webhook_secret', '')) ?></code>
      <form method="post" action="/admin/settings" style="display:inline" data-confirm="Create a new webhook secret? Sales stop arriving until you paste the new one."><?= csrf_field() ?><input type="hidden" name="action" value="regenerate"><input type="hidden" name="which" value="secret"><button class="btn btn-sm btn-ghost">New secret</button></form></dd>
  </dl>
<?php endif; ?>
