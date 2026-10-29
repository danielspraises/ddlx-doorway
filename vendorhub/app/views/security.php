<p class="eyebrow">Account</p>
<h1>Account security</h1>
<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($codes): ?>
  <div class="card" style="max-width:520px">
    <h2 style="margin-top:0">Save your backup codes</h2>
    <p class="muted">Each code works once if you lose your phone. They are shown only now. Keep them somewhere safe.</p>
    <ul class="list" style="font-family:ui-monospace,monospace;columns:2"><?php foreach ($codes as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<h2>Two-step sign-in <?= pill($on ? 'OK' : 'Check', [], ['OK' => 'ok', 'Check' => 'warn']) ?></h2>
<?php if (!$on): ?>
  <p class="muted" style="max-width:560px">Adds a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password) to signing in, so a stolen password is not enough.</p>
  <ol style="max-width:560px">
    <li>In your authenticator app choose <em>Add account</em> then <em>Enter a setup key</em>.</li>
    <li>Type this key: <code style="font-size:1.05em;letter-spacing:.06em;word-break:break-all"><?= e(trim(chunk_split((string) $secret, 4, ' '))) ?></code><br><span class="muted small">Account name: <?= e($user['email']) ?>. Type of key: time based. On a phone you can also <a href="<?= e($uri) ?>">tap here to add it</a>.</span></li>
    <li>Enter the code the app shows:</li>
  </ol>
  <form method="post" action="/account/security" class="row" data-keep style="max-width:420px"><?= csrf_field() ?><input type="hidden" name="action" value="enable">
    <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="123456" required style="max-width:160px"><button class="btn" type="submit">Turn on</button></form>
<?php else: ?>
  <p class="muted">On. You have <?= (int) $left ?> backup code<?= $left === 1 ? '' : 's' ?> left.</p>
  <form method="post" action="/account/security" class="row" data-keep><?= csrf_field() ?><input type="hidden" name="action" value="codes">
    <input type="password" name="password" placeholder="Your password" autocomplete="current-password" required style="max-width:220px"><button class="btn btn-outline" type="submit">Make new backup codes</button></form>
  <form method="post" action="/account/security" class="row" data-keep style="margin-top:12px" data-confirm="Turn off two-step sign-in?"><?= csrf_field() ?><input type="hidden" name="action" value="disable">
    <input type="password" name="password" placeholder="Your password" autocomplete="current-password" required style="max-width:220px"><button class="btn btn-ghost" type="submit">Turn off</button></form>
<?php endif; ?>
