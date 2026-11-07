<?php
/** @var string $tab @var array $user */
$m = fn ($x, $c = 'NGN') => money($x, $c);
?>
<p class="eyebrow">Seller Center</p>
<?php $tabHelp = [
  'inventory' => 'Stock changes apply straight away, even on live products. To change many at once, download your stock, edit the stock numbers in Excel and upload it back. Prices are not changed here because price changes go through review.',
  'promotions' => 'Discount codes for your own products. You fund the discount: customers pay less and your earnings are worked out on the lower price. Codes appear on the DDLX marketplace within a minute or two. Money off applies to each item, so two items get it twice. Set an end date and a use limit so a code cannot run forever.',
  'returns' => 'When a customer complains, DDLX opens a case here. Accept it, or explain why you disagree. DDLX makes the final decision.',
  'payouts' => 'Withdrawals go to your saved bank account. Sales become available to withdraw after the payout hold, and the smallest withdrawal and any fee are shown on the form.',
]; ?>
<h1><?= e(SELLER_TABS[$tab]) ?><?= isset($tabHelp[$tab]) ? ' ' . help($tabHelp[$tab]) : '' ?></h1>
<?= tabs_nav(SELLER_TABS, $tab, '/seller', ['returns' => $user['pending']['returns'] ?? 0]) ?>

<?php if (in_array($tab, ['overview', 'sales', 'payouts'], true)):
    $currencies = array_values(array_unique(array_merge(array_column($balances, 'currency'), array_column($month, 'currency'))));
    if ($currencies): ?>
  <?php foreach ($currencies as $c):
      $b = current(array_filter($balances, fn ($x) => $x['currency'] === $c)) ?: ['available' => 0, 'pending' => 0];
      $mo = current(array_filter($month, fn ($x) => $x['currency'] === $c)) ?: ['net' => 0, 'units' => 0, 'gross' => 0]; ?>
    <div class="grid g3" style="margin-top:20px">
      <div class="stat"><p class="muted small">Available to withdraw</p><p class="n"><?= e($m($b['available'], $c)) ?></p></div>
      <div class="stat"><p class="muted small">Pending (<?= hold_days() ?>-day hold) <?= help('Your earnings are each sale minus the ' . rtrim(rtrim(number_format(commission_percent(), 2), '0'), '.') . '% marketplace commission. Sales become available to withdraw after ' . hold_days() . ' days.') ?></p><p class="n"><?= e($m($b['pending'], $c)) ?></p></div>
      <div class="stat"><p class="muted small">Last 30 days</p><p class="n"><?= e($m($mo['net'], $c)) ?></p><p class="muted small"><?= (int) $mo['units'] ?> units · <?= e($m($mo['gross'], $c)) ?> sold</p></div>
    </div>
  <?php endforeach; ?>
<?php else: ?><p class="notice">No sales yet. When a customer buys one of your products on the marketplace, it shows up here.</p><?php endif; endif; ?>

<?php if ($tab === 'overview'):
    $live = count(array_filter($products, fn ($p) => $p['live']));
    $review = count(array_filter($products, fn ($p) => $p['status'] === 'pending_review'));
    $low = array_filter($products, fn ($p) => in_array(stock_state($p), ['low', 'out'], true));
    $changes = array_filter($products, fn ($p) => in_array($p['status'], ['changes_requested', 'rejected'], true));
    $attention = array_slice(array_unique(array_merge(array_values($changes), array_values($low)), SORT_REGULAR), 0, 6); ?>
<?php $curLv = $level['override_level'] ?: $level['auto_level'];
    if (!empty($level['below_since']) && !$level['override_level']): $pol = level_policy(); ?>
  <p class="notice" style="margin-top:24px"><strong>Your <?= e(LEVEL_LABEL[$curLv]) ?> level is at risk.</strong> <?= $pol['mode'] === 'grace' ? 'Meet its requirements again by ' . e(human_date(db_plus('+' . $pol['grace_days'] . ' days', $level['below_since']), false)) . ' to keep it.' : 'You no longer meet its requirements.' ?></p>
<?php endif; ?>
  <div class="grid g4" style="margin-top:24px">
    <div class="stat"><p class="muted small">Products</p><p class="n"><?= count($products) ?></p></div>
    <div class="stat"><p class="muted small">Live on marketplace</p><p class="n"><?= $live ?></p></div>
    <div class="stat"><p class="muted small">In review</p><p class="n"><?= $review ?></p></div>
    <div class="stat"><p class="muted small">Low or out of stock</p><p class="n"><?= count($low) ?></p></div>
  </div>
  <?php if (!empty($to_process)): ?><p class="notice" style="margin-top:20px"><strong><?= (int) $to_process ?> order<?= $to_process === 1 ? '' : 's' ?> to pack.</strong> <a href="/seller?tab=sales">Open Sales</a> to start processing and mark them ready for shipping.</p><?php endif; ?>
  <h2>Your earnings, last 30 days</h2>
  <div class="card"><?= bar_chart($chart['series'], $chart['currency'], 'Your earnings per day') ?></div>
  <div class="row" style="margin-top:24px"><a class="btn" href="/products/edit"><?= $products ? 'Add product' : 'Add your first product' ?></a><a class="btn btn-outline" href="/seller?tab=inventory">Manage inventory</a></div>
  <?php if ($attention): ?><h2>Needs your attention</h2><ul class="list">
    <?php foreach ($attention as $p): [$k, $l] = product_visibility($p); ?><li class="row between"><a href="/products/edit?id=<?= e($p['id']) ?>"><strong><?= e($p['name']) ?></strong></a><span><?= pill($k, [], ['live' => 'ok', 'hidden' => 'warn']) ?> <?= stock_state($p) !== 'ok' ? pill(stock_state($p)) : '' ?></span></li><?php endforeach; ?></ul><?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'products'): ?>
  <div class="row between" style="margin:18px 0"><span class="muted"><?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?> · <a href="/seller/export?kind=products">Download CSV</a></span><span class="row"><a class="btn btn-outline" href="/products/import">Import CSV</a><a class="btn" href="/products/edit">Add product</a></span></div>
  <?php if (!$products): ?><p class="notice">You have no products yet. Add one, then submit it for review. Approved products appear on the DDLX marketplace.</p><?php endif; ?>
  <?php if ($products): ?><div class="filterbar" data-fbar="products">
    <input type="search" data-fq placeholder="Search by name, SKU or category" aria-label="Search products">
    <select data-fs aria-label="Filter products"><option value="">All products</option><option value="live">Live</option><option value="hidden">Off the marketplace</option><option value="draft">Drafts</option><option value="pending_review">In review</option><option value="attention">Needs changes</option><option value="low">Low stock</option><option value="out">Out of stock</option></select>
    <span class="muted small" data-fcount></span></div><?php endif; ?>
  <ul class="list" data-fbox="products">
  <?php foreach ($products as $p): [$k, $l] = product_visibility($p); $tags = [$k, $p['status'], stock_state($p)]; if (in_array($p['status'], ['changes_requested', 'rejected'], true)) $tags[] = 'attention'; ?>
    <li data-fitem data-tags="<?= e(implode(' ', array_unique($tags))) ?>" data-text="<?= e(mb_strtolower($p['name'] . ' ' . $p['sku'] . ' ' . category_label($p) . ' ' . $p['brand'])) ?>"><div class="row between">
      <div class="row" style="flex-wrap:nowrap"><?php if ($p['featured_image']): ?><img class="thumb" src="<?= e(product_image_url($p['featured_image'])) ?>" alt=""><?php else: ?><span class="thumb"></span><?php endif; ?>
        <div><a href="/products/edit?id=<?= e($p['id']) ?>"><strong><?= e($p['name'] ?: 'Untitled') ?></strong></a>
          <div class="muted small"><?= e($p['sku']) ?> · <?= e(category_label($p)) ?> · <?= e(price_label($p)) ?><?= $p['product_type'] === 'variable' ? ' · ' . count($p['variations']) . ' variations' : '' ?></div>
          <div style="margin-top:6px"><?= pill($k, [], ['live' => 'ok', 'hidden' => 'warn']) ?> <span class="muted small"><?= e($l) ?></span> <?= stock_state($p) !== 'ok' ? pill(stock_state($p)) : '' ?></div></div></div>
      <div class="row">
        <a class="btn btn-outline btn-sm" href="/products/edit?id=<?= e($p['id']) ?>">Edit</a>
        <?php if (in_array($p['status'], ['draft', 'changes_requested', 'rejected'], true)): ?><form method="post" action="/products/action"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="btn btn-sm" name="action" value="submit">Submit</button></form><?php endif; ?>
        <?php if (!$p['live'] && $p['status'] === 'approved' && !empty($p['has_snapshot'])): ?><form method="post" action="/products/action"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="btn btn-sm" name="action" value="live">Put back live</button></form><?php endif; ?>
        <?php if ($p['status'] === 'pending_review'): ?><form method="post" action="/products/action"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="btn btn-outline btn-sm" name="action" value="withdraw">Withdraw</button></form><?php endif; ?>
      </div></div></li>
  <?php endforeach; ?><li class="muted" data-fempty hidden>No products match your search.</li></ul>
<?php endif; ?>

<?php if ($tab === 'inventory'): ?>
  <?php if ($products): ?><details class="card" style="margin:12px 0"><summary><strong>Update many at once (CSV)</strong></summary>
    <form method="post" action="/inventory/import" enctype="multipart/form-data" class="row" data-keep><?= csrf_field() ?><a class="btn btn-outline btn-sm" href="/inventory/export">Download stock list</a><input type="file" name="file" accept=".csv,text/csv" required style="max-width:240px"><button class="btn btn-sm" type="submit">Upload</button></form></details><?php endif; ?>
  <?php if (!$products): ?><p class="notice">Add a product first.</p><?php endif; ?>
  <?php if ($products): ?><div class="filterbar" data-fbar="stock">
    <input type="search" data-fq placeholder="Search by product, option or SKU" aria-label="Search stock">
    <select data-fs aria-label="Filter stock"><option value="">All stock</option><option value="low">Low stock</option><option value="out">Out of stock</option><option value="ok">In stock</option></select>
    <span class="muted small" data-fcount></span></div><?php endif; ?>
  <div class="table-wrap"><table><thead><tr><th>Product</th><th>SKU</th><th class="t-right">In stock</th><th>Change</th></tr></thead><tbody data-fbox="stock">
  <?php foreach ($products as $p):
      $rows = $p['product_type'] === 'variable' ? $p['variations'] : [['id' => null, 'sku' => $p['sku'], 'options' => [], 'stock' => $p['stock']]];
      foreach ($rows as $r): $state = $p['product_type'] === 'variable' ? ($r['stock'] <= 0 ? 'out' : ($r['stock'] <= $p['low_stock_threshold'] ? 'low' : 'ok')) : stock_state($p); ?>
    <tr data-fitem data-tags="<?= e($state) ?>" data-text="<?= e(mb_strtolower($p['name'] . ' ' . $r['sku'] . ' ' . implode(' ', $r['options']))) ?>"><td><a href="/products/edit?id=<?= e($p['id']) ?>"><strong><?= e($p['name']) ?></strong></a><?= $r['options'] ? '<br><span class="muted small">' . e(implode(' / ', $r['options'])) . '</span>' : '' ?></td>
      <td><?= e($r['sku']) ?></td>
      <td class="t-right"><?= !$p['manage_stock'] && $p['product_type'] === 'simple' ? '<span class="muted">Not tracked</span>' : (int) $r['stock'] . ' ' . ($state !== 'ok' ? pill($state) : '') ?></td>
      <td><form method="post" action="/inventory/adjust" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>"><input type="hidden" name="variation_id" value="<?= e((string) $r['id']) ?>">
        <input type="number" name="value" min="0" max="1000000" style="width:90px" aria-label="Quantity" required>
        <button class="btn btn-outline btn-sm" name="op" value="add" type="submit">Add</button>
        <button class="btn btn-outline btn-sm" name="op" value="remove" type="submit">Remove</button>
        <button class="btn btn-sm" name="op" value="set" type="submit">Set to</button></form></td></tr>
  <?php endforeach; endforeach; ?><tr data-fempty hidden><td colspan="4" class="muted">No stock matches your search.</td></tr></tbody></table></div>
<?php endif; ?>

<?php if ($tab === 'promotions'):
    $e = $edit ?? null; $isEdit = $e && empty($e['form_only']) || ($e && !empty($e['id']));
    $ev = fn (string $k, $d = '') => $e[$k] ?? $d; ?>
  <?php if (!empty($err)): ?><p class="flash flash-error" role="alert"><?= e($err) ?></p><?php endif; ?>
  <div class="narrow-col"><div>
  <h2 style="margin-top:0"><?= $isEdit ? 'Edit ' . e((string) $ev('code')) : 'New discount code' ?></h2>
  <form method="post" action="/promotions/save" data-keep class="card"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) $ev('id')) ?>">
    <div class="grid g2">
      <?php if ($isEdit): ?><div class="field"><label>Code</label><input value="<?= e((string) $ev('code')) ?>" disabled></div><?php else: ?><?= f_text('code', 'Code', (string) $ev('code'), null, ['maxlength' => 20, 'required' => 1, 'placeholder' => 'WELCOME10', 'hint' => 'Letters and numbers. Customers type this at checkout.']) ?><?php endif; ?>
      <div class="field"><label for="kind">Type</label><select id="kind" name="kind"><?php foreach (COUPON_KINDS as $k => $l): ?><option value="<?= e($k) ?>"<?= $ev('kind', 'percent') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="grid g3">
      <?= f_text('amount', 'Amount (percent 1-70, or money off each item)', (string) $ev('amount'), null, ['inputmode' => 'decimal', 'required' => 1]) ?>
      <div class="field"><label for="currency">Currency (for money off)</label><select id="currency" name="currency"><?php foreach (CURRENCIES as $c): ?><option<?= $ev('currency', 'NGN') === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
      <?= f_text('min_spend', 'Minimum spend (optional)', (string) $ev('min_spend'), null, ['inputmode' => 'decimal']) ?>
    </div>
    <div class="grid g3">
      <?= f_text('usage_limit', 'Total uses allowed (optional)', (string) $ev('usage_limit'), null, ['inputmode' => 'numeric']) ?>
      <div class="field"><label for="starts_at">Starts (Lagos time, optional)</label><input id="starts_at" type="datetime-local" name="starts_at" value="<?= e(iso_to_local(iso($ev('starts_at') ?: null))) ?>"></div>
      <div class="field"><label for="ends_at">Ends (optional)</label><input id="ends_at" type="datetime-local" name="ends_at" value="<?= e(iso_to_local(iso($ev('ends_at') ?: null))) ?>"></div>
    </div>
    <label class="check"><input type="checkbox" name="one_per_customer" value="1" <?= $ev('one_per_customer', 1) ? 'checked' : '' ?>><span>One use per customer</span></label>
    <div class="field" style="margin-top:12px"><label for="scope">Works on</label><select id="scope" name="scope"><option value="all"<?= $ev('scope', 'all') === 'all' ? ' selected' : '' ?>>All my products</option><option value="products"<?= $ev('scope', 'all') === 'products' ? ' selected' : '' ?>>Only the products I choose</option></select></div>
    <details<?= $ev('scope', 'all') === 'products' ? ' open' : '' ?>><summary>Choose products</summary>
      <div style="max-height:220px;overflow:auto;margin-top:8px"><?php $sel = (array) $ev('product_list', []); foreach ($all_products as $p): ?><label class="check"><input type="checkbox" name="product_ids[]" value="<?= e($p['id']) ?>" <?= in_array($p['id'], $sel, true) ? 'checked' : '' ?>><span><?= e($p['name']) ?></span></label><?php endforeach; if (!$all_products) echo '<p class="muted">Products appear here once submitted.</p>'; ?></div></details>
    <p class="row" style="margin-top:16px"><button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Create code' ?></button><?php if ($isEdit): ?><a class="btn btn-ghost" href="/seller?tab=promotions">Cancel</a><?php endif; ?></p>
  </form>
  </div>
  </div>
  <h2>Your codes</h2>
  <?php if (!$coupons): ?><p class="notice">No codes yet.</p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Code</th><th>Discount</th><th>Status</th><th class="t-right">Used</th><th class="t-right">You gave away</th><th></th></tr></thead><tbody>
  <?php foreach ($coupons as $c): if (str_starts_with($c['code'], 'DEL-')) continue; ?>
    <tr><td><strong><?= e($c['code']) ?></strong><br><span class="muted small"><?= $c['scope'] === 'all' ? 'All products' : count($c['product_list']) . ' product' . (count($c['product_list']) === 1 ? '' : 's') ?><?= $c['ends_at'] ? ' · ends ' . e(human_date($c['ends_at'], true)) : '' ?></span></td>
      <td><?= $c['kind'] === 'percent' ? e((string) $c['amount']) . '%' : e($c['currency'] . ' ' . number_format($c['amount'], 2)) . ' each' ?></td>
      <td><?= e(COUPON_STATE_LABEL[$c['state']] ?? $c['state']) ?></td><td class="t-right"><?= (int) $c['used_count'] ?><?= $c['usage_limit'] !== null ? ' / ' . (int) $c['usage_limit'] : '' ?></td><td class="t-right"><?= e(number_format($c['discount_total'], 2)) ?></td>
      <td><form method="post" action="/promotions/action" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
        <a class="btn btn-sm btn-outline" href="/seller?tab=promotions&amp;edit=<?= e($c['id']) ?>">Edit</a>
        <?php if (!$c['admin_disabled']): ?><button class="btn btn-sm btn-outline" name="action" value="<?= $c['active'] ? 'pause' : 'resume' ?>"><?= $c['active'] ? 'Pause' : 'Resume' ?></button><?php endif; ?>
        <button class="btn btn-sm btn-ghost" name="action" value="delete" data-confirm="Delete this code?">Delete</button></form></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'sales'): ?>
  <div class="row between" style="align-items:flex-end;margin-top:20px"><h2 style="margin:0">Recent sales</h2>
    <form method="get" action="/seller/export" class="row" data-keep><input type="hidden" name="kind" value="sales"><label class="small muted" for="se-from">From</label><input id="se-from" type="date" name="from" style="width:auto"><label class="small muted" for="se-to">to</label><input id="se-to" type="date" name="to" style="width:auto"><button class="btn btn-outline btn-sm" type="submit">Download CSV</button></form></div>
  <div class="table-wrap"><table><thead><tr><th>Order</th><th>Item</th><th class="t-right">Qty</th><th class="t-right">Sold for</th><th class="t-right">You earn</th><th>Customer</th><th>Delivery</th></tr></thead><tbody>
  <?php foreach ($sales as $s): $opts = jdec($s['options'], []); ?>
    <tr><td><strong><?= $s['order_number'] ? '#' . e($s['order_number']) : 'Order' ?></strong><br><span class="muted small"><?= e(human_date($s['placed_at'])) ?></span></td>
      <td><strong><?= e($s['name']) ?></strong><br><span class="muted small"><?= e($s['sku']) ?><?= $opts ? ' · ' . e(implode(' / ', $opts)) : '' ?></span></td>
      <td class="t-right"><?= (int) $s['quantity'] ?></td><td class="t-right"><?= e($m($s['line_total'], $s['currency'])) ?></td>
      <td class="t-right"><?= $s['status'] === 'refunded' ? pill('refunded') : e($m($s['net'], $s['currency'])) ?></td>
      <td><?= e($s['customer_name'] ?: '—') ?><br><span class="muted small"><?= e(trim($s['ship_city'] . ', ' . $s['ship_state'], ', ')) ?></span></td>
      <td><?php if ($s['status'] === 'refunded'): ?><?= pill('refunded') ?>
        <?php else: ?><?= pill($s['fulfilment'], FULFILMENT_LABEL, ['placed' => 'warn', 'processing' => 'warn', 'ready_for_shipping' => 'warn', 'completed' => 'ok']) ?>
          <?php if ($s['tracking_number'] || $s['carrier']): ?><br><span class="muted small"><?= e(trim($s['carrier'] . ' ' . $s['tracking_number'])) ?></span><?php endif; ?>
          <?php if (in_array($s['fulfilment'], ['placed', 'processing'], true)): ?>
            <form method="post" action="/orders/fulfil" class="row" style="margin-top:6px;gap:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($s['id']) ?>">
              <?php if ($s['fulfilment'] === 'placed'): ?><button class="btn btn-outline btn-sm" name="to" value="processing" type="submit">Start processing</button><?php endif; ?>
              <button class="btn btn-sm" name="to" value="ready_for_shipping" type="submit" data-confirm="Tell the marketplace this order is packed and ready to be picked up?">Ready for shipping</button></form>
          <?php elseif ($s['fulfilment'] === 'ready_for_shipping'): ?><br><span class="muted small">Waiting for DDLX pickup</span><?php endif; ?>
          <?php if (!empty($history[$s['id']])): ?><details class="small" style="margin-top:6px"><summary class="muted">History</summary><ul class="list"><?php foreach ($history[$s['id']] as $h): ?><li><?= e(LOG_LABEL[$h['status']] ?? $h['status']) ?> <span class="muted">· <?= e($h['source'] === 'seller' ? 'you' : 'DDLX') ?> · <?= e(human_date($h['created_at'])) ?><?= $h['note'] ? ' · ' . e($h['note']) : '' ?></span></li><?php endforeach; ?></ul></details><?php endif; ?>
        <?php endif; ?></td></tr>
  <?php endforeach; if (!$sales): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:40px">Sales will be listed here.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>

<?php if ($tab === 'returns'): ?>
  <ul class="list"><?php foreach ($cases as $c): ?><li>
    <div class="row between"><strong><?= e($c['item_name']) ?></strong> <?= pill($c['status'], RETURN_LABEL, RETURN_KINDS) ?></div>
    <div class="muted small">Order <?= e($c['number'] ?: $c['marketplace_order_id']) ?> · <?= e($m($c['line_total'], $c['currency'])) ?> · opened <?= e(human_date($c['created_at'], true)) ?></div>
    <div class="small" style="margin-top:6px"><strong><?= e($c['reason']) ?></strong><?= $c['details'] !== '' ? ' — ' . e($c['details']) : '' ?></div>
    <?php if ($c['seller_response'] !== ''): ?><div class="small" style="margin-top:6px"><span class="muted">You:</span> <?= e($c['seller_response']) ?></div><?php endif; ?>
    <?php if ($c['resolution_note'] !== ''): ?><div class="small" style="margin-top:6px"><span class="muted">DDLX:</span> <?= e($c['resolution_note']) ?></div><?php endif; ?>
    <?php if ($c['status'] === 'open'): ?><form method="post" action="/returns/respond" class="stack" style="margin-top:10px" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
      <textarea name="response" rows="2" maxlength="2000" placeholder="Your reply (required if you disagree)" aria-label="Your reply"></textarea>
      <div class="row"><button class="btn btn-sm" name="action" value="accept" type="submit" data-confirm="Accept this return? DDLX may refund the customer and take the earnings back.">I agree</button><button class="btn btn-sm btn-outline" name="action" value="dispute" type="submit">I disagree</button></div></form><?php endif; ?></li><?php endforeach; ?>
    <?php if (!$cases): ?><li class="muted">No return cases. Good.</li><?php endif; ?></ul>
<?php endif; ?>

<?php if ($tab === 'payouts'): $cur = $balances[0]['currency'] ?? 'NGN'; ?>
  <p class="small"><a href="/seller/export?kind=payouts">Download my wallet history (CSV)</a></p>
  <div class="pay-grid">
    <section>
      <h2>Withdraw money</h2>
      <?php if (!$accounts): ?><div class="card"><p class="muted" style="margin:0">Add a bank account first, then you can request a withdrawal.</p></div><?php else: ?>
      <form method="post" action="/payouts/request" class="card pay-card" data-keep><?= csrf_field() ?>
        <p class="muted small" style="margin-top:0">Smallest withdrawal <?= e($m(min_withdrawal(), $cur)) ?><?= withdrawal_fee() > 0 ? ' · fee ' . e($m(withdrawal_fee(), $cur)) . ' per withdrawal' : '' ?>.</p>
        <div class="grid g2">
          <div class="field"><label for="wd-cur">Currency</label><select id="wd-cur" name="currency"><?php foreach (($balances ?: [['currency' => 'NGN', 'available' => 0]]) as $b): ?><option value="<?= e($b['currency']) ?>"><?= e($b['currency']) ?> — <?= e($m($b['available'], $b['currency'])) ?> available</option><?php endforeach; ?></select></div>
          <?= f_text('amount', 'Amount', '', null, ['inputmode' => 'decimal', 'required' => 1]) ?>
        </div>
        <div class="field"><label for="wd-bank">Pay to</label><select id="wd-bank" name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['bank_name'] . ' · ' . $a['account_number'] . ' · ' . $a['account_name']) ?></option><?php endforeach; ?></select></div>
        <button class="btn" type="submit">Request withdrawal</button>
      </form><?php endif; ?>
    </section>
    <section>
      <h2>Bank accounts</h2>
      <ul class="bankcards"><?php foreach ($accounts as $a): ?><li><span class="bk-ic"><?= icon('wallet') ?></span><div class="grow"><strong><?= e($a['bank_name']) ?></strong><span class="muted small"><?= e($a['account_number']) ?> · <?= e($a['account_name']) ?></span></div>
        <form method="post" action="/payouts/bank" data-confirm="Remove this bank account?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($a['id']) ?>"><button class="btn btn-ghost btn-sm" name="action" value="remove">Remove</button></form></li><?php endforeach; ?>
        <?php if (!$accounts): ?><li class="muted">No bank accounts yet.</li><?php endif; ?></ul>
      <?php if (count($accounts) < MAX_BANK_ACCOUNTS): ?>
      <form method="post" action="/payouts/bank" class="card pay-card" style="margin-top:14px" data-keep data-bank-form data-verify="<?= $verify ? '1' : '0' ?>"><?= csrf_field() ?><h3 style="margin-top:0">Add a bank account</h3>
        <div class="field"><label for="f-bank_name">Bank <?= help('Pick your bank. Once you enter your 10-digit account number we fetch the account name from the bank, so there is nothing to type.') ?></label>
          <select id="f-bank_name" name="bank_name" required><option value="">Choose your bank…</option><?php foreach ($banks as $code => $bn): ?><option value="<?= e((string) $code) ?>"><?= e($bn) ?></option><?php endforeach; ?></select></div>
        <?= f_text('account_number', 'Account number', '', null, ['required' => 1, 'inputmode' => 'numeric', 'maxlength' => 10, 'autocomplete' => 'off', 'placeholder' => '10 digits']) ?>
        <div class="field"><label for="f-account_name">Account name</label><input id="f-account_name" name="account_name" maxlength="120" <?= $verify ? 'readonly placeholder="Fetched from your bank"' : 'placeholder="Name on the account" required' ?>><p class="small bk-status" data-bkstatus role="status"><?= $verify ? '' : 'Enter the name exactly as it appears on the account.' ?></p></div>
        <button class="btn" type="submit" data-bksave<?= $verify ? ' disabled' : '' ?>>Save account</button></form><?php endif; ?>
    </section>
  </div>

  <h2>Withdrawal history</h2>
  <ul class="list"><?php foreach ($withdrawals as $w): ?><li class="row between"><div><strong><?= e($m($w['amount'], $w['currency'])) ?></strong><?= (float) $w['fee'] > 0 ? ' <span class="muted small">(you receive ' . e($m((float) $w['amount'] - (float) $w['fee'], $w['currency'])) . ')</span>' : '' ?>
    <div class="muted small"><?= e(human_date($w['requested_at'])) ?> · <?= e($w['bank_name'] . ' ' . $w['account_number']) ?><?= $w['reference'] ? ' · Ref ' . e($w['reference']) : '' ?></div><?= $w['admin_note'] ? '<div class="small">' . e($w['admin_note']) . '</div>' : '' ?></div>
    <div class="row"><?= pill($w['status'], WITHDRAWAL_LABEL) ?><?php if ($w['status'] === 'requested'): ?><form method="post" action="/payouts/cancel" data-confirm="Cancel this withdrawal?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($w['id']) ?>"><button class="btn btn-ghost btn-sm">Cancel</button></form><?php endif; ?></div></li><?php endforeach; ?>
    <?php if (!$withdrawals): ?><li class="muted">No withdrawals yet.</li><?php endif; ?></ul>
<?php endif; ?>

<?php if ($tab === 'store'): ?>
  <section class="storehead" style="margin-top:14px">
    <div class="cover"><?php if ($store['banner']): ?><img src="/files/store?p=<?= e(rawurlencode($store['banner'])) ?>" alt="Banner"><?php else: ?><span>Banner · 1600×400 works best</span><?php endif; ?>
      <form method="post" action="/store/image" enctype="multipart/form-data" class="imgform" data-keep data-autosubmit><?= csrf_field() ?><input type="hidden" name="kind" value="banner">
        <button type="button" class="imgtrigger" data-imgbtn aria-haspopup="menu" aria-expanded="false" aria-label="Banner options"><span class="imgcue"><?= icon('image') ?><span class="cuetxt"><?= $store['banner'] ? 'Edit' : 'Add' ?> banner</span></span></button>
        <div class="imgpop" role="menu" hidden>
          <?php if ($store['banner']): ?><a role="menuitem" href="/files/store?p=<?= e(rawurlencode($store['banner'])) ?>" data-preview="/files/store?p=<?= e(rawurlencode($store['banner'])) ?>" data-title="Banner">View</a><?php endif; ?>
          <label role="menuitem" class="imgitem">Upload new<input class="sr" type="file" name="file" accept="image/jpeg,image/png,image/webp"></label>
          <?php if ($store['banner']): ?><button type="submit" role="menuitem" name="action" value="remove" formnovalidate class="imgdel">Remove</button><?php endif; ?>
          <span class="up-status small" data-upnote role="status"></span>
        </div>
      </form>
    </div>
    <div class="storeid">
      <div class="logo-wrap"><div class="logo-ring"><?php if ($store['logo']): ?><img src="/files/store?p=<?= e(rawurlencode($store['logo'])) ?>" alt="Logo"><?php else: ?><span>Logo</span><?php endif; ?></div>
        <form method="post" action="/store/image" enctype="multipart/form-data" class="imgform" data-keep data-autosubmit><?= csrf_field() ?><input type="hidden" name="kind" value="logo">
        <button type="button" class="imgtrigger" data-imgbtn aria-haspopup="menu" aria-expanded="false" aria-label="Logo options"><span class="imgcue"><?= icon('image') ?><span class="cuetxt"><?= $store['logo'] ? 'Edit' : 'Add' ?> logo</span></span></button>
        <div class="imgpop" role="menu" hidden>
          <?php if ($store['logo']): ?><a role="menuitem" href="/files/store?p=<?= e(rawurlencode($store['logo'])) ?>" data-preview="/files/store?p=<?= e(rawurlencode($store['logo'])) ?>" data-title="Logo">View</a><?php endif; ?>
          <label role="menuitem" class="imgitem">Upload new<input class="sr" type="file" name="file" accept="image/jpeg,image/png,image/webp"></label>
          <?php if ($store['logo']): ?><button type="submit" role="menuitem" name="action" value="remove" formnovalidate class="imgdel">Remove</button><?php endif; ?>
          <span class="up-status small" data-upnote role="status"></span>
        </div>
      </form>
      </div>
      <div class="grow"><strong class="store-title"><?= e(inbox_store_name($user['id']) ?: 'Your store') ?><?= verified_tick($user['id']) ?></strong><span class="muted small"><?= e($store['tagline'] !== '' ? $store['tagline'] : 'Preview of your marketplace store') ?> <?= help('This is how customers see your store on the marketplace. Click the banner or logo to view, replace or remove it.') ?></span></div>
      <?php if ($store['vacation']): ?><span class="pill pill-warn">On vacation</span><?php endif; ?>
    </div>
  </section>
  <form method="post" action="/store/save" data-keep><?= csrf_field() ?>
    <div class="grid g2 cols">
      <div class="card"><h3 style="margin-top:0">About</h3>
        <?= f_text('tagline', 'Tagline', $store['tagline'], null, ['maxlength' => 160]) ?>
        <?= f_area('description', 'About your store', $store['description'], null, ['rows' => 4, 'maxlength' => 3000]) ?>
        <div class="grid g2"><?= f_text('phone', 'Store phone', $store['phone'], null, ['type' => 'tel', 'maxlength' => 30]) ?><?= f_text('support_email', 'Support email', $store['support_email'], null, ['type' => 'email']) ?></div>
      </div>
      <div class="card"><h3 style="margin-top:0">Policies</h3>
        <?= f_area('shipping_policy', 'Shipping policy', $store['shipping_policy'], null, ['rows' => 3, 'maxlength' => 3000]) ?>
        <?= f_area('return_policy', 'Return policy', $store['return_policy'], null, ['rows' => 3, 'maxlength' => 3000]) ?>
      </div>
    </div>
    <div class="card" style="margin-top:12px"><?= f_check('vacation', 'Vacation mode: hide all my products from the marketplace until I switch it off', $store['vacation']) ?>
      <?= f_text('vacation_message', 'Message to customers', $store['vacation_message'], null, ['maxlength' => 300]) ?></div>
    <p style="margin-top:12px"><button class="btn" type="submit">Save store</button></p>
  </form>
  <div class="card" style="margin-top:24px"><h3 style="margin-top:0">Application and verification <?= verified_tick($user['id']) ?> <?= help('The details you submitted when you applied and were verified. You can read them here; contact the DDLX team to change them.') ?></h3>
    <p class="row" style="gap:12px"><a class="btn btn-outline btn-sm" href="/apply">View application</a><a class="btn btn-outline btn-sm" href="/verification">View verification</a></p></div>
<?php endif; ?>
