<?php
declare(strict_types=1);

route('GET', '/install', function () {
    render('install', ['title' => 'Install', 'guess' => base_url(), 'v' => []], 'bare');
});

route('POST', '/install', function () {
    $v = [
        'db_host' => input('db_host'), 'db_name' => input('db_name'), 'db_user' => input('db_user'),
        'admin_email' => strtolower(input('admin_email')), 'app_url' => rtrim(input('app_url'), '/'), 'marketplace_url' => rtrim(input('marketplace_url'), '/'),
    ];
    $again = fn (string $m) => render('install', ['title' => 'Install', 'error' => $m, 'v' => $v, 'guess' => base_url()], 'bare');
    if (is_file(APP_ROOT . '/config/installed.lock')) abort(404);
    if (!filter_var($v['admin_email'], FILTER_VALIDATE_EMAIL)) { $again('Enter a valid admin email.'); return; }
    $pass = input_raw('admin_password');
    if ($pass !== input_raw('admin_confirm')) { $again('The two admin passwords do not match.'); return; }
    if ($e = password_ok($pass)) { $again($e); return; }
    if (!filter_var($v['app_url'], FILTER_VALIDATE_URL)) { $again('Enter this site\'s address, for example https://vendor.ddlx.shop'); return; }
    $dbConf = ['driver' => 'mysql', 'host' => $v['db_host'], 'name' => $v['db_name'], 'user' => $v['db_user'], 'pass' => input_raw('db_pass')];
    try {
        $db = new Db($dbConf);
    } catch (Throwable $e) {
        $again('Could not connect to the database. Check the host, name, user and password. (' . $e->getMessage() . ')');
        return;
    }
    if ($db->val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")) {
        $again('This database already has Vendor Hub tables. Use an empty database.');
        return;
    }
    try {
        run_schema($db);
        $db->tx(function (Db $db) use ($v, $pass) {
            seed_categories($db);
            create_admin($db, $v['admin_email'], $pass);
            foreach (default_settings($v['app_url'], $v['marketplace_url']) as $k => $val) {
                $db->insert('settings', ['k' => $k, 'v' => $val]);
            }
        });
    } catch (Throwable $e) {
        log_error('install failed: ' . $e->getMessage());
        $again('Setup stopped: ' . $e->getMessage());
        return;
    }
    $config = "<?php\nreturn " . var_export(['db' => $dbConf, 'admin_email' => $v['admin_email'], 'debug' => false], true) . ";\n";
    if (@file_put_contents(APP_ROOT . '/config/config.php', $config) === false) {
        $again('The database is ready, but the config folder is not writable. Make config/ writable (permissions 755 or 775) and run the installer again with a fresh database.');
        return;
    }
    @chmod(APP_ROOT . '/config/config.php', 0640);
    @file_put_contents(APP_ROOT . '/config/installed.lock', gmdate('c'));
    $GLOBALS['vh_config'] = ['db' => $dbConf];
    render('install', ['title' => 'Installed', 'done' => true, 'admin' => $v['admin_email'], 'appUrl' => $v['app_url'],
        'apiKey' => (string) $db->val("SELECT v FROM settings WHERE k = 'marketplace_api_key'"),
        'secret' => (string) $db->val("SELECT v FROM settings WHERE k = 'marketplace_webhook_secret'")], 'bare');
});
