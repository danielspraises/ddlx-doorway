<p class="eyebrow">Setup</p>
<?php if (!empty($done)): ?>
  <h1>Vendor Hub is ready</h1>
  <p>The database is set up and the admin account <strong><?= e($admin) ?></strong> exists.</p>
  <div class="notice"><h3>Save these two keys now</h3>
    <p>They connect your WooCommerce store to Vendor Hub. You can see them again any time under Admin → Settings.</p></div>
  <dl class="dl">
    <dt>Vendor Hub address</dt><dd><?= e($appUrl) ?></dd>
    <dt>Marketplace API key</dt><dd><code><?= e($apiKey) ?></code></dd>
    <dt>Webhook secret</dt><dd><code><?= e($secret) ?></code></dd>
  </dl>
  <p><a class="btn" href="/auth">Sign in as admin</a></p>
<?php else: ?>
  <h1>Install Vendor Hub</h1>
  <p class="muted">Create an empty MySQL database and user in cPanel (MySQL® Databases) first, then fill this in. It takes a few seconds.</p>
  <?php if (!empty($error)): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="/install">
    <h2>Database</h2>
    <div class="field"><label for="db_host">Host</label><input id="db_host" name="db_host" value="<?= e($v['db_host'] ?? 'localhost') ?>" required></div>
    <div class="field"><label for="db_name">Database name</label><input id="db_name" name="db_name" value="<?= e($v['db_name'] ?? '') ?>" required><span class="hint">cPanel adds a prefix, for example <code>cpuser_vendorhub</code>.</span></div>
    <div class="field"><label for="db_user">Database user</label><input id="db_user" name="db_user" value="<?= e($v['db_user'] ?? '') ?>" required></div>
    <div class="field"><label for="db_pass">Database password</label><input id="db_pass" name="db_pass" type="password" autocomplete="off"></div>
    <h2>Administrator</h2>
    <div class="field"><label for="admin_email">Admin email</label><input id="admin_email" name="admin_email" type="email" value="<?= e($v['admin_email'] ?? 'admin@ddlxapparel.com') ?>" required></div>
    <div class="field"><label for="admin_password">Admin password</label><input id="admin_password" name="admin_password" type="password" minlength="8" autocomplete="new-password" required></div>
    <div class="field"><label for="admin_confirm">Confirm password</label><input id="admin_confirm" name="admin_confirm" type="password" autocomplete="new-password" required></div>
    <h2>Addresses</h2>
    <div class="field"><label for="app_url">This site's address</label><input id="app_url" name="app_url" type="url" value="<?= e($v['app_url'] ?? $guess) ?>" required></div>
    <div class="field"><label for="marketplace_url">Marketplace address</label><input id="marketplace_url" name="marketplace_url" type="url" value="<?= e($v['marketplace_url'] ?? 'https://ddlxapparel.com') ?>"><span class="hint">Where WooCommerce runs. Use https://ddlx.shop once you go live. Used to avoid duplicate SKUs.</span></div>
    <button class="btn" type="submit">Install</button>
  </form>
<?php endif; ?>
