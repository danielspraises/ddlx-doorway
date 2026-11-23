<?php
$user = current_user();
$flashMsg = take_flash();
$path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$current = $path . (isset($_GET['tab']) ? '?tab=' . $_GET['tab'] : '');
$mode = app_mode($user);
$app = $mode !== '';
$desk = $mode === 'desk';
$as = $app ? app_state($path, (string) ($_GET['tab'] ?? ''), $user) : ['active' => '', 'root' => true, 'back' => ''];
$tabsDef = $app ? app_tabs($user) : [];
$homeHref = $desk ? desk_home($user) : '/seller?tab=overview';
$myStore = $app ? ($desk ? (string) $user['email'] : (inbox_store_name($user['id']) ?: (!empty($user['storeOf']) ? 'New business' : (string) $user['email']))) : '';
$ownerOf = $user ? (string) ($user['storeOf'] ?? $user['id']) : '';
$myStores = $user && !$desk && empty($user['viewAs']) ? stores_of($ownerOf) : [];
$deskTag = $desk ? (!empty($user['isAdmin']) ? 'Admin' : 'Staff') : '';
$lockOn = $app && ($_COOKIE['vh_lock'] ?? '') === '1' && ($_COOKIE['vh_open'] ?? '') !== '1';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?> — Vendor Hub</title>
<meta name="robots" content="noindex">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="<?= $app && !($as['active'] === 'home' && $as['root']) ? '#f3f5f9' : '#0f1b2d' ?>">
<meta name="color-scheme" content="light">
<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Vendor Hub"><meta name="apple-mobile-web-app-status-bar-style" content="<?= $app ? 'default' : 'black-translucent' ?>">
<link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=Outfit:wght@500;600;700&display=swap">
<link rel="stylesheet" href="/assets/app.css?v=<?= (int) @filemtime(__DIR__ . "/../../public/assets/app.css") ?>">
</head>
<body class="<?= $app ? 'app' . ($as['root'] ? ' app-root' : ' app-sub') . ($as['active'] === 'home' && $as['root'] ? ' app-home' : '') . ($desk ? ' app-desk-mode' : '') : '' ?>" data-shell="<?= e($mode) ?>" data-csrf="<?= e(csrf_token()) ?>" <?= $app ? 'data-uid="' . e($user['id']) . '" data-who="' . e($myStore) . '"' : '' ?> <?= $user ? 'data-live="1"' : '' ?> <?= !empty($live_refresh) ? 'data-refresh="1"' : '' ?>>
<?php if (!$app): ?>
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
      <a class="bell" href="/assistant" aria-label="Ask DDLX"><?= icon('spark') ?></a>
      <a class="bell" href="/messages" aria-label="Messages"><?= icon('mail') ?><span class="badge-count<?= ($user['pending']['messages'] ?? 0) > 0 ? '' : ' hidden' ?>" data-badge="Messages"><?= (int) ($user['pending']['messages'] ?? 0) ?></span></a>
      <a class="bell" href="/notifications" aria-label="Notifications"><?= icon('bell') ?><span class="badge-count<?= ($user['pending']['notifications'] ?? 0) > 0 ? '' : ' hidden' ?>" data-badge="Notifications"><?= (int) ($user['pending']['notifications'] ?? 0) ?></span></a>
      <details class="account"><summary><span class="who"><?= e($user['email']) ?></span><span class="avatar"><?= e(strtoupper(substr($user['email'], 0, 1))) ?></span></summary>
        <div class="menu">
          <p class="muted"><?= e($user['email']) ?><br><strong style="color:var(--ink)"><?= $user['isAdmin'] ? 'Admin' : ($user['canReview'] || $user['canOrders'] || $user['canMessages'] ? 'Staff' : e(STAGE_LABEL[$user['stage']] ?? '')) ?></strong></p>
          <?php foreach (nav_items($user) as [$label, $href]): ?><a class="m-only" href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?>
          <a href="/notifications">Notifications</a>
          <?php if (!$desk && empty($user['viewAs'])): ?><a href="/stores">Your stores</a><?php endif; ?>
          <a href="/account/security">Account security</a>
          <button type="button" class="hidden" data-install>Install app</button>
          <form method="post" action="/logout"><?= csrf_field() ?><button type="submit">Sign out</button></form>
        </div></details>
    </div>
    <?php else: ?>
    <div class="nav-end"><button type="button" class="btn btn-outline btn-sm hidden" data-install>Install app</button><a class="btn btn-sm" href="/auth">Sign in</a></div>
    <?php endif; ?>
  </div>
</header>
<?php else: ?>
<div class="app-wrap">
<aside class="app-side" aria-label="Main">
  <a class="app-logo" href="<?= e($homeHref) ?>" aria-label="Vendor Hub home"><span class="brand-name">Vendor Hub<i>/</i></span></a>
  <nav class="app-nav">
    <?php foreach ($tabsDef as $k => [$label, $href, $ic]): $bk = $desk ? ['reviews' => 'Reviews', 'orders' => 'Orders'][$k] ?? '' : ($k === 'orders' ? 'Returns' : '');
        $bn = $desk ? ($k === 'reviews' ? (int) (($user['pending']['applications'] ?? 0) + ($user['pending']['verifications'] ?? 0) + ($user['pending']['products'] ?? 0)) : ($k === 'orders' ? (int) (($user['pending']['orders'] ?? 0) + ($user['pending']['returns'] ?? 0)) : 0)) : ($k === 'orders' ? (int) ($user['pending']['returns'] ?? 0) : 0);
        if ($href === ''): ?><button type="button" class="<?= $as['active'] === $k ? 'on' : '' ?>" data-sheet="more-sheet" aria-haspopup="dialog"><?= icon($ic) ?><span><?= e($label) ?></span></button><?php else: ?><a href="<?= e($href) ?>" class="<?= $as['active'] === $k ? 'on' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span><?php if ($bk !== ''): ?><span class="badge-count<?= $bn > 0 ? '' : ' hidden' ?>" data-badge="<?= e($bk) ?>"><?= $bn ?></span><?php endif; ?></a><?php endif; ?><?php endforeach; ?>
  </nav>
  <?php if (!$desk && app_desk_link($user) !== ''): ?><a class="app-desk" href="<?= e(app_desk_link($user)) ?>"><?= icon('cog') ?><span>Admin desk</span></a><?php endif; ?>
  <div class="app-me"><span class="avatar"><?= e(strtoupper(substr($myStore, 0, 1))) ?></span><span class="who"><?= e($myStore) ?></span>
    <form method="post" action="/logout"><?= csrf_field() ?><button type="submit" class="icon-btn" aria-label="Sign out" title="Sign out"><?= icon('logout') ?></button></form></div>
</aside>
<div class="app-col">
<header class="app-top">
  <?php if (!$as['root']): ?><a class="icon-btn app-back" href="<?= e($as['back']) ?>" data-back aria-label="Back"><?= icon('back') ?></a><strong class="app-ttl"><?= e($pageTitle) ?></strong>
  <?php elseif ($as['active'] === 'home' && !$desk): ?><button type="button" class="biz-chip" data-sheet="biz-sheet" aria-haspopup="dialog"><span class="avatar"><?= e(strtoupper(substr($myStore, 0, 1))) ?></span><span class="nm"><?= e($myStore) ?></span><?= icon('chev') ?></button>
  <?php else: ?><a class="app-brand" href="<?= e($homeHref) ?>"><span class="brand-name">Vendor Hub<i>/</i></span><?php if ($desk): ?><span class="desk-tag"><?= e($deskTag) ?></span><?php endif; ?></a><?php endif; ?>
  <div class="app-ico">
    <a class="icon-btn" href="/assistant" aria-label="Ask DDLX"><?= icon('spark') ?></a>
    <a class="icon-btn" href="/messages" aria-label="Messages"><?= icon('mail') ?><span class="badge-count<?= ($user['pending']['messages'] ?? 0) > 0 ? '' : ' hidden' ?>" data-badge="Messages"><?= (int) ($user['pending']['messages'] ?? 0) ?></span></a>
    <a class="icon-btn" href="/notifications" aria-label="Notifications"><?= icon('bell') ?><span class="badge-count<?= ($user['pending']['notifications'] ?? 0) > 0 ? '' : ' hidden' ?>" data-badge="Notifications"><?= (int) ($user['pending']['notifications'] ?? 0) ?></span></a>
  </div>
</header>
<?php endif; ?>
<?php if (!empty($user['viewAs'])): ?>
<div class="viewas" role="status"><div class="wrap"><span><?= icon('eye') ?> Viewing <strong><?= e(inbox_store_name($user['id']) ?: $user['email']) ?></strong> as the seller sees it. Read-only.</span>
  <form method="post" action="/view-as/exit"><?= csrf_field() ?><button class="btn btn-sm" type="submit">Exit view</button></form></div></div>
<?php endif; ?>
<?php if ($flashMsg && $app): ?><div id="flash-src" hidden data-m="<?= e($flashMsg['m']) ?>"></div>
<?php elseif ($flashMsg): ?><div class="wrap"><p class="flash <?= e($flashMsg['k'] === 'error' ? 'flash-error' : 'flash-ok') ?>" role="status"><?= e($flashMsg['m']) ?></p></div><?php endif; ?>
<?php if (!$app && !empty($user) && empty($user['twofa']) && ($user['isAdmin'] || $user['canReview'] || $user['canOrders'] || $user['canMessages']) && ($_SERVER['REQUEST_URI'] ?? '') !== '/account/security'): ?><div class="wrap"><p class="flash flash-error" role="status">Your account can approve products and money. Protect it with <a href="/account/security">two-step sign-in</a>.</p></div><?php endif; ?>
<main class="<?= !empty($full) ? '' : ($app ? 'app-main' : 'wrap main') ?>" id="main"><?= $content ?></main>
<?php if ($app): ?>
</div>
</div>
<?php if ($desk): ?>
<dialog class="sheet" id="more-sheet" aria-label="More">
  <div class="sheet-h"><strong>More</strong><button type="button" class="icon-btn" data-sheet-close aria-label="Close"><?= icon('close') ?></button></div>
  <div class="sheet-b">
    <div class="biz-row on"><span class="avatar"><?= e(strtoupper(substr($myStore, 0, 1))) ?></span><span class="grow"><strong><?= e($myStore) ?></strong><span class="muted small"><?= e($deskTag) ?></span></span></div>
    <?php foreach (desk_more($user) as [$ml, $mh, $mi, $mb]): $mn = $mb !== '' ? (int) ($user['pending'][$mb] ?? 0) : 0; ?><a class="srow" href="<?= e($mh) ?>"><span class="sr-ic"><?= icon($mi) ?></span><span class="grow"><strong><?= e($ml) ?></strong></span><?php if ($mn > 0): ?><span class="badge-count"><?= $mn ?></span><?php endif; ?><span class="sr-go"><?= icon('chev') ?></span></a><?php endforeach; ?>
    <?php if (($user['stage'] ?? '') === 'verified'): ?><a class="srow" href="/seller?tab=overview" data-nonav><span class="sr-ic"><?= icon('store') ?></span><span class="grow"><strong>Seller view</strong></span><span class="sr-go"><?= icon('chev') ?></span></a><?php endif; ?>
    <a class="srow" href="/account/security"><span class="sr-ic"><?= icon('shield') ?></span><span class="grow"><strong>Security</strong></span><span class="sr-go"><?= icon('chev') ?></span></a>
    <button type="button" class="srow" data-lock-setup><span class="sr-ic"><?= icon('lock') ?></span><span class="grow"><strong>App lock</strong><span class="muted small" data-lock-state></span></span><span class="sr-go"><?= icon('chev') ?></span></button>
    <form method="post" action="/logout"><?= csrf_field() ?><button type="submit" class="srow danger"><span class="sr-ic"><?= icon('logout') ?></span><span class="grow"><strong>Log out</strong></span></button></form>
  </div>
</dialog>
<?php else: ?>
<dialog class="sheet" id="biz-sheet" aria-label="Your business">
  <div class="sheet-h"><strong>Your business</strong><button type="button" class="icon-btn" data-sheet-close aria-label="Close"><?= icon('close') ?></button></div>
  <div class="sheet-b">
    <?php foreach ($myStores as $st): $isCur = $st['id'] === $user['id']; ?>
    <form method="post" action="/stores/switch" data-keep><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($st['id']) ?>"><button type="submit" class="biz-row<?= $isCur ? ' on' : '' ?>"><span class="avatar"><?= e(strtoupper(substr($st['name'], 0, 1))) ?></span><span class="grow"><strong><?= e($st['name']) ?></strong></span><span class="chip-s<?= $st['stage'] === 'verified' ? ' ok' : '' ?>"><?= $st['stage'] === 'verified' ? 'Verified' : 'Pending' ?></span></button></form>
    <?php endforeach; ?>
    <?php if (empty($user['viewAs']) && count($myStores) < MAX_STORES): ?><form method="post" action="/stores/new" data-keep><?= csrf_field() ?><button type="submit" class="biz-row"><span class="avatar"><?= icon('plus') ?></span><span class="grow"><strong>Add another business</strong></span></button></form><?php endif; ?>
    <?php if (app_desk_link($user) !== ''): ?><a class="srow" href="<?= e(app_desk_link($user)) ?>"><span class="sr-ic"><?= icon('cog') ?></span><span class="grow"><strong>Admin desk</strong></span><span class="sr-go"><?= icon('chev') ?></span></a><?php endif; ?>
    <a class="srow" href="/seller?tab=store"><span class="sr-ic"><?= icon('store') ?></span><span class="grow"><strong>Store profile</strong></span><span class="sr-go"><?= icon('chev') ?></span></a>
    <a class="srow" href="/account/security"><span class="sr-ic"><?= icon('shield') ?></span><span class="grow"><strong>Security</strong></span><span class="sr-go"><?= icon('chev') ?></span></a>
    <button type="button" class="srow" data-lock-setup><span class="sr-ic"><?= icon('lock') ?></span><span class="grow"><strong>App lock</strong><span class="muted small" data-lock-state></span></span><span class="sr-go"><?= icon('chev') ?></span></button>
    <form method="post" action="/logout"><?= csrf_field() ?><button type="submit" class="srow danger"><span class="sr-ic"><?= icon('logout') ?></span><span class="grow"><strong>Log out</strong></span></button></form>
  </div>
</dialog>
<?php endif; ?>
<dialog class="sheet" id="lock-sheet" aria-label="App lock">
  <div class="sheet-h"><strong>App lock</strong><button type="button" class="icon-btn" data-sheet-close aria-label="Close"><?= icon('close') ?></button></div>
  <div class="sheet-b">
        <label class="lk-f"><span>New PIN</span><input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="off" data-lk-pin></label>
    <label class="lk-f"><span>Repeat PIN</span><input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="off" data-lk-pin2></label>
    <label class="lk-bio hidden" data-lk-biorow><input type="checkbox" data-lk-bio> <span>Also unlock with fingerprint or face</span></label>
    <p class="lk-err hidden" data-lk-err></p>
    <button type="button" class="btn" data-lk-save>Turn on</button>
    <button type="button" class="btn btn-outline hidden" data-lk-off>Turn off</button>
  </div>
</dialog>
<div class="lock<?= $lockOn ? ' on' : '' ?>" id="lock" role="dialog" aria-modal="true" aria-label="Unlock" <?= $lockOn ? '' : 'hidden' ?>>
  <span class="lock-logo"><?= e(strtoupper(substr($myStore, 0, 1))) ?></span><strong class="lock-t">Enter your PIN</strong><span class="muted lock-s"><?= e($myStore) ?></span>
  <div class="lock-dots" data-lock-dots><i></i><i></i><i></i><i></i></div>
  <p class="lk-err" data-lock-err></p>
  <div class="lock-pad" data-lock-pad></div>
  <button type="button" class="lock-alt hidden" data-lock-bio>Use fingerprint or face</button>
  <form method="post" action="/logout" class="lock-out"><?= csrf_field() ?><button type="submit">Forgot PIN? Sign out</button></form>
</div>
<nav class="app-tabs" aria-label="Main">
  <?php foreach ($tabsDef as $k => [$label, $href, $ic]): $bk = $desk ? ['reviews' => 'Reviews', 'orders' => 'Orders'][$k] ?? '' : ($k === 'orders' ? 'Returns' : '');
      $bn = $desk ? ($k === 'reviews' ? (int) (($user['pending']['applications'] ?? 0) + ($user['pending']['verifications'] ?? 0) + ($user['pending']['products'] ?? 0)) : ($k === 'orders' ? (int) (($user['pending']['orders'] ?? 0) + ($user['pending']['returns'] ?? 0)) : 0)) : ($k === 'orders' ? (int) ($user['pending']['returns'] ?? 0) : 0);
      $inner = '<span class="ti">' . icon($ic) . ($bk !== '' ? '<span class="badge-count' . ($bn > 0 ? '' : ' hidden') . '" data-badge="' . e($bk) . '">' . $bn . '</span>' : '') . '</span><span class="tl">' . e($label) . '</span>';
      if ($href === ''): ?><button type="button" class="<?= $as['active'] === $k ? 'on' : '' ?>" data-sheet="more-sheet" aria-haspopup="dialog"><?= $inner ?></button><?php else: ?><a href="<?= e($href) ?>" class="<?= $as['active'] === $k ? 'on' : '' ?>"<?= $as['active'] === $k ? ' aria-current="page"' : '' ?>><?= $inner ?></a><?php endif; ?><?php endforeach; ?>
</nav>
<?php else: ?>
<footer class="site-footer"><div class="wrap"><strong>DDLX</strong><nav class="foot-links" aria-label="Legal"><?php foreach (LEGAL_DOCS as $k => $d): ?><a href="/<?= e($k) ?>"><?= e($d[0]) ?></a><?php endforeach; ?></nav><a href="https://ddlx.shop" target="_blank" rel="noopener">Marketplace <?= icon('arrow-up-right') ?></a></div></footer>
<?php endif; ?>
<div class="toasts" id="toasts" aria-live="polite"></div>
<dialog id="preview-dialog"><div class="dlg-head"><strong data-dlg-title>Document</strong><button class="btn btn-sm btn-outline" type="button" data-dlg-close>Close</button></div><div class="dlg-body"></div></dialog>
<script src="/assets/app.js?v=<?= (int) @filemtime(__DIR__ . "/../../public/assets/app.js") ?>" defer></script>
<script src="/assets/pwa.js" defer></script>
<script src="/assets/help.js?v=1" defer></script>
<?php if ($app): ?><script src="/assets/appfeel.js?v=<?= (int) @filemtime(__DIR__ . "/../../public/assets/appfeel.js") ?>" defer></script><?php endif; ?>
<?php if ($user): ?><script src="/assets/native.js?v=1" defer></script><?php endif; ?>
<?php foreach (($scripts ?? []) as $src): ?><script src="/assets/<?= e($src) ?>?v=<?= (int) @filemtime(__DIR__ . "/../../public/assets/" . basename($src)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
