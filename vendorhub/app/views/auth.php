<?php /** @var string $mode */ ?>
<p class="eyebrow">DDLX Vendor Hub</p>
<?php if ($mode === 'reset'): ?>
  <h1>Choose a new password</h1>
  <form method="post" action="/reset"><?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <?php if (!empty($error)): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="field"><label for="password">New password</label><div class="pw"><input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required><button type="button" data-pw="password">Show</button></div></div>
    <div class="field"><label for="confirm">Confirm password</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" required></div>
    <button class="btn" type="submit">Save password</button>
  </form>
<?php elseif ($mode === 'twofa'): ?>
  <h1>Enter your code</h1>
  <p class="muted">Open your authenticator app and type the 6-digit code for DDLX Vendor Hub. Lost your phone? Use one of your backup codes instead.</p>
  <form method="post" action="/login/2fa"><?= csrf_field() ?>
    <input type="hidden" name="challenge" value="<?= e($challenge) ?>">
    <?php if (!empty($error)): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="field"><label for="code">Code</label><input id="code" name="code" inputmode="text" autocomplete="one-time-code" autofocus required></div>
    <button class="btn" type="submit">Continue</button> <a class="btn btn-ghost" href="/auth">Cancel</a>
  </form>
<?php elseif ($mode === 'forgot'): ?>
  <h1>Reset your password</h1>
  <?php if (!empty($sent)): ?>
    <p>If an account exists for that email, a reset link is on its way. It works for one hour.</p>
    <p><a href="/auth">Back to sign in</a></p>
  <?php else: ?>
  <form method="post" action="/forgot"><?= csrf_field() ?>
    <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" value="<?= e($email ?? '') ?>" required></div>
    <button class="btn" type="submit">Send reset link</button> <a class="btn btn-ghost" href="/auth">Cancel</a>
  </form>
  <?php endif; ?>
<?php else: $register = $mode === 'register'; ?>
  <h1><?= $register ? 'Create your seller account' : 'Sign in' ?></h1>
  <p class="muted"><?= $register ? 'Sell on the DDLX marketplace. You will apply, get verified, then add products.' : 'Welcome back.' ?></p>
  <form method="post" action="<?= $register ? '/register' : '/login' ?>"><?= csrf_field() ?>
    <?php if (!empty($error)): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" value="<?= e($email ?? '') ?>" required></div>
    <div class="field"><label for="password">Password</label><div class="pw"><input id="password" name="password" type="password" <?= $register ? 'minlength="8" autocomplete="new-password"' : 'autocomplete="current-password"' ?> required><button type="button" data-pw="password">Show</button></div><?php if ($register): ?><span class="hint">At least 8 characters.</span><?php endif; ?></div>
    <?php if ($register): ?><div class="field"><label for="confirm">Confirm password</label><div class="pw"><input id="confirm" name="confirm" type="password" autocomplete="new-password" required><button type="button" data-pw="confirm">Show</button></div></div><?php endif; ?>
    <button class="btn" type="submit"><?= $register ? 'Create account' : 'Sign in' ?></button>
  </form>
  <p class="small muted" style="margin-top:18px">
    <?php if ($register): ?>Already have an account? <a href="/auth">Sign in</a>
    <?php else: ?>New here? <a href="/auth?mode=register">Create an account</a> · <a href="/auth?mode=forgot">Forgot password?</a><?php endif; ?>
  </p>
<?php endif; ?>
