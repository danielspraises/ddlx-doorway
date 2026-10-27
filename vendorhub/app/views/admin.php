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
<p class="eyebrow"><?= $user['isAdmin'] ? 'Staff review queue' : 'Product review' ?></p>
<h1><?= e($tabs[$tab]) ?></h1>
<?= tabs_nav($nav, $tab, '/admin', ['applications' => $badges['applications'], 'verifications' => $badges['verifications'], 'products' => $badges['products'], 'payouts' => $badges['payouts']]) ?>

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

<?php if ($tab === 'payouts'): ?>
  <p class="muted">Pay the seller from your bank or Paystack dashboard, then enter the transfer reference here.</p>
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
    <li style="padding-left:<?= $d * 22 ?>px"><details><summary><span class="name<?= $c['active'] ? '' : ' off' ?>"><?= e($c['name']) ?></span> <span class="muted small"><?= (int) $c['product_count'] ?> product<?= $c['product_count'] === 1 ? '' : 's' ?><?= $c['active'] ? '' : ' · hidden' ?></span></summary>
      <div class="row" style="margin:10px 0">
        <?php if ($d < MAX_CATEGORY_DEPTH - 1): ?><form method="post" action="/admin/category" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="parent_id" value="<?= e($c['id']) ?>"><input name="name" placeholder="New sub-category" maxlength="80" required><button class="btn btn-sm btn-outline">Add sub</button></form><?php endif; ?>
        <form method="post" action="/admin/category" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><input name="name" value="<?= e($c['name']) ?>" maxlength="80" required><button class="btn btn-sm btn-outline">Rename</button></form>
        <form method="post" action="/admin/category" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
          <select name="to" aria-label="Move to"><option value="root">Top level</option><?php $ban = category_descendant_ids($c['id'], $all); foreach ($flat as $o) { if (in_array($o['category']['id'], $ban, true)) continue; echo '<option value="' . e($o['category']['id']) . '">' . e(str_repeat('— ', $o['depth']) . $o['category']['name']) . '</option>'; } ?></select><button class="btn btn-sm btn-outline">Move</button></form>
        <form method="post" action="/admin/category" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn btn-sm btn-ghost" name="action" value="<?= $c['active'] ? 'hide' : 'show' ?>"><?= $c['active'] ? 'Hide' : 'Show' ?></button>
          <?php if (!category_children($c['id'], $all) && !$c['product_count']): ?><button class="btn btn-sm btn-danger" name="action" value="delete" data-confirm="Delete “<?= e($c['name']) ?>”?">Delete</button><?php endif; ?></form>
      </div></details></li><?php endforeach; ?></ul>
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

<?php if ($tab === 'settings'): ?>
  <form method="post" action="/admin/settings" class="card" style="max-width:640px" data-keep><?= csrf_field() ?>
    <h3 style="margin-top:0">Money</h3>
    <div class="grid g2"><?= f_text('commission_percent', 'Marketplace commission (%)', (string) setting('commission_percent', '10'), null, ['inputmode' => 'decimal']) ?><?= f_text('payout_hold_days', 'Payout hold (days)', (string) setting('payout_hold_days', '7'), null, ['inputmode' => 'numeric', 'hint' => 'Sales become withdrawable after this many days.']) ?>
      <?= f_text('min_withdrawal', 'Smallest withdrawal', (string) setting('min_withdrawal', '1000'), null, ['inputmode' => 'decimal']) ?><?= f_text('withdrawal_fee', 'Fee per withdrawal', (string) setting('withdrawal_fee', '0'), null, ['inputmode' => 'decimal']) ?></div>
    <h3>Addresses</h3>
    <?= f_text('app_url', 'Vendor Hub address', (string) setting('app_url', ''), null, ['type' => 'url', 'placeholder' => 'https://vendor.ddlx.shop']) ?>
    <?= f_text('marketplace_url', 'Marketplace address', (string) setting('marketplace_url', ''), null, ['type' => 'url', 'placeholder' => 'https://ddlx.shop', 'hint' => 'Used to check SKUs against the store. Leave blank to skip.']) ?>
    <?= f_text('mail_from', 'Email sender address', (string) setting('mail_from', ''), null, ['type' => 'email', 'placeholder' => 'no-reply@ddlx.shop']) ?>
    <button class="btn">Save settings</button>
  </form>
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
