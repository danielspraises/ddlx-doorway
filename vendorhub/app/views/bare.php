<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> — Vendor Hub</title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=Outfit:wght@500;600;700&display=swap">
<link rel="stylesheet" href="/assets/app.css?v=7">
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
  </div></div>
</div>
<div class="toasts" id="toasts" aria-live="polite"></div>
<script src="/assets/app.js?v=2" defer></script>
</body>
</html>
