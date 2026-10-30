<?php
/** @var string $tab @var array $user */
$m = fn ($x, $c = 'NGN') => money($x, $c);
?>
<p class="eyebrow">Seller Center</p>
<h1><?= e(SELLER_TABS[$tab]) ?></h1>
<?= tabs_nav(SELLER_TABS, $tab, '/seller', ['returns' => $user['pending']['returns'] ?? 0]) ?>

<?php if (in_array($tab, ['overview', 'sales', 'payouts'], true)):
    $currencies = array_values(array_unique(array_merge(array_column($balances, 'currency'), array_column($month, 'currency'))));
    if ($currencies): ?>
  <?php foreach ($currencies as $c):
      $b = current(array_filter($balances, fn ($x) => $x['currency'] === $c)) ?: ['available' => 0, 'pending' => 0];
      $mo = current(array_filter($month, fn ($x) => $x['currency'] === $c)) ?: ['net' => 0, 'units' => 0, 'gross' => 0]; ?>
    <div class="grid g3" style="margin-top:20px">
      <div class="stat"><p class="muted small">Available to withdraw</p><p class="n"><?= e($m($b['available'], $c)) ?></p></div>
      <div class="stat"><p class="muted small">Pending (<?= hold_days() ?>-day hold)</p><p class="n"><?= e($m($b['pending'], $c)) ?></p></div>
      <div class="stat"><p class="muted small">Last 30 days</p><p class="n"><?= e($m($mo['net'], $c)) ?></p><p class="muted small"><?= (int) $mo['units'] ?> units · <?= e($m($mo['gross'], $c)) ?> sold</p></div>
    </div>
  <?php endforeach; ?>
  <p class="muted small">Your earnings are each sale minus the <?= e(rtrim(rtrim(number_format(commission_percent(), 2), '0'), '.')) ?>% marketplace commission. Sales become available to withdraw after <?= hold_days() ?> days.</p>
<?php else: ?><p class="notice">No sales yet. When a customer buys one of your products on the marketplace, it shows up here.</p><?php endif; endif; ?>

<?php if ($tab === 'overview'):
    $live = count(array_filter($products, fn ($p) => $p['live']));
    $review = count(array_filter($products, fn ($p) => $p['status'] === 'pending_review'));
    $low = array_filter($products, fn ($p) => in_array(stock_state($p), ['low', 'out'], true));
    $changes = array_filter($products, fn ($p) => in_array($p['status'], ['changes_requested', 'rejected'], true));
    $attention = array_slice(array_unique(array_merge(array_values($changes), array_values($low)), SORT_REGULAR), 0, 6); ?>
<?php
    $curLv = $level['override_level'] ?: $level['auto_level'];
    $nextLv = LEVELS[level_rank($curLv) + 1] ?? null;
    $nextChecks = $nextLv ? level_checks($nextLv, $lm) : [];
?>
  <div class="card level-card" style="margin-top:24px">
    <div class="level-big lvl-<?= e($curLv) ?>"><?= icon('shield') ?></div>
    <div>
      <p class="muted small" style="margin:0">Your seller level</p>
      <p style="margin:2px 0 4px"><?= level_badge($curLv) ?> <?php if ($level['override_level']): ?><span class="muted small">set by the DDLX team</span><?php endif; ?></p>
      <p class="muted" style="margin:0"><?= e(LEVEL_NOTE[$curLv]) ?><?= $rating['count'] ? ' Rating ' . number_format((float) $rating['average'], 1) . ' from ' . (int) $rating['count'] . ' customer ratings.' : '' ?></p>
      <?php if (!empty($level['below_since']) && !$level['override_level']): $pol = level_policy(); ?><p class="notice" style="margin:12px 0 0"><strong>Your <?= e(LEVEL_LABEL[$curLv]) ?> level is at risk.</strong> You no longer meet its requirements<?= $pol['mode'] === 'grace' ? '. Meet them again by ' . e(human_date(db_plus('+' . $pol['grace_days'] . ' days', $level['below_since']), false)) . ' to keep it' : '' ?>.</p>
        <ul class="need"><?php foreach (level_checks($curLv, $lm) as $c): ?><li class="<?= $c['ok'] ? 'ok' : '' ?>"><?= icon($c['ok'] ? 'check' : 'arrow-right') ?><span><?= e($c['label']) ?>: <strong><?= e($c['have']) ?></strong> <span class="muted">(<?= e($c['need']) ?>)</span></span></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if ($nextLv): ?><p style="margin:12px 0 0"><strong>To reach <?= e(LEVEL_LABEL[$nextLv]) ?>:</strong></p>
      <ul class="need"><?php foreach ($nextChecks as $c): ?><li class="<?= $c['ok'] ? 'ok' : '' ?>"><?= icon($c['ok'] ? 'check' : 'arrow-right') ?><span><?= e($c['label']) ?>: <strong><?= e($c['have']) ?></strong> <span class="muted">(<?= e($c['need']) ?>)</span></span></li><?php endforeach; ?></ul>
      <?php else: ?><p style="margin:12px 0 0"><strong>You are at the top level.</strong> Keep returns low and orders moving to stay here.</p><?php endif; ?>
      <p class="muted small" style="margin:10px 0 0">Based on the last <?= LEVEL_WINDOW_DAYS ?> days. Higher levels get a badge and better placement on the marketplace<?php $pk = level_perks($curLv); echo $pk['commission_off'] > 0 || $pk['hold_off'] > 0 ? ', plus ' . ($pk['commission_off'] > 0 ? $pk['commission_off'] . ' points off commission' : '') . ($pk['commission_off'] > 0 && $pk['hold_off'] > 0 ? ' and ' : '') . ($pk['hold_off'] > 0 ? 'payouts ' . $pk['hold_off'] . ' days sooner' : '') : ''; ?>.</p>
    </div>
  </div>
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
  <ul class="list">
  <?php foreach ($products as $p): [$k, $l] = product_visibility($p); ?>
    <li><div class="row between">
      <div class="row" style="flex-wrap:nowrap"><?php if ($p['featured_image']): ?><img class="thumb" src="<?= e(product_image_url($p['featured_image'])) ?>" alt=""><?php else: ?><span class="thumb"></span><?php endif; ?>
        <div><a href="/products/edit?id=<?= e($p['id']) ?>"><strong><?= e($p['name'] ?: 'Untitled') ?></strong></a>
          <div class="muted small"><?= e($p['sku']) ?> · <?= e(category_label($p)) ?> · <?= e(price_label($p)) ?><?= $p['product_type'] === 'variable' ? ' · ' . count($p['variations']) . ' variations' : '' ?></div>
          <div style="margin-top:6px"><?= pill($k, [], ['live' => 'ok', 'hidden' => 'warn']) ?> <span class="muted small"><?= e($l) ?></span> <?= stock_state($p) !== 'ok' ? pill(stock_state($p)) : '' ?></div></div></div>
      <div class="row">
        <a class="btn btn-outline btn-sm" href="/products/edit?id=<?= e($p['id']) ?>">Edit</a>
        <?php if (in_array($p['status'], ['draft', 'changes_requested', 'rejected'], true)): ?><form method="post" action="/products/action"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="btn btn-sm" name="action" value="submit">Submit</button></form><?php endif; ?>
        <?php if ($p['status'] === 'pending_review'): ?><form method="post" action="/products/action"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="btn btn-outline btn-sm" name="action" value="withdraw">Withdraw</button></form><?php endif; ?>
      </div></div></li>
  <?php endforeach; ?></ul>
<?php endif; ?>

<?php if ($tab === 'inventory'): ?>
  <p class="muted">Stock changes apply straight away, even on live products.</p>
  <?php if ($products): ?><details class="card" style="margin:12px 0"><summary><strong>Update many at once (CSV)</strong></summary>
    <p class="muted small">Download your current stock, change the <code>stock</code> numbers in Excel, and upload it back. Prices are not changed here because price changes go through review.</p>
    <form method="post" action="/inventory/import" enctype="multipart/form-data" class="row" data-keep><?= csrf_field() ?><a class="btn btn-outline btn-sm" href="/inventory/export">Download stock list</a><input type="file" name="file" accept=".csv,text/csv" required style="max-width:240px"><button class="btn btn-sm" type="submit">Upload</button></form></details><?php endif; ?>
  <?php if (!$products): ?><p class="notice">Add a product first.</p><?php endif; ?>
  <div class="table-wrap"><table><thead><tr><th>Product</th><th>SKU</th><th class="t-right">In stock</th><th>Change</th></tr></thead><tbody>
  <?php foreach ($products as $p):
      $rows = $p['product_type'] === 'variable' ? $p['variations'] : [['id' => null, 'sku' => $p['sku'], 'options' => [], 'stock' => $p['stock']]];
      foreach ($rows as $r): $state = $p['product_type'] === 'variable' ? ($r['stock'] <= 0 ? 'out' : ($r['stock'] <= $p['low_stock_threshold'] ? 'low' : 'ok')) : stock_state($p); ?>
    <tr><td><a href="/products/edit?id=<?= e($p['id']) ?>"><strong><?= e($p['name']) ?></strong></a><?= $r['options'] ? '<br><span class="muted small">' . e(implode(' / ', $r['options'])) . '</span>' : '' ?></td>
      <td><?= e($r['sku']) ?></td>
      <td class="t-right"><?= !$p['manage_stock'] && $p['product_type'] === 'simple' ? '<span class="muted">Not tracked</span>' : (int) $r['stock'] . ' ' . ($state !== 'ok' ? pill($state) : '') ?></td>
      <td><form method="post" action="/inventory/adjust" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>"><input type="hidden" name="variation_id" value="<?= e((string) $r['id']) ?>">
        <input type="number" name="value" min="0" max="1000000" style="width:90px" aria-label="Quantity" required>
        <button class="btn btn-outline btn-sm" name="op" value="add" type="submit">Add</button>
        <button class="btn btn-outline btn-sm" name="op" value="remove" type="submit">Remove</button>
        <button class="btn btn-sm" name="op" value="set" type="submit">Set to</button></form></td></tr>
  <?php endforeach; endforeach; ?></tbody></table></div>
<?php endif; ?>

<?php if ($tab === 'promotions'):
    $e = $edit ?? null; $isEdit = $e && empty($e['form_only']) || ($e && !empty($e['id']));
    $ev = fn (string $k, $d = '') => $e[$k] ?? $d; ?>
  <p class="muted" style="max-width:680px">Create discount codes for your own products. <strong>You fund the discount</strong>: customers pay less and your earnings are worked out on the lower price. Codes appear on the DDLX marketplace within a minute or two.</p>
  <?php if (!empty($err)): ?><p class="flash flash-error" role="alert"><?= e($err) ?></p><?php endif; ?>
  <div class="split"><div class="split-main">
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
  <aside class="split-side"><div class="card tip"><p class="eyebrow" style="margin:0 0 6px">Tips</p><ul class="muted small" style="margin:0;padding-left:18px"><li>Money off applies to each item, so two items get it twice.</li><li>Set an end date and a use limit so a code cannot run forever.</li><li>Codes only work on your products, never on other sellers'.</li></ul></div></aside>
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
  <p class="muted">When a customer complains, DDLX opens a case here. Accept it, or explain why you disagree. DDLX makes the final decision.</p>
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
  <h2>Withdraw money</h2>
  <?php if (!$accounts): ?><p class="muted">Add a bank account below, then request a withdrawal.</p><?php else: ?>
  <form method="post" action="/payouts/request" class="card" style="max-width:520px" data-keep><?= csrf_field() ?>
    <p class="muted small" style="margin-top:0">Smallest withdrawal <?= e($m(min_withdrawal(), $cur)) ?><?= withdrawal_fee() > 0 ? ' · fee ' . e($m(withdrawal_fee(), $cur)) . ' per withdrawal' : '' ?>.</p>
    <div class="grid g2">
      <div class="field"><label for="wd-cur">Currency</label><select id="wd-cur" name="currency"><?php foreach (($balances ?: [['currency' => 'NGN', 'available' => 0]]) as $b): ?><option value="<?= e($b['currency']) ?>"><?= e($b['currency']) ?> — <?= e($m($b['available'], $b['currency'])) ?> available</option><?php endforeach; ?></select></div>
      <?= f_text('amount', 'Amount', '', null, ['inputmode' => 'decimal', 'required' => 1]) ?>
    </div>
    <div class="field"><label for="wd-bank">Pay to</label><select id="wd-bank" name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['bank_name'] . ' · ' . $a['account_number'] . ' · ' . $a['account_name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn" type="submit">Request withdrawal</button>
  </form><?php endif; ?>

  <h2>Bank accounts</h2>
  <ul class="list"><?php foreach ($accounts as $a): ?><li class="row between"><div><strong><?= e($a['bank_name']) ?> · <?= e($a['account_number']) ?></strong><div class="muted small"><?= e($a['account_name']) ?></div></div>
    <form method="post" action="/payouts/bank" data-confirm="Remove this bank account?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($a['id']) ?>"><button class="btn btn-ghost btn-sm" name="action" value="remove">Remove</button></form></li><?php endforeach; ?>
    <?php if (!$accounts): ?><li class="muted">No bank accounts yet.</li><?php endif; ?></ul>
  <?php if (count($accounts) < MAX_BANK_ACCOUNTS): ?>
  <form method="post" action="/payouts/bank" class="card" style="max-width:520px;margin-top:16px" data-keep><?= csrf_field() ?><h3 style="margin-top:0">Add a bank account</h3>
    <?= f_text('bank_name', 'Bank', '', null, ['required' => 1, 'placeholder' => 'e.g. GTBank']) ?>
    <?= f_text('account_number', 'Account number', '', null, ['required' => 1, 'inputmode' => 'numeric', 'maxlength' => 10]) ?>
    <?= f_text('account_name', 'Account name', '', null, ['required' => 1]) ?>
    <button class="btn" type="submit">Save account</button></form><?php endif; ?>

  <h2>Withdrawal history</h2>
  <ul class="list"><?php foreach ($withdrawals as $w): ?><li class="row between"><div><strong><?= e($m($w['amount'], $w['currency'])) ?></strong><?= (float) $w['fee'] > 0 ? ' <span class="muted small">(you receive ' . e($m((float) $w['amount'] - (float) $w['fee'], $w['currency'])) . ')</span>' : '' ?>
    <div class="muted small"><?= e(human_date($w['requested_at'])) ?> · <?= e($w['bank_name'] . ' ' . $w['account_number']) ?><?= $w['reference'] ? ' · Ref ' . e($w['reference']) : '' ?></div><?= $w['admin_note'] ? '<div class="small">' . e($w['admin_note']) . '</div>' : '' ?></div>
    <div class="row"><?= pill($w['status'], WITHDRAWAL_LABEL) ?><?php if ($w['status'] === 'requested'): ?><form method="post" action="/payouts/cancel" data-confirm="Cancel this withdrawal?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($w['id']) ?>"><button class="btn btn-ghost btn-sm">Cancel</button></form><?php endif; ?></div></li><?php endforeach; ?>
    <?php if (!$withdrawals): ?><li class="muted">No withdrawals yet.</li><?php endif; ?></ul>
<?php endif; ?>

<?php if ($tab === 'store'): ?>
  <div class="grid g2" style="margin-top:20px">
  <?php foreach (['logo' => 'Logo', 'banner' => 'Banner'] as $kind => $label): ?>
    <div><p class="label"><?= $label ?></p>
      <div class="imgbox<?= $kind === 'banner' ? ' banner-box' : '' ?>"><?php if ($store[$kind]): ?><img src="/files/store?p=<?= e(rawurlencode($store[$kind])) ?>" alt="<?= e($label) ?>"><?php endif; ?></div>
      <form method="post" action="/store/image" enctype="multipart/form-data" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="kind" value="<?= $kind ?>"><input type="file" name="file" accept="image/jpeg,image/png,image/webp" required><button class="btn btn-outline btn-sm" name="action" value="upload">Upload</button><?php if ($store[$kind]): ?><button class="btn btn-ghost btn-sm" name="action" value="remove" formnovalidate>Remove</button><?php endif; ?></form></div>
  <?php endforeach; ?></div>
  <form method="post" action="/store/save" style="max-width:700px;margin-top:24px" data-keep><?= csrf_field() ?>
    <?= f_text('tagline', 'Tagline', $store['tagline'], null, ['maxlength' => 160]) ?>
    <?= f_area('description', 'About your store', $store['description'], null, ['rows' => 5, 'maxlength' => 3000]) ?>
    <div class="grid g2"><?= f_text('phone', 'Store phone', $store['phone'], null, ['type' => 'tel', 'maxlength' => 30]) ?><?= f_text('support_email', 'Support email', $store['support_email'], null, ['type' => 'email']) ?></div>
    <?= f_area('shipping_policy', 'Shipping policy', $store['shipping_policy'], null, ['rows' => 4, 'maxlength' => 3000]) ?>
    <?= f_area('return_policy', 'Return policy', $store['return_policy'], null, ['rows' => 4, 'maxlength' => 3000]) ?>
    <div class="card"><?= f_check('vacation', 'Vacation mode — hide all my products from the marketplace until I switch it off', $store['vacation']) ?>
      <?= f_text('vacation_message', 'Message to customers', $store['vacation_message'], null, ['maxlength' => 300]) ?></div>
    <p><button class="btn" type="submit">Save store</button></p>
  </form>
<?php endif; ?>
