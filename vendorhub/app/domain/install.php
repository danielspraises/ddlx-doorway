<?php
declare(strict_types=1);

// Creates the tables and starting data. Used by the web installer and by the self-tests.

function run_schema(Db $db): void
{
    $sql = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    if ($db->driver === 'sqlite') {
        // The self-tests run on SQLite; production runs on MySQL/MariaDB.
        $sql = preg_replace('/\)\s*ENGINE=[^;]*;/', ');', $sql);
        $sql = preg_replace('/BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/,\s*CONSTRAINT [a-z_]+ FOREIGN KEY \([a-z_]+\) REFERENCES [a-z_]+\([a-z_]+\)( ON DELETE (CASCADE|RESTRICT|SET NULL))?/', '', $sql);
    }
    foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $db->exec($statement);
        }
    }
}

// Builds the category tree from database/categories.txt ("Fashion > Men > Clothing > Shirts" per line).
function seed_categories(Db $db): int
{
    $lines = file(APP_ROOT . '/database/categories.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $ids = [];
    $position = [];
    $count = 0;
    foreach ($lines as $line) {
        $parent = null;
        $key = '';
        foreach (array_map('trim', explode('>', $line)) as $part) {
            $key .= '/' . strtolower($part);
            if (!isset($ids[$key])) {
                $id = uuid();
                $pk = $parent ?? 'root';
                $position[$pk] = ($position[$pk] ?? 0) + 1;
                $db->insert('categories', ['id' => $id, 'parent_id' => $parent, 'name' => $part, 'position' => $position[$pk], 'active' => 1, 'created_at' => now_db()]);
                $ids[$key] = $id;
                $count++;
            }
            $parent = $ids[$key];
        }
    }
    return $count;
}

function create_admin(Db $db, string $email, string $password): string
{
    $id = uuid();
    $db->insert('users', ['id' => $id, 'email' => strtolower($email), 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => now_db()]);
    $db->insert('user_roles', ['user_id' => $id, 'role' => 'admin']);
    return $id;
}

function default_settings(string $appUrl, string $marketUrl): array
{
    return [
        'app_url' => $appUrl,
        'marketplace_url' => $marketUrl,
        'marketplace_api_key' => bin2hex(random_bytes(24)),
        'marketplace_webhook_secret' => bin2hex(random_bytes(24)),
        'commission_percent' => '10',
        'payout_hold_days' => '7',
        'min_withdrawal' => '1000',
        'withdrawal_fee' => '0',
    ];
}
