<?php /** @var array $user @var array $share @var array $assets @var array $ref */
$link = share_url($share['code']);
$invite = join_url($share['code']);
$store = inbox_store_name($user['id']) ?: 'my store';
$slug = slugify($store);
?>
<section class="promo-card">
  <h2>Your store QR code</h2>
  <div class="promo-qr">
    <img class="promo-thumb" src="/seller/flyer?v=<?= e(substr(md5((string) setting('flyer_template', '')), 0, 8)) ?>" alt="Your flyer" width="1080" height="1350" loading="lazy">
    <div class="promo-body">
      <label class="promo-label" for="share-link">Store link</label>
      <div class="copyrow"><input readonly value="<?= e($link) ?>" id="share-link"><button class="btn btn-sm btn-outline" type="button" data-copy="#share-link">Copy</button></div>
      <div class="promo-actions">
        <button class="btn" type="button" data-flyer-png="/seller/flyer" data-name="<?= e($slug) ?>-ddlx-flyer.png">Download flyer</button>
        <a class="btn btn-outline" href="/seller/flyer?download=1">SVG</a>
        <a class="btn btn-outline" download="<?= e($slug) ?>-qr.svg" href="data:image/svg+xml;base64,<?= base64_encode(qr_svg($link, 'Your store QR code')) ?>">QR only</a>
      </div>
      <p class="promo-meta"><strong><?= (int) $share['scans'] ?></strong> scan<?= (int) $share['scans'] === 1 ? '' : 's' ?><?= $share['last_scan_at'] ? ' · last ' . e(human_date($share['last_scan_at'], true)) : '' ?></p>
    </div>
  </div>
</section>

<section class="promo-card">
  <h2>From DDLX</h2>
  <?php if (!$assets): ?><p class="muted" style="margin:0">Nothing yet. Promo material from DDLX will show up here.</p>
  <?php else: ?><ul class="asset-list asset-flat"><?php foreach ($assets as $a): ?><li class="asset">
    <div class="asset-thumb"><?php if (str_starts_with($a['mime'], 'image/')): ?><img src="/files/promo?id=<?= e($a['id']) ?>&amp;preview=1" alt="" loading="lazy"><?php else: ?><span><?= e(promo_kind_label($a['mime'])) ?></span><?php endif; ?></div>
    <div class="asset-info"><strong><?= e($a['title']) ?></strong><?php if ($a['description'] !== ''): ?><p class="muted small"><?= e($a['description']) ?></p><?php endif; ?></div>
    <a class="btn btn-sm" href="/files/promo?id=<?= e($a['id']) ?>">Download</a></li><?php endforeach; ?></ul><?php endif; ?>
</section>

<section class="promo-card">
  <h2>Invite sellers</h2>
  <div class="copyrow"><input readonly value="<?= e($invite) ?>" aria-label="Your invite link" id="invite-link"><button class="btn btn-sm btn-outline" type="button" data-copy="#invite-link">Copy</button>
    <a class="btn btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text=<?= e(rawurlencode('I sell on DDLX and it is working well for me. You can apply to sell there too: ' . $invite)) ?>">WhatsApp</a></div>
  <p class="promo-meta"><strong><?= (int) $ref['applied'] ?></strong> applied · <strong><?= (int) $ref['verified'] ?></strong> verified</p>
</section>
