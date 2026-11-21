<?php
/** @var array $f @var ?array $existing @var ?array $parent @var array $eligible @var ?string $error @var ?array $rows @var array $user */
$previewed = !empty($previewed);
$cur = $f['currency'] ?: 'NGN';
$currencies = array_values(array_unique(array_column($eligible, 'currency')));
if (!in_array($cur, CURRENCIES, true)) $cur = 'NGN';
if (!$existing && !$currencies) $currencies = ['NGN'];
if (!$existing && $f['value'] === '' && ($f['currency'] === 'NGN') && $currencies && !in_array('NGN', $currencies, true)) $cur = $currencies[0];
$sel = array_flip($f['product_list']);
$locked = (bool) $parent;
$title = $existing ? 'Edit ' . $existing['name'] : ($parent ? 'Join ' . $parent['name'] : 'New sale');
$price = fn (array $p) => $p['type'] === 'variable'
    ? (($r = array_filter(array_column($p['vars'], 'regular'))) ? sale_money_range([min($r), max($r)], $p['currency']) : '–')
    : ($p['regular'] !== null ? dash_money($p['regular'], $p['currency']) : '–');
?>
<p><a href="<?= $existing ? '/sales/view?id=' . e($existing['id']) : '/seller?tab=promotions' ?>" class="small">← <?= $existing ? e($existing['name']) : 'Promotions' ?></a></p>
<h1 style="margin-bottom:18px"><?= e($title) ?></h1>
<?php if ($error): ?><p class="flash flash-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($previewed): ?><p class="flash flash-ok" role="status">Prices look good. Press Schedule sale when you are ready.</p><?php endif; ?>

<form method="post" action="/sales/save" class="sform" data-keep>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="parent_id" value="<?= e($f['parent_id']) ?>"><input type="hidden" name="currency" value="<?= e($cur) ?>">

  <section class="scard">
    <h3>When</h3>
    <?php if ($locked): ?>
      <p style="margin:0"><strong><?= e($parent['name']) ?></strong> · <?= e(sale_dates($parent)) ?></p>
      <?php if ($parent['note']): ?><p class="muted small" style="margin:6px 0 0"><?= e($parent['note']) ?></p><?php endif; ?>
      <?php if ($parent['min_discount'] !== null): ?><p class="muted small" style="margin:6px 0 0">Give at least <?= e(rtrim(rtrim(number_format($parent['min_discount'], 2), '0'), '.')) ?>% off.</p><?php endif; ?>
    <?php else: ?>
    <div class="field"><label for="f-name">Name</label><input id="f-name" name="name" value="<?= e($f['name']) ?>" maxlength="60" placeholder="December sale" required></div>
    <?php $started = $existing && $existing['starts_on'] <= sale_today(); ?>
    <div class="grid g2">
      <div class="field"><label for="f-start">Starts</label><input id="f-start" type="date" name="starts_on" value="<?= e($f['starts_on']) ?>" min="<?= e(sale_today()) ?>" <?= $started ? 'readonly' : 'required' ?>></div>
      <div class="field"><label for="f-end">Ends</label><input id="f-end" type="date" name="ends_on" value="<?= e($f['ends_on']) ?>" min="<?= e(sale_today()) ?>" required></div>
    </div>
    <?php endif; ?>
  </section>

  <section class="scard">
    <h3>Discount</h3>
    <div class="segr" role="radiogroup" aria-label="Discount">
      <?php foreach (SALE_RULES as $k => $l): ?><label><input type="radio" name="rule" value="<?= e($k) ?>" <?= $f['rule'] === $k ? 'checked' : '' ?>><span><?= e($l) ?></span></label><?php endforeach; ?>
    </div>
    <div class="only-notcustom" style="margin-top:14px;max-width:260px">
      <div class="field" style="margin:0"><label for="f-value"><span class="u-percent">Percent off</span><span class="u-amount">Amount off each item</span><span class="u-fixed">Sale price for every product</span></label>
        <div class="unit"><span class="pre u-money"><?= e(CURRENCY_SYMBOLS[$cur] ?? $cur) ?></span><input id="f-value" name="value" value="<?= e($f['value']) ?>" inputmode="decimal" placeholder="20"><span class="post u-percent">%</span></div></div>
    </div>
  </section>

  <section class="scard">
    <h3>Products</h3>
    <?php if (!$eligible): ?><p class="muted" style="margin:0">No live products yet</p><?php else: ?>
    <div class="segr only-notcustom" role="radiogroup" aria-label="Products">
      <label><input type="radio" name="scope" value="all" <?= $f['scope'] === 'all' ? 'checked' : '' ?>><span>All products</span></label>
      <label><input type="radio" name="scope" value="selected" <?= $f['scope'] === 'selected' ? 'checked' : '' ?>><span>Only chosen</span></label>
      <label><input type="radio" name="scope" value="except" <?= $f['scope'] === 'except' ? 'checked' : '' ?>><span>All except</span></label>
    </div>
    <div class="only-chosen">
      <div class="filterbar" data-fbar="sp"><input type="search" data-fq placeholder="Search products" aria-label="Search products"><span class="muted small" data-fcount></span></div>
      <div class="slist" data-fbox="sp">
        <?php foreach ($eligible as $p): ?>
        <label class="srow" data-fitem data-text="<?= e(mb_strtolower($p['name'])) ?>">
          <input type="checkbox" name="product_ids[]" value="<?= e($p['id']) ?>" <?= isset($sel[$p['id']]) ? 'checked' : '' ?>>
          <?php if ($p['image']): ?><img class="thumb" src="<?= e(product_image_url($p['image'])) ?>" alt=""><?php else: ?><span class="thumb"></span><?php endif; ?>
          <span class="grow"><span class="nm"><?= e($p['name']) ?></span></span><span class="muted small"><?= e($price($p)) ?></span>
        </label>
        <?php endforeach; ?>
        <p class="muted small" data-fempty hidden style="padding:12px">No products match.</p>
      </div>
    </div>
    <div class="only-custom">
      <div class="filterbar" data-fbar="sc"><input type="search" data-fq placeholder="Search products" aria-label="Search products"><span class="muted small" data-fcount></span></div>
      <div class="slist" data-fbox="sc">
        <?php foreach ($eligible as $p): ?>
        <div class="srow" data-fitem data-text="<?= e(mb_strtolower($p['name'])) ?>">
          <?php if ($p['image']): ?><img class="thumb" src="<?= e(product_image_url($p['image'])) ?>" alt=""><?php else: ?><span class="thumb"></span><?php endif; ?>
          <span class="grow"><span class="nm"><?= e($p['name']) ?></span><span class="muted small">Now <?= e($price($p)) ?></span></span>
          <input class="sp" name="price[<?= e($p['id']) ?>]" value="<?= e((string) ($f['price_map'][$p['id']] ?? '')) ?>" inputmode="decimal" placeholder="Sale price" aria-label="Sale price for <?= e($p['name']) ?>">
        </div>
        <?php endforeach; ?>
        <p class="muted small" data-fempty hidden style="padding:12px">No products match.</p>
      </div>
    </div>
    <?php endif; ?>
  </section>

  <div class="row sactions">
    <button class="btn btn-outline" type="submit" name="do" value="preview">Preview prices</button>
    <button class="btn" type="submit" name="do" value="save"><?= $existing ? 'Save changes' : 'Schedule sale' ?></button>
  </div>
</form>

<?php if ($rows): $applied = array_filter($rows, fn ($r) => $r['applied']); ?>
<section class="scard" style="margin-top:20px">
  <div class="dhead"><h3>Preview</h3><span class="muted small"><?= count($applied) ?> product<?= count($applied) === 1 ? '' : 's' ?> on sale<?= count($rows) > count($applied) ? ' · ' . (count($rows) - count($applied)) . ' unchanged' : '' ?></span></div>
  <?php include __DIR__ . '/sale_rows.php'; ?>
</section>
<?php endif; ?>
