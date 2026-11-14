<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> — Vendor Hub</title>
<meta name="robots" content="noindex">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0f1b2d">
<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Vendor Hub"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=Outfit:wght@500;600;700&display=swap">
<link rel="stylesheet" href="/assets/app.css?v=<?= (int) @filemtime(__DIR__ . "/../../public/assets/app.css") ?>">
</head>
<body>
<div class="auth-page">
  <aside class="auth-art" aria-hidden="true">
    <a class="brand" href="/"><span class="brand-name">Vendor Hub<i>/</i></span></a>
    <div class="scene"><?= tote_svg() ?></div>
    <h2>Your brand. <em>The next chapter.</em></h2>
    <p class="cap">MADE FOR INDEPENDENT COMMERCE</p>
  </aside>
  <div class="auth-panel"><div class="auth-box">
    <a class="brand" href="/"><span class="brand-name">Vendor Hub<i>/</i></span><span class="brand-sub">for DDLX</span></a>
    <?= $content ?>
    <p class="legal-links"><a href="/terms">Terms</a><a href="/privacy">Privacy</a><a href="/seller-terms">Seller Agreement</a><a href="/returns-policy">Returns</a></p>
  </div></div>
</div>
<div class="toasts" id="toasts" aria-live="polite"></div>
<script src="/assets/app.js?v=<?= (int) @filemtime(__DIR__ . "/../../public/assets/app.js") ?>" defer></script>
<script src="/assets/pwa.js" defer></script>
<script src="/assets/help.js?v=1" defer></script>
</body>
</html>
