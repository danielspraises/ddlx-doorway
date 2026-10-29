<?php
/** @var array $form @var ?array $saved @var ?string $error @var ?string $notice @var bool $market */
$id = $form['id'];
$status = $saved['status'] ?? 'draft';
$locked = $saved && $status === 'pending_review';
$editable = !$locked;
$dis = $locked ? ' disabled' : '';
$v = fn (string $k) => (string) ($form[$k] ?? '');
$num = fn ($x) => $x === null ? '' : rtrim(rtrim(number_format((float) $x, 3, '.', ''), '0'), '.');
$ready = $saved ? readiness_problems($saved) : [];
[$visKind, $visLabel] = $saved ? product_visibility($saved) : ['draft', 'New product'];
$img = function (string $path, string $remove) use ($id, $editable): string {
    return '<div class="imgbox"><img src="' . e(product_image_url($path)) . '" alt="">'
        . ($editable ? '<form method="post" action="/products/image/remove" data-keep>' . csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="path" value="' . e($path) . '"><button type="submit" aria-label="Remove photo">×</button></form>' : '') . '</div>';
};
?>
<p><a href="/seller?tab=products" class="small">← Products</a></p>
<div class="row between"><h1><?= $saved ? e($saved['name'] ?: 'Product') : 'New product' ?></h1><?= pill($visKind, [], ['live' => 'ok', 'hidden' => 'warn']) ?> <?php if ($saved): ?><span class="muted small"><?= e($visLabel) ?></span><?php endif; ?></div>

<?php if ($error): ?><p class="flash flash-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($notice): ?><p class="flash flash-ok" role="status"><?= e($notice) ?></p><?php endif; ?>
<?php if ($saved && !empty($saved['admin_comment']) && in_array($status, ['changes_requested', 'rejected', 'approved'], true)): ?>
  <div class="notice"><h3>Feedback from the review team</h3><p><?= nl2br(e($saved['admin_comment'])) ?></p></div>
<?php endif; ?>
<?php if ($locked): ?><div class="notice"><h3>In review</h3><p>You can't edit this product while it is being reviewed. Withdraw it from review to make changes.</p></div><?php endif; ?>
<?php if ($saved && $saved['live'] && $status !== 'approved'): ?><div class="notice"><h3>Customers still see the last approved version</h3><p>Your new changes go live only after the review team approves them. Stock changes apply at once.</p></div><?php endif; ?>

<form method="post" action="/products/save" data-keep class="editor"><?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= e((string) $id) ?>"><input type="hidden" name="expected" value="<?= e($v('expected')) ?>">
  <fieldset<?= $dis ?> style="border:0;padding:0;margin:0">
  <h2>General</h2>
  <div class="card">
    <?= f_text('name', 'Product name', $v('name'), null, ['required' => 1, 'maxlength' => 200]) ?>
    <div class="grid g2">
      <div class="field"><label for="f-sku">SKU <span class="err">*</span></label>
        <div class="row"><input id="f-sku" name="sku" value="<?= e($v('sku')) ?>" maxlength="100" style="flex:1;min-width:140px"><button class="btn btn-outline btn-sm" type="submit" name="action" value="generate_sku" data-gen-sku formnovalidate>Generate</button></div>
        <span class="hint"><?= $market ? 'Checked against the whole marketplace so it cannot clash.' : 'Must be unique across all sellers.' ?></span></div>
      <div class="field"><label for="f-product_type">Product type</label><select id="f-product_type" name="product_type"><option value="simple"<?= $v('product_type') === 'simple' ? ' selected' : '' ?>>Simple product</option><option value="variable"<?= $v('product_type') === 'variable' ? ' selected' : '' ?>>Variable (sizes, colours…)</option></select></div>
    </div>
    <div class="grid g2">
      <?= category_select($form['category_id'] ?? null) ?>
      <?= f_text('brand', 'Brand', $v('brand'), null, ['maxlength' => 100]) ?>
    </div>
    <?= f_text('tags', 'Tags', implode(', ', $form['tags'] ?? []), null, ['hint' => 'Separate with commas.']) ?>
    <?= f_area('short_description', 'Short description', $v('short_description'), null, ['rows' => 2, 'maxlength' => 500]) ?>
    <?= f_area('description', 'Description', $v('description'), null, ['rows' => 7, 'maxlength' => 10000]) ?>
  </div>

  <h2>Pricing</h2>
  <div class="card">
    <div class="grid g3">
      <div class="field"><label for="f-currency">Currency</label><select id="f-currency" name="currency"><?php foreach (CURRENCIES as $c): ?><option<?= $v('currency') === $c ? ' selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div>
      <?= f_text('regular_price', 'Regular price', $num($form['regular_price'] ?? null), null, ['inputmode' => 'decimal', 'hint' => $v('product_type') === 'variable' ? 'Variations have their own prices.' : '']) ?>
      <?= f_text('sale_price', 'Sale price', $num($form['sale_price'] ?? null), null, ['inputmode' => 'decimal']) ?>
    </div>
    <div class="grid g2">
      <?= f_text('sale_starts_at', 'Sale starts (optional)', iso_to_local($form['sale_starts_at'] ?? null), null, ['type' => 'datetime-local']) ?>
      <?= f_text('sale_ends_at', 'Sale ends (optional)', iso_to_local($form['sale_ends_at'] ?? null), null, ['type' => 'datetime-local', 'hint' => 'Times are in Lagos time.']) ?>
    </div>
  </div>

  <h2>Inventory</h2>
  <div class="card">
    <?= f_check('manage_stock', 'Track stock quantity', !empty($form['manage_stock'])) ?>
    <div class="grid g3">
      <?= f_text('stock', 'Stock', (string) ($form['stock'] ?? 0), null, ['inputmode' => 'numeric', 'hint' => 'For variable products, set stock on each variation.']) ?>
      <?= f_text('low_stock_threshold', 'Low-stock alert at', (string) ($form['low_stock_threshold'] ?? 5), null, ['inputmode' => 'numeric']) ?>
    </div>
    <?= f_check('allow_backorders', 'Allow backorders', !empty($form['allow_backorders'])) ?>
  </div>

  <h2>Shipping</h2>
  <div class="card">
    <p class="muted small" style="margin-top:0">Required. The marketplace uses these to price delivery.</p>
    <div class="grid g4">
      <?= f_text('weight_kg', 'Weight (kg)', $num($form['weight_kg'] ?? null), null, ['inputmode' => 'decimal', 'required' => 1]) ?>
      <?= f_text('length_cm', 'Length (cm)', $num($form['length_cm'] ?? null), null, ['inputmode' => 'decimal', 'required' => 1]) ?>
      <?= f_text('width_cm', 'Width (cm)', $num($form['width_cm'] ?? null), null, ['inputmode' => 'decimal', 'required' => 1]) ?>
      <?= f_text('height_cm', 'Height (cm)', $num($form['height_cm'] ?? null), null, ['inputmode' => 'decimal', 'required' => 1]) ?>
    </div>
  </div>

  <?php if ($v('product_type') === 'variable'): ?>
  <h2>Attributes and variations</h2>
  <div class="card">
    <?= f_area('attributes', 'Attributes', attributes_text($form['attributes'] ?? []), null, ['rows' => 3, 'hint' => 'One per line, for example "Size: S, M, L" then "Colour: Red, Blue".']) ?>
    <button class="btn btn-outline" type="submit" name="action" value="rebuild" formnovalidate>Build variations from attributes</button>
    <?php foreach (($form['variations'] ?? []) as $i => $row): ?>
      <div class="varrow">
        <input type="hidden" name="var[<?= $i ?>][id]" value="<?= e((string) ($row['id'] ?? '')) ?>">
        <input type="hidden" name="var[<?= $i ?>][options]" value="<?= e(jenc((object) $row['options'])) ?>">
        <p style="margin:0 0 10px"><strong><?= e(implode(' / ', $row['options'])) ?></strong></p>
        <div class="grid g4">
          <div class="field"><label>SKU</label><input name="var[<?= $i ?>][sku]" value="<?= e($row['sku']) ?>" maxlength="100"></div>
          <div class="field"><label>Regular price</label><input name="var[<?= $i ?>][regular_price]" value="<?= e($num($row['regular_price'])) ?>" inputmode="decimal"></div>
          <div class="field"><label>Sale price</label><input name="var[<?= $i ?>][sale_price]" value="<?= e($num($row['sale_price'])) ?>" inputmode="decimal"></div>
          <div class="field"><label>Stock</label><input name="var[<?= $i ?>][stock]" value="<?= (int) $row['stock'] ?>" inputmode="numeric"></div>
          <div class="field"><label>Sale starts</label><input type="datetime-local" name="var[<?= $i ?>][sale_starts_at]" value="<?= e(iso_to_local($row['sale_starts_at'] ?? null)) ?>"></div>
          <div class="field"><label>Sale ends</label><input type="datetime-local" name="var[<?= $i ?>][sale_ends_at]" value="<?= e(iso_to_local($row['sale_ends_at'] ?? null)) ?>"></div>
        </div>
        <label class="check" style="margin:0"><input type="checkbox" name="var[<?= $i ?>][enabled]" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>><span>Enabled</span></label>
      </div>
    <?php endforeach; ?>
    <?php if (empty($form['variations'])): ?><p class="muted">No variations yet. Add attributes above, then build them.</p><?php endif; ?>
  </div>
  <?php else: ?>
    <input type="hidden" name="attributes" value="">
  <?php endif; ?>
  </fieldset>

  <div class="savebar">
    <?php if ($editable): ?>
      <span class="muted small savebar-note"><?= $ready ? e(count($ready)) . ' thing' . (count($ready) === 1 ? '' : 's') . ' left before you can submit' : ($saved ? 'Ready to submit' : 'Save first, then add photos') ?></span>
      <button class="btn btn-outline" type="submit" name="action" value="save">Save</button>
      <?php if (in_array($status, ['draft', 'changes_requested', 'rejected'], true) || !$saved): ?><button class="btn btn-accent" type="submit" name="action" value="submit" data-confirm="Save and send this product for review?">Save and submit for review</button><?php endif; ?>
    <?php endif; ?>
  </div>
</form>

<?php if ($saved): ?>
  <h2>Photos</h2>
  <div class="card">
    <h3 style="margin-top:0">Featured image</h3>
    <div class="imgs"><?= $saved['featured_image'] ? $img($saved['featured_image'], 'featured') : '<span class="muted">None yet</span>' ?></div>
    <?php if ($editable): ?><form method="post" action="/products/image" enctype="multipart/form-data" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="slot" value="featured"><input type="file" name="file" accept="image/jpeg,image/png,image/webp" required><button class="btn btn-outline btn-sm" type="submit">Upload</button></form><?php endif; ?>
    <hr class="sep">
    <h3>Gallery <span class="muted small">(<?= count($saved['gallery']) ?> of <?= MAX_GALLERY ?>)</span></h3>
    <div class="imgs"><?php foreach ($saved['gallery'] as $g) echo $img($g, 'gallery'); ?><?php if (!$saved['gallery']): ?><span class="muted">None yet</span><?php endif; ?></div>
    <?php if ($editable && count($saved['gallery']) < MAX_GALLERY): ?><form method="post" action="/products/image" enctype="multipart/form-data" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="slot" value="gallery"><input type="file" name="files[]" multiple accept="image/jpeg,image/png,image/webp" required><button class="btn btn-outline btn-sm" type="submit">Upload photos</button><span class="hint">You can pick several at once.</span></form><?php endif; ?>
    <?php if ($saved['product_type'] === 'variable' && $saved['variations']): ?>
      <hr class="sep"><h3>Variation photos</h3>
      <?php foreach ($saved['variations'] as $sv): ?>
        <div class="row" style="margin-bottom:10px"><?= $sv['image'] ? $img($sv['image'], 'variation') : '<span class="thumb"></span>' ?><span><?= e(implode(' / ', $sv['options'])) ?></span>
          <?php if ($editable): ?><form method="post" action="/products/image" enctype="multipart/form-data" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="slot" value="variation"><input type="hidden" name="variation_id" value="<?= e($sv['id']) ?>"><input type="file" name="file" accept="image/jpeg,image/png,image/webp" required><button class="btn btn-outline btn-sm" type="submit">Upload</button></form><?php endif; ?></div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <h2>Status</h2>
  <div class="card">
    <?php if ($ready): ?><p class="label">Before you can submit:</p><ul><?php foreach ($ready as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul><?php else: ?><p class="pill pill-ok">Ready to submit</p><?php endif; ?>
    <div class="row" style="margin-top:12px">
      <?php if ($status === 'pending_review'): ?>
        <form method="post" action="/products/action"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="back" value="editor"><button class="btn btn-outline" name="action" value="withdraw">Withdraw from review</button></form>
      <?php endif; ?>
      <?php if ($saved['live']): ?>
        <form method="post" action="/products/action" data-confirm="Take this product off the marketplace?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="back" value="editor"><button class="btn btn-outline" name="action" value="unlive">Take off marketplace</button></form>
      <?php elseif ($status === 'approved' && $saved['has_snapshot']): ?>
        <form method="post" action="/products/action"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="back" value="editor"><button class="btn" name="action" value="live">Put back on marketplace</button></form>
      <?php endif; ?>
      <?php if (!$saved['live'] && $status !== 'pending_review'): ?>
        <form method="post" action="/products/action" data-confirm="Delete this product for good?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><button class="btn btn-danger" name="action" value="delete">Delete product</button></form>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <p class="muted">Save the product first, then you can add photos.</p>
<?php endif; ?>
