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
<link rel="stylesheet" href="/assets/app.css?v=1">
</head>
<body data-csrf="<?= e(csrf_token()) ?>" <?= $user ? 'data-live="1"' : '' ?> <?= !empty($live_refresh) ? 'data-refresh="1"' : '' ?>>
<header class="site-header">
  <div class="wrap bar">
    <a class="brand" href="<?= e($user ? home_path($user) : '/') ?>"><span class="brand-mark">V</span> Vendor Hub</a>
    <?php if ($user): ?>
    <button class="menu-toggle" type="button" aria-label="Menu" aria-expanded="false" data-menu>☰</button>
    <nav class="nav" data-nav aria-label="Main">
      <?php foreach (nav_items($user) as [$label, $href, $badge]):
          $active = $href === $current || (!str_contains($href, '?') && $href === $path); ?>
        <a href="<?= e($href) ?>" class="<?= $active ? 'active' : '' ?>"><?= e($label) ?><span class="badge-count<?= $badge > 0 ? '' : ' hidden' ?>" data-badge="<?= e($label) ?>"><?= (int) $badge ?></span></a>
      <?php endforeach; ?>
      <span class="nav-user"><?= e($user['email']) ?>
        <small><?= $user['isAdmin'] ? 'Admin' : ($user['canReview'] || $user['canOrders'] ? 'Staff' : e(STAGE_LABEL[$user['stage']] ?? '')) ?></small></span>
      <form method="post" action="/logout"><?= csrf_field() ?><button class="link-btn" type="submit">Sign out</button></form>
    </nav>
    <?php endif; ?>
  </div>
</header>
<?php if ($flashMsg): ?><div class="wrap"><p class="flash <?= e($flashMsg['k'] === 'error' ? 'flash-error' : 'flash-ok') ?>" role="status"><?= e($flashMsg['m']) ?></p></div><?php endif; ?>
<main class="wrap main" id="main"><?= $content ?></main>
<div class="toasts" id="toasts" aria-live="polite"></div>
<dialog id="preview-dialog"><div class="dlg-head"><strong data-dlg-title>Document</strong><button class="btn btn-sm btn-outline" type="button" data-dlg-close>Close</button></div><div class="dlg-body"></div></dialog>
<script src="/assets/app.js?v=1" defer></script>
<?php foreach (($scripts ?? []) as $src): ?><script src="/assets/<?= e($src) ?>?v=1" defer></script>
<?php endforeach; ?>
</body>
</html>
