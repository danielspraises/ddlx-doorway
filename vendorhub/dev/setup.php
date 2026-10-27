<?php
declare(strict_types=1);

// Local test setup. Creates a throwaway SQLite database, a demo admin and the default settings.
// Usage:  php dev/setup.php [--reset] [--port=8000]
// It never touches MySQL and writes only config/config.php and storage/dev.db (both git-ignored).

$root = dirname(__DIR__);
$opts = getopt('', ['reset', 'port::']);
$port = (int) ($opts['port'] ?? 8000);
$cfgFile = $root . '/config/config.php';
$db = $root . '/storage/dev.db';

if (is_file($cfgFile) && !isset($opts['reset'])) {
    fwrite(STDERR, "config/config.php already exists. Use --reset to wipe the local test data and start over.\n");
    exit(1);
}
foreach ([$db, $root . '/config/installed.lock'] as $f) if (is_file($f)) unlink($f);
foreach (['pdo_sqlite', 'gd', 'mbstring', 'fileinfo'] as $ext) {
    if (!extension_loaded($ext)) { fwrite(STDERR, "PHP extension '$ext' is not enabled. Open php.ini and remove the semicolon before extension=$ext\n"); exit(1); }
}
if (!is_dir(dirname($db))) mkdir(dirname($db), 0775, true);

file_put_contents($cfgFile, "<?php\nreturn " . var_export([
    'db' => ['driver' => 'sqlite', 'path' => $db],
    'debug' => true,
    'admin_email' => 'admin@ddlxapparel.com',
], true) . ";\n");
file_put_contents($root . '/config/installed.lock', gmdate('c'));

define('VH_TESTING', true); // mail goes nowhere locally instead of failing
require $root . '/app/bootstrap.php';
$GLOBALS['vh_config'] = require $cfgFile;
run_schema(db());
db()->tx(function ($d) use ($port) {
    seed_categories($d);
    create_admin($d, 'admin@ddlxapparel.com', 'password123');
    foreach (default_settings('http://127.0.0.1:' . $port, '') as $k => $v) $d->insert('settings', ['k' => $k, 'v' => $v]);
});
echo "Ready.\n  Admin login: admin@ddlxapparel.com / password123\n  Start the site: php -S 127.0.0.1:$port -t public\n  Then open http://127.0.0.1:$port\n";
