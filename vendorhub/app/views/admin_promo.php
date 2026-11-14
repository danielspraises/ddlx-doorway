<?php /** @var array $items @var array $options @var array $flyer @var array $shares @var array $leaders @var string $q */
$audFields = function (array $a, string $pfx) use ($options): string {
    $h = '<fieldset class="aud" data-aud><legend class="small muted">Who can download it</legend>';
    foreach (['all' => 'All verified sellers', 'levels' => 'Sellers at certain levels', 'sellers' => 'Chosen sellers'] as $k => $l) $h .= '<label class="check"><input type="radio" name="mode" value="' . $k . '" ' . ($a['mode'] === $k ? 'checked' : '') . '><span>' . e($l) . '</span></label>';
    $h .= '<div class="aud-levels" data-aud-for="levels"><div class="row">';
    foreach (LEVELS as $lv) $h .= '<label class="check"><input type="checkbox" name="levels[]" value="' . $lv . '" ' . (in_array($lv, $a['levels'], true) ? 'checked' : '') . '><span>' . e(LEVEL_LABEL[$lv]) . '</span></label>';
    $h .= '</div></div><div class="aud-sellers" data-aud-for="sellers"><select name="sellers[]" multiple size="6" aria-label="Chosen sellers">';
    foreach ($options as $id => $nm) $h .= '<option value="' . e($id) . '" ' . (in_array($id, $a['sellers'], true) ? 'selected' : '') . '>' . e($nm) . '</option>';
    return $h . '</select><span class="hint">Hold Ctrl (or Cmd) to pick several.</span></div></fieldset>';
};
?>
<p class="muted">Send banners, posters and guides to the sellers you choose, and set the flyer every seller uses for their store QR code.</p>

<section class="promo-sec">
  <h2>Share a file with sellers</h2>
  <form method="post" action="/admin/promo/save" enctype="multipart/form-data" class="card" data-keep><?= csrf_field() ?>
    <div class="grid g2">
      <?= f_text('title', 'Title', '', null, ['required' => 1, 'maxlength' => 120, 'placeholder' => 'December sale banner']) ?>
      <div class="field"><label for="promo-file">File</label><input id="promo-file" type="file" name="file" accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4" required><span class="hint">Image, PDF or MP4 video, up to 12 MB.</span></div>
    </div>
    <?= f_area('description', 'Note for sellers (optional)', '', null, ['rows' => 2, 'maxlength' => 500]) ?>
    <?= $audFields(['mode' => 'all', 'levels' => [], 'sellers' => []], 'new') ?>
    <label class="check" style="margin-top:10px"><input type="checkbox" name="notify" value="yes" checked><span>Notify the sellers who can download it</span></label>
    <input type="hidden" name="active" value="1">
    <div class="row" style="margin-top:12px"><button class="btn" type="submit">Upload and share</button></div>
  </form>
</section>

<section class="promo-sec">
  <h2>Shared files</h2>
  <?php if (!$items): ?><p class="muted">Nothing shared yet.</p><?php else: ?>
  <ul class="asset-list"><?php foreach ($items as $a): ?><li class="asset asset-admin">
    <div class="asset-thumb"><?php if (str_starts_with($a['mime'], 'image/')): ?><img src="/files/promo?id=<?= e($a['id']) ?>&amp;preview=1" alt="" loading="lazy"><?php else: ?><span><?= e(promo_kind_label($a['mime'])) ?></span><?php endif; ?></div>
    <div class="asset-info"><strong><?= e($a['title']) ?></strong> <?= (int) $a['active'] === 1 ? '' : '<span class="pill">Hidden</span>' ?>
      <p class="muted small" style="margin:2px 0 0"><?= e($a['aud_label']) ?> · <?= e(promo_kind_label($a['mime'])) ?> · <?= (int) $a['people'] ?> seller<?= (int) $a['people'] === 1 ? '' : 's' ?> downloaded (<?= (int) $a['downloads'] ?> downloads)</p>
      <details class="asset-edit"><summary>Edit who gets it</summary>
        <form method="post" action="/admin/promo/save" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($a['id']) ?>">
          <?= f_text('title', 'Title', $a['title'], null, ['maxlength' => 120]) ?><?= f_area('description', 'Note for sellers', $a['description'], null, ['rows' => 2, 'maxlength' => 500]) ?>
          <?= $audFields($a['aud'], $a['id']) ?>
          <label class="check" style="margin-top:8px"><input type="checkbox" name="active" value="1" <?= (int) $a['active'] === 1 ? 'checked' : '' ?>><span>Visible to sellers</span></label>
          <div class="row" style="margin-top:10px"><button class="btn btn-sm" type="submit">Save</button></div></form></details></div>
    <div class="asset-actions"><a class="btn btn-sm btn-outline" href="/files/promo?id=<?= e($a['id']) ?>">Download</a>
      <form method="post" action="/admin/promo/delete" data-keep data-confirm="Delete this file? Sellers will no longer be able to download it."><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($a['id']) ?>"><button class="btn btn-sm btn-ghost" type="submit">Delete</button></form></div></li><?php endforeach; ?></ul><?php endif; ?>
</section>

<section class="promo-sec">
  <h2>Seller flyer template <?= help('Every verified seller gets this flyer with their own store name and QR code on it. Upload a background image designed in Canva or similar (1080 x 1350 px works best), keep the middle free for the QR code, and pick where it sits.') ?></h2>
  <div class="promo-grid">
    <div class="promo-flyer"><img src="/admin/flyer?v=<?= e(substr(md5((string) setting('flyer_template', '')), 0, 8)) ?>" alt="Flyer preview" width="1080" height="1350"></div>
    <form method="post" action="/admin/flyer/save" enctype="multipart/form-data" class="card" data-keep><?= csrf_field() ?>
      <?= f_text('headline', 'Headline', $flyer['headline'], null, ['maxlength' => 80, 'hint' => 'Use {store} for the seller\'s store name.']) ?>
      <?= f_text('subline', 'Line under the QR code', $flyer['subline'], null, ['maxlength' => 80]) ?>
      <?= f_text('footer', 'Footer', $flyer['footer'], null, ['maxlength' => 60]) ?>
      <div class="grid g3">
        <div class="field"><label for="fl-bg">Background colour</label><input id="fl-bg" type="color" name="bg_color" value="<?= e($flyer['bg_color']) ?>"></div>
        <div class="field"><label for="fl-tx">Text colour</label><input id="fl-tx" type="color" name="text_color" value="<?= e($flyer['text_color']) ?>"></div>
        <div class="field"><label for="fl-ac">Accent colour</label><input id="fl-ac" type="color" name="accent_color" value="<?= e($flyer['accent_color']) ?>"></div>
      </div>
      <div class="grid g2">
        <?= f_select('qr_pos', 'QR code position', ['upper' => 'Higher up', 'center' => 'Middle', 'lower' => 'Lower down'], $flyer['qr_pos']) ?>
        <?= f_text('qr_size', 'QR code size (30 to 62)', (string) $flyer['qr_size'], null, ['type' => 'number', 'min' => 30, 'max' => 62]) ?>
      </div>
      <div class="field"><label for="fl-img">Background image</label><input id="fl-img" type="file" name="bg" accept="image/jpeg,image/png,image/webp"><span class="hint">Optional. It fills the whole flyer. <?= $flyer['bg_file'] !== '' ? 'One is set now.' : 'None set.' ?></span></div>
      <?php if ($flyer['bg_file'] !== ''): ?><label class="check"><input type="checkbox" name="remove_bg" value="1"><span>Remove the current background image</span></label><?php endif; ?>
      <div class="row" style="margin-top:12px"><button class="btn" type="submit">Save flyer template</button></div>
    </form>
  </div>
</section>

<section class="promo-sec">
  <h2>Seller QR codes <?= help('Each seller has a short link that goes to their store on the marketplace. It counts scans. If a store page lives at a different address, put it in the box and the QR code will use it.') ?></h2>
  <form method="get" action="/admin" class="case-filters" data-keep><input type="hidden" name="tab" value="promo"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by store name" aria-label="Search sellers"><button class="btn btn-sm btn-outline" type="submit">Search</button></form>
  <?php if (!$shares): ?><p class="muted">No verified sellers yet.</p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Store</th><th>Scans</th><th>Goes to</th><th></th></tr></thead><tbody>
    <?php foreach ($shares as $s): ?><tr><td><strong><?= e($s['name']) ?></strong><br><span class="muted small"><?= e($s['code']) ?><?= $s['last'] ? ' · last scan ' . e(human_date($s['last'])) : '' ?></span></td><td><?= (int) $s['scans'] ?></td>
      <td><form method="post" action="/admin/share/target" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="seller" value="<?= e($s['id']) ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
        <input name="url" value="<?= e($s['override']) ?>" placeholder="<?= e($s['target'] ?: 'Set the marketplace address in Settings') ?>" aria-label="Store address for <?= e($s['name']) ?>" style="min-width:240px;flex:1"><button class="btn btn-sm btn-outline" type="submit">Save</button></form></td><td></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>

<section class="promo-sec">
  <h2>Seller invites <?= help('Sellers can invite brands they know from their Promo kit tab. Applications that come from an invite are credited to the seller who shared the link.') ?></h2>
  <?php if (!$leaders): ?><p class="muted">No invited applications yet.</p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Seller</th><th>Applied</th><th>Verified</th></tr></thead><tbody><?php foreach ($leaders as $l): ?><tr><td><?= e($l['store']) ?></td><td><?= (int) $l['applied'] ?></td><td><?= (int) $l['verified'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
