<?php
declare(strict_types=1);

// A plain SQL copy of the database that can be restored in phpMyAdmin (cPanel → Import).
// Sessions, sign-in attempts and one-time challenges are left out. Uploaded images live in storage/ and need a
// separate download from cPanel File Manager.

const BACKUP_SKIP = ['sessions', 'login_attempts', 'login_challenges', 'password_resets', 'events'];

function backup_tables(): array
{
    $db = db();
    $rows = $db->driver === 'mysql' ? $db->all('SHOW TABLES') : $db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    $out = [];
    foreach ($rows as $r) $out[] = (string) array_values($r)[0];
    sort($out);
    return array_values(array_diff($out, BACKUP_SKIP));
}

// Calls $emit with successive chunks of SQL text.
function backup_stream(callable $emit): void
{
    $db = db();
    $emit("-- DDLX Vendor Hub backup\n-- Created " . now_db() . " UTC\n-- Restore: cPanel → phpMyAdmin → select the database → Import.\n\n");
    if ($db->driver !== 'mysql') $emit("PRAGMA foreign_keys = OFF;\n\n");
    if ($db->driver === 'mysql') $emit("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
    foreach (backup_tables() as $t) {
        if ($db->driver === 'mysql') {
            $create = $db->one("SHOW CREATE TABLE `$t`");
            $emit("DROP TABLE IF EXISTS `$t`;\n" . (string) ($create['Create Table'] ?? array_values($create ?? [''])[1] ?? '') . ";\n\n");
        }
        $offset = 0;
        $cols = null;
        while (true) {
            $rows = $db->all("SELECT * FROM `$t` LIMIT 500 OFFSET $offset");
            if (!$rows) break;
            $cols ??= '`' . implode('`, `', array_keys($rows[0])) . '`';
            $vals = [];
            foreach ($rows as $r) $vals[] = '(' . implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $db->pdo->quote((string) $v), $r)) . ')';
            $emit("INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n");
            $offset += 500;
            if (count($rows) < 500) break;
        }
        $emit("\n");
    }
    if ($db->driver === 'mysql') $emit("SET FOREIGN_KEY_CHECKS = 1;\n");
}
