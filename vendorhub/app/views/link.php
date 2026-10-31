<p class="eyebrow">Admin</p>
<h1>Link to the marketplace</h1>
<p class="muted" style="max-width:640px"><strong><?= e($store) ?></strong> (<?= e($email) ?>). Match this seller to their existing store on ddlx.shop. After the link, their products and past orders are brought across and kept in step from here.</p>
<p><a class="btn btn-ghost btn-sm" href="/admin?tab=sellers">&larr; Back to sellers</a></p>

<?php if (!$connected): ?>
  <p class="notice">The marketplace is not connected yet. Add its address and key under Settings, and install the plugin on ddlx.shop.</p>
<?php elseif (!$link): ?>
  <form method="get" action="/admin/link" class="row" style="margin:12px 0" data-keep><input type="hidden" name="seller" value="<?= e($sid) ?>"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name or email" style="max-width:280px"><button class="btn btn-sm btn-outline">Search</button><?php if ($q !== ''): ?><a class="btn btn-sm btn-ghost" href="/admin/link?seller=<?= e($sid) ?>">Clear</a><?php endif; ?></form>
  <?php if ($vendors === null): ?>
    <p class="notice error">The marketplace did not answer. Check that the plugin is active (version 1.4.0 or newer) and try again.</p>
  <?php elseif (!$vendors): ?>
    <p class="muted">No marketplace sellers found<?= $q !== '' ? ' for "' . e($q) . '"' : '' ?>.</p>
  <?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Marketplace seller</th><th>Email</th><th class="t-right">Products</th><th></th></tr></thead><tbody>
  <?php foreach ($vendors as $v): ?>
    <tr><td><strong><?= e($v['name']) ?></strong> <span class="muted small">#<?= (int) $v['id'] ?></span><?= !empty($v['why']) ? '<br><span class="pill pill-ok">' . e($v['why']) . '</span>' : '' ?></td>
      <td><?= e($v['email']) ?></td><td class="t-right"><?= (int) ($v['product_count'] ?? 0) ?></td>
      <td class="t-right"><?php if ($v['linked_here']): ?><span class="muted small">Linked to <?= e($v['linked_here']) ?></span>
        <?php else: ?><form method="post" action="/admin/link" data-keep><?= csrf_field() ?><input type="hidden" name="seller" value="<?= e($sid) ?>"><input type="hidden" name="uid" value="<?= (int) $v['id'] ?>"><input type="hidden" name="name" value="<?= e($v['name']) ?>"><button class="btn btn-sm" type="submit">Link</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
<?php else: ?>
  <div class="card" style="max-width:720px" data-link-box data-seller="<?= e($sid) ?>">
    <div class="row between"><div><span class="pill pill-ok">Linked</span> <strong><?= e($link['name']) ?></strong> <span class="muted small">marketplace seller #<?= (int) $link['uid'] ?></span></div>
      <?php if (!$counts['products'] && !$counts['orders']): ?><form method="post" action="/admin/link" data-keep><?= csrf_field() ?><input type="hidden" name="seller" value="<?= e($sid) ?>"><input type="hidden" name="action" value="unlink"><button class="btn btn-sm btn-ghost" type="submit">Remove link</button></form><?php endif; ?></div>
    <hr class="sep">
    <h3 style="margin-top:0">1. Products</h3>
    <p class="muted small">Copies their existing products (with photos, prices, stock and variations) into Vendor Hub and ties each to the same product on the store, so nothing is duplicated. Safe to run again.</p>
    <p class="row"><button class="btn" type="button" data-run="products">Import products</button><span class="muted small" data-count="products"><?= (int) $counts['products'] ?> imported so far</span></p>
    <hr class="sep">
    <h3 style="margin-top:0">2. Past orders</h3>
    <p class="muted small">Brings in their sales history so charts and levels are complete. Finished orders are shown for history only and add nothing to the wallet. Orders still on their way are paid out normally once delivered. Import products first.</p>
    <p class="row"><button class="btn" type="button" data-run="orders"<?= $counts['products'] ? '' : ' disabled' ?>>Import past orders</button><span class="muted small" data-count="orders"><?= (int) $counts['orders'] ?> orders imported so far</span></p>
    <p class="muted small" data-status role="status"></p>
    <ul class="list hidden" data-notes style="margin-top:8px"></ul>
  </div>
  <p class="muted small" style="max-width:720px;margin-top:12px">After importing, this seller's products here are the master copy: edit them in Vendor Hub and the store follows within a few minutes. Their store page and address on ddlx.shop stay exactly as they are.</p>
<?php endif; ?>
