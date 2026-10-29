<?php
$user = current_user();
$flashMsg = take_flash();
$path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$current = $path . (isset($_GET['tab']) ? '?tab=' . $_GET['tab'] : '');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> — Vendor Hub</title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=Outfit:wght@500;600;700&display=swap">
<link rel="stylesheet" href="/assets/app.css?v=2">
</head>
<body data-csrf="<?= e(csrf_token()) ?>" <?= $user ? 'data-live="1"' : '' ?> <?= !empty($live_refresh) ? 'data-refresh="1"' : '' ?>>
<header class="site-header">
  <div class="wrap bar">
    <a class="brand" href="<?= e($user ? home_path($user) : '/') ?>" aria-label="Vendor Hub home"><span class="brand-name">Vendor Hub<i>/</i></span><span class="brand-sub">for DDLX</span></a>
    <?php if ($user): ?>
    <nav class="nav" aria-label="Main">
      <?php foreach (nav_items($user) as [$label, $href, $badge]):
          $hp = parse_url($href, PHP_URL_PATH); $active = $hp === $path || ($hp === '/seller' && $path === '/seller') || ($hp === '/admin' && $path === '/admin'); ?>
        <a href="<?= e($href) ?>" class="<?= $active ? 'active' : '' ?>"><?= e($label) ?><span class="badge-count<?= $badge > 0 ? '' : ' hidden' ?>" data-badge="<?= e($label) ?>"><?= (int) $badge ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="nav-end">
      <a class="bell" href="/notifications" aria-label="Notifications"><?= icon('bell') ?><span class="badge-count<?= ($user['pending']['notifications'] ?? 0) > 0 ? '' : ' hidden' ?>" data-badge="Notifications"><?= (int) ($user['pending']['notifications'] ?? 0) ?></span></a>
      <details class="account"><summary><span class="who"><?= e($user['email']) ?></span><span class="avatar"><?= e(strtoupper(substr($user['email'], 0, 1))) ?></span></summary>
        <div class="menu">
          <p class="muted"><?= e($user['email']) ?><br><strong style="color:var(--ink)"><?= $user['isAdmin'] ? 'Admin' : ($user['canReview'] || $user['canOrders'] ? 'Staff' : e(STAGE_LABEL[$user['stage']] ?? '')) ?></strong></p>
          <?php foreach (nav_items($user) as [$label, $href]): ?><a class="m-only" href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?>
          <a href="/notifications">Notifications</a>
          <form method="post" action="/logout"><?= csrf_field() ?><button type="submit">Sign out</button></form>
        </div></details>
    </div>
    <?php else: ?>
    <div class="nav-end"><a class="btn btn-ghost btn-sm" href="/auth">Sign in</a><a class="btn btn-sm" href="/apply">Start application</a></div>
    <?php endif; ?>
  </div>
</header>
<?php if ($flashMsg): ?><div class="wrap"><p class="flash <?= e($flashMsg['k'] === 'error' ? 'flash-error' : 'flash-ok') ?>" role="status"><?= e($flashMsg['m']) ?></p></div><?php endif; ?>
<main class="<?= !empty($full) ? '' : 'wrap main' ?>" id="main"><?= $content ?></main>
<?php if (!empty($footer)): ?><footer class="site-footer"><div class="wrap"><strong>DDLX</strong><a href="https://ddlxapparel.com">Marketplace <?= icon('arrow-up-right') ?></a></div></footer><?php endif; ?>
<div class="toasts" id="toasts" aria-live="polite"></div>
<dialog id="preview-dialog"><div class="dlg-head"><strong data-dlg-title>Document</strong><button class="btn btn-sm btn-outline" type="button" data-dlg-close>Close</button></div><div class="dlg-body"></div></dialog>
<script src="/assets/app.js?v=2" defer></script>
<?php foreach (($scripts ?? []) as $src): ?><script src="/assets/<?= e($src) ?>?v=1" defer></script>
<?php endforeach; ?>
</body>
</html>
