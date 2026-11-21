<?php
/** @var array $user */
$msg = (int) ($user['pending']['messages'] ?? 0);
$ret = (int) ($user['pending']['returns'] ?? 0);
$row = function (string $ic, string $title, string $sub, string $href, string $extra = '') {
    return '<a class="srow" href="' . e($href) . '"><span class="sr-ic">' . icon($ic) . '</span><span class="grow"><strong>' . e($title) . '</strong>' . ($sub !== '' ? '<span class="muted small">' . e($sub) . '</span>' : '') . '</span>' . $extra . '<span class="sr-go">' . icon('chev') . '</span></a>';
};
?>
<h1 class="app-h1">Support</h1>
<a class="sup-card" href="/messages"><span class="sr-ic"><?= icon('mail') ?></span><span class="grow"><strong>Message support</strong></span><?= $msg > 0 ? '<span class="badge-count">' . $msg . '</span>' : '' ?><span class="sr-go"><?= icon('chev') ?></span></a>
<div class="sgroup">
  <?= $row('undo', 'Returns', '', '/seller?tab=returns', $ret > 0 ? '<span class="badge-count">' . $ret . '</span>' : '') ?>
  <?= $row('spark', 'Ask DDLX', '', '/assistant') ?>
</div>
<p class="grp">Handbook and policies</p>
<div class="sgroup">
  <?php foreach (LEGAL_DOCS as $k => $d) echo $row('doc', $d[0], '', '/' . $k); ?>
</div>
