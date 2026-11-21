<?php
/** @var array $user @var array $store @var array $rating @var array $level */
$name = inbox_store_name($user['id']) ?: 'Your store';
$curLv = $level['override_level'] ?: $level['auto_level'];
$pendRet = (int) ($user['pending']['returns'] ?? 0);
$row = function (string $ic, string $title, string $sub, string $href, string $extra = '') {
    return '<a class="srow" href="' . e($href) . '"><span class="sr-ic">' . icon($ic) . '</span><span class="grow"><strong>' . e($title) . '</strong>' . ($sub !== '' ? '<span class="muted small">' . e($sub) . '</span>' : '') . '</span>' . $extra . '<span class="sr-go">' . icon('chev') . '</span></a>';
};
?>
<section class="prof">
  <div class="prof-cover"><?php if ($store['banner']): ?><img src="/files/store?p=<?= e(rawurlencode($store['banner'])) ?>" alt=""><?php endif; ?></div>
  <div class="prof-logo"><?php if ($store['logo']): ?><img src="/files/store?p=<?= e(rawurlencode($store['logo'])) ?>" alt=""><?php else: ?><span><?= e(strtoupper(substr($name, 0, 1))) ?></span><?php endif; ?></div>
  <h1 class="prof-name"><?= e($name) ?><?= verified_tick($user['id']) ?></h1>
  <?php if ($store['tagline'] !== ''): ?><p class="prof-tag muted"><?= e($store['tagline']) ?></p><?php endif; ?>
  <p class="prof-chips"><span class="chip-s"><?= e(LEVEL_LABEL[$curLv]) ?> level</span><span class="chip-s"><?= $rating['average'] !== null ? '★ ' . e(number_format((float) $rating['average'], 1)) . ' (' . (int) $rating['count'] . ')' : 'No ratings yet' ?></span><?php if ($store['vacation']): ?><span class="chip-s warn">On vacation</span><?php endif; ?></p>
</section>

<p class="grp">Business</p>
<div class="sgroup">
  <?= $row('store', 'Store profile', 'Logo, banner, about and policies', '/seller?tab=store') ?>
  <?= $row('wallet', 'Wallet and bank accounts', 'Withdraw, add a bank account, history', '/seller?tab=payouts') ?>
  <?= $row('undo', 'Returns', 'Cases that need your reply', '/seller?tab=returns', $pendRet > 0 ? '<span class="badge-count">' . $pendRet . '</span>' : '') ?>
  <?= $row('tag', 'Sales and discount codes', 'Schedule sales ahead of time', '/seller?tab=promotions') ?>
  <?= $row('image', 'Promo kit', 'Share your store anywhere', '/seller?tab=promo') ?>
  <?= $row('file-check', 'Application and verification', 'What you submitted to DDLX', '/verification') ?>
</div>

<p class="grp">Account</p>
<div class="sgroup">
  <?= $row('mail', 'Messages', 'Talk to the DDLX team', '/messages', ($user['pending']['messages'] ?? 0) > 0 ? '<span class="badge-count">' . (int) $user['pending']['messages'] . '</span>' : '') ?>
  <?= $row('bell', 'Notifications', 'Orders, payouts and reviews', '/notifications', ($user['pending']['notifications'] ?? 0) > 0 ? '<span class="badge-count">' . (int) $user['pending']['notifications'] . '</span>' : '') ?>
  <?= $row('shield', 'Security', 'Password and two-step sign-in', '/account/security') ?>
  <button type="button" class="srow" data-lock-setup><span class="sr-ic"><?= icon('lock') ?></span><span class="grow"><strong>App lock</strong><span class="muted small" data-lock-state>Ask for a PIN when the app opens</span></span><span class="sr-go"><?= icon('chev') ?></span></button>
</div>

<p class="grp">Help</p>
<div class="sgroup">
  <?= $row('spark', 'Ask DDLX', 'Quick answers about selling', '/assistant') ?>
  <details class="sdet"><summary class="srow"><span class="sr-ic"><?= icon('doc') ?></span><span class="grow"><strong>Policies</strong><span class="muted small">Seller agreement, returns, prohibited items</span></span><span class="sr-go"><?= icon('chev') ?></span></summary>
    <div class="sdet-in"><?php foreach (LEGAL_DOCS as $k => $d) echo '<a class="srow sub" href="/' . e($k) . '"><span class="grow"><strong>' . e($d[0]) . '</strong></span><span class="sr-go">' . icon('chev') . '</span></a>'; ?></div></details>
  <button type="button" class="srow hidden" data-install><span class="sr-ic"><?= icon('download') ?></span><span class="grow"><strong>Install the app</strong><span class="muted small">Add Vendor Hub to your home screen</span></span></button>
</div>

<form method="post" action="/logout" class="sgroup logout"><?= csrf_field() ?><button type="submit" class="srow danger"><span class="sr-ic"><?= icon('logout') ?></span><span class="grow"><strong>Log out</strong></span><span class="muted small">Vendor Hub</span></button></form>
