<?php
declare(strict_types=1);

// Upgrades an existing install when a new release changes the database. Runs once, automatically, on the first
// request after the new files are uploaded. Every step checks before it changes anything, so it is safe to repeat.
// A fresh install already has the latest tables (schema.sql) and is stamped with SCHEMA_VERSION.

const SCHEMA_VERSION = 1;

function column_exists(Db $db, string $table, string $column): bool
{
    try {
        $db->val("SELECT $column FROM $table LIMIT 1");
        return true;
    } catch (Throwable) {
        return false;
    }
}

function table_exists(Db $db, string $table): bool
{
    try {
        $db->val("SELECT 1 FROM $table LIMIT 1");
        return true;
    } catch (Throwable) {
        return false;
    }
}

function migration_steps(): array
{
    $engine = fn (Db $db) => $db->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    return [
        1 => function (Db $db) use ($engine) {
            if (!column_exists($db, 'categories', 'commission_percent')) $db->exec('ALTER TABLE categories ADD COLUMN commission_percent DECIMAL(5,2) NULL');
            if (!table_exists($db, 'audit_log')) {
                $db->exec('CREATE TABLE audit_log (id CHAR(36) NOT NULL PRIMARY KEY, actor_id CHAR(36) NULL, actor_email VARCHAR(190) NOT NULL DEFAULT \'\', action VARCHAR(60) NOT NULL, summary VARCHAR(400) NOT NULL DEFAULT \'\', target_id CHAR(36) NULL, created_at DATETIME(3) NOT NULL)' . $engine($db));
                $db->exec('CREATE INDEX audit_created_idx ON audit_log (created_at)');
            }
        },
    ];
}

function migrate_if_needed(): void
{
    $db = db();
    $have = (int) setting('schema_version', 0);
    if ($have >= SCHEMA_VERSION) return;
    try {
        foreach (migration_steps() as $v => $step) {
            if ($v <= $have) continue;
            $step($db);
        }
        set_setting('schema_version', (string) SCHEMA_VERSION);
    } catch (Throwable $e) {
        log_error('Upgrade failed: ' . $e->getMessage());
    }
}
