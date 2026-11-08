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
<?php $tabHelp = [
  'orders' => 'Move orders from here or from WooCommerce; both places stay in step. Sellers pack their items in Vendor Hub, you collect, ship and close the order.',
  'payouts' => $ps ?? false ? 'Press Send with Paystack to pay a seller\'s bank account automatically. The request shows as sending until the bank confirms.' : 'Pay the seller from your bank or Paystack dashboard, then enter the transfer reference here. Add your Paystack key in Settings to pay with one click.',
  'refunds' => 'When an order is refunded or cancelled the seller\'s earnings are taken back automatically. The customer\'s money is returned here: press Refund with Paystack, or mark it done if you refunded it elsewhere. Never refund the same order twice.',
  'categories' => 'Sellers pick the most specific category. Up to ' . MAX_CATEGORY_DEPTH . ' levels.',
]; ?>
<h1><?= e($tabs[$tab]) ?><?= isset($tabHelp[$tab]) ? ' ' . help($tabHelp[$tab]) : '' ?></h1>
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
    <p class="muted"><?= e($p['store_name']) ?><?= verified_tick($p['seller_id'] ?? '') ?> · <?= e($p['seller_email']) ?></p>
    <div class="imgs"><?php foreach (array_filter(array_merge([$p['featured_image']], $p['gallery'])) as $path): ?><a href="<?= e(product_image_url($path)) ?>" data-preview="<?= e(product_image_url($path)) ?>" data-title="<?= e($p['name']) ?>"><img class="thumb" style="width:90px;height:90px" src="<?= e(product_image_url($path)) ?>" alt=""></a><?php endforeach; ?></div>
    <dl class="dl"><dt>SKU</dt><dd><?= e($p['sku']) ?></dd><dt>Category</dt><dd><?= e(category_label($p)) ?></dd><dt>Brand</dt><dd><?= e($p['brand'] ?: '—') ?></dd><dt>Price</dt><dd><?= e(price_label($p)) ?><?= $p['sale_price'] !== null && $p['product_type'] === 'simple' ? ' (regular ' . e($m($p['regular_price'], $p['currency'])) . ')' : '' ?></dd>
      <dt>Stock</dt><dd><?= $p['product_type'] === 'variable' ? array_sum(array_column($p['variations'], 'stock')) : (int) $p['stock'] ?></dd>
      <dt>Shipping</dt><dd><?= e((string) $p['weight_kg']) ?> kg · <?= e((string) $p['length_cm']) ?> × <?= e((string) $p['width_cm']) ?> × <?= e((string) $p['height_cm']) ?> cm</dd>
      </dl>
    <?php if ($p['short_description'] !== '' || $p['description'] !== ''): ?><div class="card pdesc"><h3 style="margin-top:0">Description</h3><?= $p['short_description'] !== '' ? '<p class="lead-sm">' . e($p['short_description']) . '</p>' : '' ?><?= rich_text((string) $p['description']) ?></div><?php endif; ?>
    <?php if ($p['variations']): ?><h3>Variations</h3><div class="table-wrap"><table><thead><tr><th>Options</th><th>SKU</th><th class="t-right">Price</th><th class="t-right">Stock</th></tr></thead><tbody><?php foreach ($p['variations'] as $v): ?><tr><td><?= e(implode(' / ', $v['options'])) ?><?= $v['enabled'] ? '' : ' (off)' ?></td><td><?= e($v['sku']) ?></td><td class="t-right"><?= e($m($v['sale_price'] ?? $v['regular_price'] ?? 0, $p['currency'])) ?></td><td class="t-right"><?= (int) $v['stock'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php if ($p['admin_comment']): ?><div class="notice"><h3>Previous note</h3><p><?= nl2br(e($p['admin_comment'])) ?></p></div><?php endif; ?>
    <?php if ($p['status'] === 'pending_review'): ?>
      <?php if ($problems): ?><div class="notice"><h3>Not ready to approve</h3><p><?= e(implode(' ', $problems)) ?></p></div><?php endif; ?>
      <?= $decision('/admin/product', $p['id'], $p['updated_raw'], array_merge($problems ? [] : [['decision', 'approve', 'Approve and publish', '']], [['decision', 'request_changes', 'Request changes', 'btn-outline'], ['decision', 'reject', 'Reject', 'btn-danger']])) ?>
    <?php elseif ($p['live']): ?>
      <?= $decision('/admin/product', $p['id'], $p['updated_raw'], [['decision', 'take_down', 'Take off marketplace', 'btn-danger']]) ?>
    <?php elseif ($p['status'] === 'approved' && !empty($p['has_snapshot'])): ?>
      <div class="notice"><p>This product is approved but currently off the marketplace.</p></div>
      <?= $decision('/admin/product', $p['id'], $p['updated_raw'], [['decision', 'relist', 'Put back on marketplace', '']]) ?>
    <?php endif; ?>
  <?php else: ?>
    <div class="filterbar" data-fbar="adminproducts" data-fdefault="<?= e($f) ?>">
      <input type="search" data-fq placeholder="Search by product, store, SKU or category" aria-label="Search products">
      <span class="chips-row"><?php foreach (['pending' => 'Waiting', 'live' => 'Live', 'hidden' => 'Off marketplace', 'all' => 'All'] as $k => $l): ?><button type="button" class="btn btn-sm <?= $f === $k ? '' : 'btn-outline' ?>" data-fchip="<?= $k ?>"><?= $l ?></button><?php endforeach; ?></span>
      <span class="muted small" data-fcount></span></div>
    <ul class="list" data-fbox="adminproducts"><?php foreach ($items as $p): $tags = []; if ($p['status'] === 'pending_review') $tags[] = 'pending'; if ($p['live']) $tags[] = 'live'; elseif ($p['status'] === 'approved') $tags[] = 'hidden'; ?><li class="row between" data-fitem data-tags="<?= e(implode(' ', $tags)) ?>" data-text="<?= e(mb_strtolower($p['name'] . ' ' . $p['store_name'] . ' ' . $p['sku'] . ' ' . category_label($p))) ?>"<?= $f !== 'all' && !in_array($f, $tags, true) ? ' hidden' : '' ?>><div class="row" style="flex-wrap:nowrap"><?php if ($p['featured_image']): ?><img class="thumb" src="<?= e(product_image_url($p['featured_image'])) ?>" alt=""><?php else: ?><span class="thumb"></span><?php endif; ?>
      <div><a href="/admin?tab=products&id=<?= e($p['id']) ?>"><strong><?= e($p['name']) ?></strong></a><div class="muted small"><?= e($p['store_name']) ?><?= verified_tick($p['seller_id'] ?? '') ?> · <?= e(category_label($p)) ?> · <?= e(price_label($p)) ?></div></div></div><span><?= pill($p['status'], PRODUCT_STATUS_LABEL) ?> <?= $p['live'] ? pill('live') : '' ?></span></li><?php endforeach; ?>
      <li class="muted" data-fempty<?= $items ? ' hidden' : '' ?>>Nothing here.</li></ul>
  <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'returns'): ?>
  <p class="muted">Customers ask for returns from their account on the marketplace, and you can also open a case from the Orders tab. The seller answers here and you decide. Earnings for the item are frozen while a case is open.</p>
  <div class="row" style="margin:16px 0"><?php foreach (['open' => 'Open', 'done' => 'Closed', 'all' => 'All'] as $k => $l): ?><a class="btn btn-sm <?= $filter === $k ? '' : 'btn-outline' ?>" href="/admin?tab=returns&filter=<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?></div>
  <ul class="list"><?php foreach ($items as $c): ?><li>
    <div class="row between" style="align-items:flex-start;gap:16px"><div>
      <strong><?= e($c['item_name']) ?></strong> · order <?= e($c['number'] ?: $c['marketplace_order_id']) ?> · <?= e($m($c['line_total'], $c['currency'])) ?> <?= pill($c['status'], RETURN_LABEL, RETURN_KINDS) ?>
      <div class="muted small"><?= e($c['seller_email']) ?> · <?= e($c['customer_name'] ?: 'Customer') ?> · opened <?= e(human_date($c['created_at'], true)) ?></div>
      <div class="small" style="margin-top:6px"><strong><?= e($c['reason']) ?></strong><?= $c['details'] !== '' ? ' — ' . e($c['details']) : '' ?>
        <span class="muted"> · <?= $c['source'] === 'customer' ? 'asked by the customer' : 'opened by DDLX' ?> · <?= $c['kind'] === 'refund_only' ? 'refund without sending it back' : 'item comes back first' ?></span></div>
      <?php foreach ((array) json_decode((string) ($c['photos'] ?? '[]'), true) as $i => $ph): ?><a class="small" href="<?= e($ph) ?>" target="_blank" rel="noopener">Photo <?= $i + 1 ?></a> <?php endforeach; ?>
      <?php if ($c['status'] === 'open' && !empty($c['respond_by'])): ?><div class="muted small" style="margin-top:6px">Seller must reply by <?= e(human_date($c['respond_by'], true)) ?>. No reply counts as accepting.</div><?php endif; ?>
      <?php if ($c['seller_response'] !== ''): ?><div class="small" style="margin-top:6px"><span class="muted">Seller:</span> <?= e($c['seller_response']) ?></div><?php endif; ?>
      <?php if ($c['resolution_note'] !== ''): ?><div class="small" style="margin-top:6px"><span class="muted">Decision:</span> <?= e($c['resolution_note']) ?></div><?php endif; ?></div>
    <?php if (!in_array($c['status'], RETURN_FINAL, true)): ?><form method="post" action="/admin/return/resolve" class="stack" style="min-width:260px" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
      <input name="note" maxlength="500" placeholder="Note to the seller (required to reject)" aria-label="Note">
      <label class="check"><input type="checkbox" name="restock" value="yes"><span>Item comes back to stock</span></label>
      <div class="row"><?php if ($c['kind'] === 'return' && $c['source'] === 'customer' && in_array($c['status'], ['open', 'seller_disputed'], true)): ?><button class="btn btn-sm" name="decision" value="approve" type="submit" data-confirm="Approve the return? The customer will be asked to send the item back, then refunded.">Approve return</button><?php endif; ?><button class="btn btn-sm <?= $c['kind'] === 'return' && $c['source'] === 'customer' && $c['status'] !== 'return_shipped' ? 'btn-outline' : '' ?>" name="decision" value="refund" type="submit" data-confirm="Refund this item and take the earnings back from the seller?">Refund now</button><button class="btn btn-sm btn-outline" name="decision" value="reject" type="submit" data-confirm="Reject this case?">Reject</button></div>
      <?= help('Refund reverses the seller\'s earnings. Return the customer\'s money in WooCommerce.') ?></form><?php endif; ?></div></li><?php endforeach; ?>
    <?php if (!$items): ?><li class="muted">No cases here.</li><?php endif; ?></ul>
<?php endif; ?>

<?php if ($tab === 'orders'): ?>
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
  <p class="small"><a href="/admin/export?kind=payouts">Download all withdrawals (CSV)</a></p>
  <ul class="list"><?php foreach ($items as $w): ?><li>
    <div class="row between"><div><strong><?= e($w['store_name']) ?></strong><?= verified_tick($w['seller_id'] ?? '') ?> <span class="muted">· <?= e($w['seller_email']) ?></span>
      <div>Pay <strong><?= e($m((float) $w['amount'] - (float) $w['fee'], $w['currency'])) ?></strong><?= (float) $w['fee'] > 0 ? ' <span class="muted small">(' . e($m($w['amount'], $w['currency'])) . ' less ' . e($m($w['fee'], $w['currency'])) . ' fee)</span>' : '' ?></div>
      <div class="muted small"><?= e($w['bank_name']) ?> · <?= e($w['account_number']) ?> · <?= e($w['account_name']) ?></div>
      <div class="muted small">Requested <?= e(human_date($w['requested_at'], true)) ?><?= $w['reference'] ? ' · Ref ' . e($w['reference']) : '' ?><?= $w['admin_note'] ? ' · ' . e($w['admin_note']) : '' ?></div></div>
      <?php if ($w['status'] === 'requested'): ?>
      <div>
      <?php if ($ps && $w['currency'] === 'NGN'): ?><form method="post" action="/admin/payout/paystack" data-keep style="margin-bottom:8px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($w['id']) ?>"><button class="btn btn-sm btn-accent" name="action" value="send" data-confirm="Send <?= e($m((float) $w['amount'] - (float) $w['fee'], $w['currency'])) ?> to <?= e($w['account_name']) ?> with Paystack? This moves real money from your Paystack balance.">Send with Paystack</button></form><?php endif; ?>
      <form method="post" action="/admin/payout" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($w['id']) ?>"><input name="text" placeholder="Transfer reference or reason" maxlength="500" style="width:230px" aria-label="Transfer reference or reason">
        <button class="btn btn-sm btn-outline" name="decision" value="paid" data-confirm="Mark as paid?">Mark paid</button><button class="btn btn-sm btn-danger" name="decision" value="rejected" data-confirm="Reject and return the money to the seller's wallet?">Reject</button></form></div>
      <?php elseif ($w['status'] === 'processing'): ?>
      <form method="post" action="/admin/payout/paystack" data-keep class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($w['id']) ?>"><?= pill('processing', WITHDRAWAL_LABEL, ['processing' => 'warn']) ?><button class="btn btn-sm btn-outline" name="action" value="check">Check status</button></form>
      <?php else: echo pill($w['status'], WITHDRAWAL_LABEL, ['failed' => 'bad']); endif; ?></div></li><?php endforeach; ?>
    <?php if (!$items): ?><li class="muted">No withdrawal requests yet.</li><?php endif; ?></ul>
<?php endif; ?>

<?php if ($tab === 'refunds'): ?>
  <div class="row" style="margin:12px 0"><?php foreach (['open' => 'To do', 'done' => 'Done', 'all' => 'All'] as $k => $l): ?><a class="chip" style="<?= $filter === $k ? 'border-color:var(--ink);font-weight:700' : '' ?>" href="/admin?tab=refunds&amp;filter=<?= e($k) ?>"><?= e($l) ?></a><?php endforeach; ?></div>
  <ul class="list"><?php foreach ($items as $r): ?><li>
    <div class="row between"><div><strong>Order <?= e($r['number'] ?: $r['marketplace_order_id']) ?></strong> <span class="muted">· <?= e($r['customer_name']) ?> · <?= e(human_date($r['created_at'], true)) ?></span>
      <div>Refund <strong><?= e($m((float) $r['amount'], $r['currency'])) ?></strong> <?= pill($r['status'], CR_LABEL, ['to_pay' => 'warn', 'sending' => 'warn', 'failed' => 'bad', 'refunded' => 'ok', 'manual' => 'ok']) ?></div>
      <div class="muted small"><?= $r['payment_ref'] !== '' ? 'Paystack payment ' . e($r['payment_ref']) : 'No Paystack payment reference on this order' ?><?= $r['payment_method'] !== '' ? ' · ' . e($r['payment_method']) : '' ?><?= $r['note'] ? ' · ' . e($r['note']) : '' ?></div></div>
      <?php if (in_array($r['status'], ['to_pay', 'failed', 'sending'], true)): ?>
      <form method="post" action="/admin/refund" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>">
        <input name="amount" value="<?= e(number_format((float) $r['amount'], 2, '.', '')) ?>" inputmode="decimal" style="width:110px" aria-label="Amount to refund">
        <?php if ($ps && $r['currency'] === 'NGN' && $r['payment_ref'] !== '' && $r['status'] !== 'sending'): ?><button class="btn btn-sm btn-accent" name="action" value="paystack" data-confirm="Refund this amount to the customer through Paystack? This cannot be undone.">Refund with Paystack</button><?php endif; ?>
        <input name="note" placeholder="Note (optional)" maxlength="300" style="width:170px" aria-label="Note"><button class="btn btn-sm btn-outline" name="action" value="manual" data-confirm="Mark as refunded? Only do this if the customer already got the money back.">Mark done</button></form>
      <?php endif; ?></div></li><?php endforeach; ?>
  <?php if (!$items): ?><li class="muted">Nothing to refund.</li><?php endif; ?></ul>
<?php endif; ?>

<?php if ($tab === 'categories'): $flat = category_flat($all); ?>
  <p class="muted small"><?= count($all) ?> categories</p>
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
  <?php foreach ($items as $c): ?><tr><td><strong><?= e($c['code']) ?></strong></td><td><?= e($c['store']) ?><?= verified_tick($c['seller_id'] ?? '') ?></td>
    <td><?= $c['kind'] === 'percent' ? e((string) $c['amount']) . '%' : e($c['currency'] . ' ' . number_format($c['amount'], 2)) . ' each' ?></td>
    <td><?= e(COUPON_STATE_LABEL[$c['state']] ?? $c['state']) ?></td><td class="t-right"><?= (int) $c['used_count'] ?></td><td class="t-right"><?= e(number_format($c['discount_total'], 2)) ?></td>
    <td><form method="post" action="/admin/code" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn btn-sm btn-outline" name="action" value="<?= $c['admin_disabled'] ? 'allow' : 'block' ?>"><?= $c['admin_disabled'] ? 'Switch on' : 'Switch off' ?></button></form></td></tr>
  <?php endforeach; if (!$items): ?><tr><td colspan="7" class="muted">No codes yet.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>

<?php if ($tab === 'sellers'): ?>
  <div class="toolbar">
    <form method="get" action="/admin" class="tb-search" data-keep><input type="hidden" name="tab" value="sellers"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search sellers" aria-label="Search sellers"><button class="btn btn-sm btn-outline">Search</button></form>
    <div class="tb-actions">
      <form method="post" action="/admin/level" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="recalculate"><button class="btn btn-sm btn-outline" type="submit">Recalculate levels</button></form>
      <form method="post" action="/admin/marketplace-sync" data-keep class="tb-sync"><?= csrf_field() ?>
        <label class="check-inline"><input type="checkbox" name="create" value="1"> Also create accounts for marketplace-only sellers</label>
        <button class="btn btn-sm" type="submit">Sync with marketplace</button><?= help('Links marketplace (Dokan) sellers to Vendor Hub sellers with the same email. With the box ticked, sellers that only exist on the marketplace also get a Vendor Hub account and an email to set a password. Products and orders are imported per seller from the Manage page. Verified Vendor Hub sellers reach the marketplace automatically on its next sync. Levels also update when orders are delivered and ratings change.') ?>
      </form>
    </div>
  </div>
  <div class="sellers">
  <?php foreach ($items as $s): $m = $s['m']; $lk = $links[$s['id']] ?? null; ?>
    <article class="seller-card">
      <header class="sc-head">
        <div class="sc-id"><span class="sc-name"><strong><?= e($s['store']) ?></strong><?= verified_tick($s['id']) ?></span><span class="muted small"><?= e($s['email']) ?></span></div>
        <div class="sc-level"><?= level_badge($s['level']) ?><?= $s['override_level'] ? '<span class="muted small">pinned' . ($s['override_note'] ? ': ' . e($s['override_note']) : '') . '</span>' : '' ?><?= !empty($s['below_since']) && !$s['override_level'] ? '<span class="small" style="color:#b45309">at risk since ' . e(human_date($s['below_since'], false)) . '</span>' : '' ?></div>
      </header>
      <dl class="sc-metrics">
        <div><dt>Delivered (90d)</dt><dd><?= (int) ($m['delivered'] ?? 0) ?></dd></div>
        <div><dt>Returns</dt><dd><?= isset($m['return_pct']) ? e((string) $m['return_pct']) . '%' : '–' ?></dd></div>
        <div><dt>Packed in 48h</dt><dd><?= isset($m['dispatch_pct']) && $m['dispatch_pct'] !== null ? e((string) $m['dispatch_pct']) . '%' : '–' ?></dd></div>
        <div><dt>Rating</dt><dd><?= !empty($m['rating']) ? e((string) $m['rating']) . ' <span class="muted small">(' . (int) $m['ratings'] . ')</span>' : '–' ?></dd></div>
        <div><dt>Marketplace</dt><dd><?= $lk ? '<span class="pill pill-ok">Linked</span> <span class="muted small">#' . (int) $lk['uid'] . '</span>' : '<span class="muted small">Not linked</span>' ?></dd></div>
      </dl>
      <footer class="sc-foot">
        <form method="post" action="/admin/level" class="sc-pin" data-keep><?= csrf_field() ?><input type="hidden" name="seller" value="<?= e($s['id']) ?>">
          <select name="level" aria-label="Pin a level"><option value="">Automatic level</option><?php foreach (LEVEL_LABEL as $k => $l): ?><option value="<?= e($k) ?>"<?= $s['override_level'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
          <input name="note" placeholder="Reason (optional)" value="<?= e($s['override_note']) ?>" maxlength="250"><button class="btn btn-sm btn-outline" type="submit">Save level</button></form>
        <div class="sc-btns"><a class="btn btn-sm btn-outline" href="/admin/link?seller=<?= e($s['id']) ?>"><?= $lk ? 'Manage marketplace' : 'Link to marketplace' ?></a><a class="btn btn-sm" href="/admin/seller?id=<?= e($s['id']) ?>">Edit details</a><form method="post" action="/admin/view-as" data-keep style="display:inline"><?= csrf_field() ?><input type="hidden" name="seller" value="<?= e($s['id']) ?>"><button class="btn btn-sm btn-outline" type="submit">View as seller</button></form></div>
      </footer>
    </article>
  <?php endforeach; if (!$items): ?><p class="muted">No verified sellers yet.</p><?php endif; ?>
  </div>
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
  <nav class="chips" aria-label="Settings sections"><a class="chip" href="#s-general">General</a><a class="chip" href="#s-assistant">Assistant</a><a class="chip" href="#s-email">Email</a><a class="chip" href="#s-phone">Phone app</a><a class="chip" href="#s-levels">Seller levels</a><a class="chip" href="#s-paystack">Paystack</a><a class="chip" href="#s-backup">Backup</a><a class="chip" href="#s-connect">Marketplace link</a></nav>
  <form method="post" action="/admin/settings" class="card set" style="max-width:640px" data-keep><?= csrf_field() ?>
    <h3 id="s-general" style="margin-top:0">Money</h3>
    <div class="grid g2"><?= f_text('commission_percent', 'Marketplace commission (%)', (string) setting('commission_percent', '10'), null, ['inputmode' => 'decimal']) ?><?= f_text('payout_hold_days', 'Payout hold (days)', (string) setting('payout_hold_days', '7'), null, ['inputmode' => 'numeric', 'hint' => 'Sales become withdrawable after this many days.']) ?>
      <?= f_text('min_withdrawal', 'Smallest withdrawal', (string) setting('min_withdrawal', '1000'), null, ['inputmode' => 'decimal']) ?><?= f_text('withdrawal_fee', 'Fee per withdrawal', (string) setting('withdrawal_fee', '0'), null, ['inputmode' => 'decimal']) ?></div>
    <?= f_area('return_address', 'Return address', (string) setting('return_address', ''), null, ['rows' => 3, 'maxlength' => 400, 'hint' => 'Where customers send items back. Shown to them when a return is approved.']) ?>
    <h3>Addresses</h3>
    <?= f_text('app_url', 'Vendor Hub address', (string) setting('app_url', ''), null, ['type' => 'url', 'placeholder' => 'https://vendor.ddlx.shop']) ?>
    <?= f_text('marketplace_url', 'Marketplace address', (string) setting('marketplace_url', ''), null, ['type' => 'url', 'placeholder' => 'https://ddlx.shop', 'hint' => 'Used to check SKUs against the store. Leave blank to skip.']) ?>
    <h3 id="s-assistant">Ask DDLX assistant <?= help('Answers questions for sellers and staff. It always works with the built-in help. Add a free Gemini and/or Groq key for fuller answers: Gemini is asked first, Groq answers if Gemini is busy, and the built-in help answers if neither can.') ?></h3>
    <label class="check"><input type="checkbox" name="ai_enabled" value="yes" <?= setting('ai_enabled', '1') === '1' ? 'checked' : '' ?>><span>Use the assistant</span></label>
    <?php foreach (AI_PROVIDERS as $prov => $d): $has = assistant_key($prov) !== ''; ?>
      <?= f_text('ai_key_' . $prov, $d['label'] . ' key', '', null, ['type' => 'password', 'autocomplete' => 'off', 'hint' => $d['help'], 'placeholder' => $has ? 'Saved (ends ' . substr(assistant_key($prov), -4) . '). Paste a new key to replace it.' : 'Paste the key here']) ?>
      <?php if ($has): ?><label class="check"><input type="checkbox" name="ai_clear_<?= $prov ?>" value="yes"><span>Remove the saved <?= e($d['label']) ?> key</span></label><?php endif; ?>
    <?php endforeach; ?>
    <?php if ((string) setting('ai_last_error', '') !== ''): ?><p class="flash flash-error">Last problem reaching an assistant service: <?= e((string) setting('ai_last_error', '')) ?></p><?php endif; ?>
    <div class="row"><button class="btn">Save settings</button><button class="btn btn-outline" type="submit" formaction="/admin/assistant-test" formnovalidate>Save and test the assistant</button></div>
  </form>
  <h2 id="s-email">Email sending (SMTP) <?= help('Makes order, payout and password emails reach inboxes instead of spam. Use a service such as Brevo (free tier) or your own mail account. Leave the server blank to use your host\'s built-in mail. On Brevo: the server, port and login are on its SMTP & API page, and the password is an SMTP key you create there (not your account password).') ?></h2>
  <form method="post" action="/admin/email" class="card set" style="max-width:640px" data-keep><?= csrf_field() ?>
    <div class="grid g2"><?= f_text('smtp_host', 'SMTP server', (string) setting('smtp_host', ''), null, ['placeholder' => 'smtp-relay.brevo.com']) ?><?= f_text('smtp_port', 'Port', (string) setting('smtp_port', '587'), null, ['inputmode' => 'numeric', 'hint' => '587 for TLS, 465 for SSL.']) ?>
      <?= f_text('smtp_user', 'Login', (string) setting('smtp_user', ''), null, ['autocomplete' => 'off', 'placeholder' => 'xxxxxx@smtp-brevo.com']) ?><?= f_text('smtp_pass', 'SMTP key (password)', '', null, ['type' => 'password', 'autocomplete' => 'new-password', 'placeholder' => setting('smtp_pass', '') !== '' ? 'Saved. Type a new one to replace.' : 'Brevo: SMTP & API → SMTP keys']) ?></div>
    <?= f_text('mail_from', 'Send emails from', (string) setting('mail_from', ''), null, ['type' => 'email', 'placeholder' => 'no-reply@ddlx.shop', 'hint' => 'The address people see. Not the Login above. On Brevo, add it under Senders & IP and verify it first.']) ?>
    <div class="field"><label for="smtp_secure">Connection</label><select id="smtp_secure" name="smtp_secure"><?php foreach (['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None (not recommended)'] as $k => $l): ?><option value="<?= $k ?>" <?= setting('smtp_secure', 'tls') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <?php if (setting('smtp_pass', '') !== ''): ?><label class="check"><input type="checkbox" name="smtp_clear" value="yes"><span>Remove the saved password</span></label><?php endif; ?>
    <div class="row"><button class="btn" name="action" value="save">Save email settings</button><button class="btn btn-outline" name="action" value="test" formnovalidate>Send me a test email</button></div>
  </form>
  <h2 id="s-phone">Phone app notifications (Firebase) <?= help('Sends new orders, messages and alerts to phones through the Android app. In the Firebase console open Project settings → Service accounts → Generate new private key, then paste the whole file here.') ?></h2>
  <form method="post" action="/admin/push" class="card set" style="max-width:640px" data-keep><?= csrf_field() ?>
    <?php $pa = push_account(); ?>
    <?php if ($pa): ?><p class="small"><span class="pill pill-ok">Connected</span> project <strong><?= e($pa['project_id']) ?></strong></p><?php endif; ?>
    <?= f_area('fcm_service_account', $pa ? 'Replace the key (optional)' : 'Firebase service-account key', '', null, ['rows' => 5, 'placeholder' => '{ "type": "service_account", ... }', 'autocomplete' => 'off']) ?>
    <?php if ($pa): ?><label class="check"><input type="checkbox" name="push_clear" value="yes"><span>Remove the saved key</span></label><?php endif; ?>
    <div class="row"><button class="btn" name="action" value="save">Save</button><?php if ($pa): ?><button class="btn btn-outline" name="action" value="test" formnovalidate>Send me a test notification</button><?php endif; ?></div>
  </form>
  <p><a class="btn btn-outline" href="/admin/health">Run system check</a> <?= help('Checks PHP, the database, folders, HTTPS, email and the marketplace connection.') ?></p>
  <h2 id="s-levels">Seller levels <?= help('Sellers earn Bronze, Silver, Gold and Blue from the last 90 days. The badge and marketplace placement always apply. Perks (commission points off, payout days sooner) are optional: leave them at 0 to start and raise them later as a reward.') ?></h2>
  <?php $rules = level_rules(); ?>
  <form method="post" action="/admin/level-rules" data-keep class="card" style="max-width:900px"><?= csrf_field() ?>
    <div class="table-wrap"><table><thead><tr><th>Level</th><th>Delivered orders</th><th>Max returns %</th><th>Packed in 48h %</th><th>Min rating</th><th>Ratings needed</th><th>Commission points off</th><th>Payout days sooner</th></tr></thead><tbody>
    <?php foreach ($rules as $lv => $r): ?><tr><td><?= level_badge($lv) ?></td>
      <?php foreach (['orders', 'returns', 'dispatch', 'rating', 'min_ratings', 'commission_off', 'hold_off'] as $k): ?><td><input name="lv[<?= e($lv) ?>][<?= e($k) ?>]" value="<?= e((string) $r[$k]) ?>" inputmode="decimal" style="width:84px" aria-label="<?= e(LEVEL_LABEL[$lv] . ' ' . $k) ?>"></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php $pol = level_policy(); ?>
    <h3>When a seller stops meeting a level <?= help('Levels are checked every day, and whenever an order is delivered or a rating changes. A level you pinned on the Sellers tab never changes automatically.') ?></h3>
    <div class="field"><label for="ld">Downgrading</label><select id="ld" name="level_downgrade">
      <option value="auto"<?= $pol['mode'] === 'auto' ? ' selected' : '' ?>>Automatic: the level drops at the next daily check</option>
      <option value="grace"<?= $pol['mode'] === 'grace' ? ' selected' : '' ?>>Grace period: warn the seller, drop after the days below</option>
      <option value="never"<?= $pol['mode'] === 'never' ? ' selected' : '' ?>>Never automatic: levels only go up, I lower them by hand</option></select></div>
    <div class="grid g2"><?= f_text('level_grace_days', 'Grace period (days)', (string) $pol['grace_days'], null, ['inputmode' => 'numeric']) ?>
      <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="level_one_step" value="yes" <?= $pol['one_step'] ? 'checked' : '' ?>><span>Drop one level at a time</span></label></div></div>
    <button class="btn" type="submit">Save level rules</button></form>
  <h2 id="s-paystack">Paystack <?= help('Lets you pay sellers and refund customers with one click. In Paystack open Settings → API Keys & Webhooks, copy your secret key and set the webhook address to ' . base_url() . '/api/paystack/webhook. In Settings → Preferences turn off "Confirm transfers before sending", or transfers wait for an OTP. Test with a sk_test_ key before using sk_live_.') ?></h2>
  <form method="post" action="/admin/paystack" data-keep class="card set" style="max-width:640px"><?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="paystack_enabled" value="yes" <?= setting('paystack_enabled', '1') === '1' ? 'checked' : '' ?>><span>Use Paystack for payouts and refunds</span></label>
    <?= f_text('paystack_secret_key', 'Secret key', '', null, ['type' => 'password', 'autocomplete' => 'off', 'placeholder' => paystack_key() !== '' ? 'Saved (' . (paystack_test_mode() ? 'test key' : 'live key') . ', ends ' . substr(paystack_key(), -4) . '). Paste a new key to replace it.' : 'sk_test_...']) ?>
    <?php if (paystack_key() !== ''): ?><label class="check"><input type="checkbox" name="paystack_clear_key" value="yes"><span>Remove the saved key</span></label><?php endif; ?>
    <p class="row"><button class="btn" type="submit">Save Paystack</button><button class="btn btn-outline" type="submit" name="action" value="test">Test connection</button></p>
  </form>
  <h2 id="s-backup">Backup <?= help('Downloads a copy of all sellers, products, orders and balances. Do this weekly and before any update. Uploaded photos and documents are in the storage folder: download that from cPanel File Manager too. Keep backups private, they contain personal data.') ?></h2>
  <form method="post" action="/admin/backup" class="row" data-keep><?= csrf_field() ?><input type="password" name="password" placeholder="Your password" autocomplete="current-password" required style="max-width:240px"><button class="btn btn-outline" type="submit">Download database backup</button></form>
  <h2 id="s-connect">Connect the marketplace <?= help('Paste these into WordPress under WooCommerce → Vendor Hub. Treat them like passwords.') ?></h2>
  <dl class="dl">
    <dt>Vendor Hub address</dt><dd><code><?= e(base_url()) ?></code></dd>
    <dt>API key</dt><dd><code><?= e((string) setting('marketplace_api_key', '')) ?></code>
      <form method="post" action="/admin/settings" style="display:inline" data-confirm="Create a new API key? The marketplace stops working until you paste the new one."><?= csrf_field() ?><input type="hidden" name="action" value="regenerate"><input type="hidden" name="which" value="key"><button class="btn btn-sm btn-ghost">New key</button></form></dd>
    <dt>Webhook secret</dt><dd><code><?= e((string) setting('marketplace_webhook_secret', '')) ?></code>
      <form method="post" action="/admin/settings" style="display:inline" data-confirm="Create a new webhook secret? Sales stop arriving until you paste the new one."><?= csrf_field() ?><input type="hidden" name="action" value="regenerate"><input type="hidden" name="which" value="secret"><button class="btn btn-sm btn-ghost">New secret</button></form></dd>
  </dl>
<?php endif; ?>
