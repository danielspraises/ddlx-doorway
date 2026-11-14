<?php /** @var array $user @var array $share @var array $assets @var array $ref @var string $flyerPng */
$link = share_url($share['code']);
$invite = join_url($share['code']);
$store = inbox_store_name($user['id']) ?: 'my store';
?>
<section class="promo-sec">
  <h2>Your store QR code <?= help('Customers scan it and land on your store on the DDLX marketplace. Print the flyer, stick the code on your packages, or share it on social media.') ?></h2>
  <div class="promo-grid">
    <div class="promo-flyer"><img src="/seller/flyer?v=<?= e(substr(md5((string) setting('flyer_template', '')), 0, 8)) ?>" alt="Your flyer with your store QR code" width="1080" height="1350" loading="lazy"></div>
    <div class="promo-side">
      <p class="muted small" style="margin:0 0 6px">Your link</p>
      <div class="copyrow"><input readonly value="<?= e($link) ?>" aria-label="Your store link" id="share-link"><button class="btn btn-sm btn-outline" type="button" data-copy="#share-link">Copy</button></div>
      <div class="row" style="margin:16px 0 8px">
        <button class="btn" type="button" data-flyer-png="/seller/flyer" data-name="<?= e(slugify($store)) ?>-ddlx-flyer.png">Download flyer (PNG)</button>
        <a class="btn btn-outline" href="/seller/flyer?download=1">Download (SVG)</a>
      </div>
      <p class="muted small" style="margin:0 0 18px">PNG is best for WhatsApp and Instagram. SVG stays sharp when printed large.</p>
      <div class="stat" style="max-width:260px"><p class="muted small">Scans so far</p><p class="n"><?= (int) $share['scans'] ?></p><p class="muted small"><?= $share['last_scan_at'] ? 'Last scan ' . e(human_date($share['last_scan_at'], true)) : 'No scans yet' ?></p></div>
      <div class="qr-only"><div class="qr-mini"><?= qr_svg($link, 'Your store QR code') ?></div>
        <a class="btn btn-sm btn-outline" download="<?= e(slugify($store)) ?>-qr.svg" href="data:image/svg+xml;base64,<?= base64_encode(qr_svg($link, 'Your store QR code')) ?>">Download QR only</a></div>
    </div>
  </div>
</section>

<section class="promo-sec">
  <h2>From DDLX <?= help('Banners, posters and guides that DDLX shares with sellers. What you see depends on your seller level and what the team has chosen for you.') ?></h2>
  <?php if (!$assets): ?><p class="muted">Nothing shared with you yet. When DDLX adds promo material for your store, it appears here and you get a notification.</p>
  <?php else: ?><ul class="asset-list"><?php foreach ($assets as $a): ?><li class="asset">
    <div class="asset-thumb"><?php if (str_starts_with($a['mime'], 'image/')): ?><img src="/files/promo?id=<?= e($a['id']) ?>&amp;preview=1" alt="" loading="lazy"><?php else: ?><span><?= e(promo_kind_label($a['mime'])) ?></span><?php endif; ?></div>
    <div class="asset-info"><strong><?= e($a['title']) ?></strong><?php if ($a['description'] !== ''): ?><p class="muted small"><?= e($a['description']) ?></p><?php endif; ?>
      <p class="muted small" style="margin:2px 0 0"><?= e(promo_kind_label($a['mime'])) ?> · <?= e(number_format($a['bytes'] / 1048576, $a['bytes'] > 1048576 ? 1 : 2)) ?> MB · <?= e(human_date($a['created_at'])) ?></p></div>
    <a class="btn btn-sm" href="/files/promo?id=<?= e($a['id']) ?>">Download</a></li><?php endforeach; ?></ul><?php endif; ?>
</section>

<section class="promo-sec">
  <h2>Invite other sellers <?= help('Know a brand that should sell on DDLX? Share your invite link. When they apply with it, DDLX knows you brought them in.') ?></h2>
  <p class="muted" style="margin-top:0">Share this link with brands and makers you know. They create a seller account and apply as usual.</p>
  <div class="copyrow" style="max-width:520px"><input readonly value="<?= e($invite) ?>" aria-label="Your invite link" id="invite-link"><button class="btn btn-sm btn-outline" type="button" data-copy="#invite-link">Copy</button></div>
  <p style="margin:12px 0"><a class="btn btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text=<?= e(rawurlencode('I sell on DDLX and it is working well for me. You can apply to sell there too: ' . $invite)) ?>">Share on WhatsApp</a></p>
  <p class="muted small"><?= (int) $ref['applied'] ?> invited seller<?= $ref['applied'] === 1 ? '' : 's' ?> applied · <?= (int) $ref['verified'] ?> verified</p>
</section>
