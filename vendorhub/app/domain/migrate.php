<?php
declare(strict_types=1);

// Upgrades an existing install when a new release changes the database. Runs once, automatically, on the first
// request after the new files are uploaded. Every step checks before it changes anything, so it is safe to repeat.
// A fresh install already has the latest tables (schema.sql) and is stamped with SCHEMA_VERSION.

const SCHEMA_VERSION = 4;

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
        2 => function (Db $db) use ($engine) {
            foreach (['totp_secret VARCHAR(64) NULL', 'totp_enabled TINYINT NOT NULL DEFAULT 0', 'totp_last_step BIGINT NOT NULL DEFAULT 0', 'totp_recovery TEXT NULL'] as $def) {
                if (!column_exists($db, 'users', explode(' ', $def)[0])) $db->exec('ALTER TABLE users ADD COLUMN ' . $def);
            }
            if (!table_exists($db, 'login_challenges')) {
                $db->exec('CREATE TABLE login_challenges (token_hash CHAR(64) NOT NULL PRIMARY KEY, user_id CHAR(36) NOT NULL, tries INT NOT NULL DEFAULT 0, expires_at DATETIME(3) NOT NULL, created_at DATETIME(3) NOT NULL)' . $engine($db));
            }
        },
        3 => function (Db $db) use ($engine) {
            if (!table_exists($db, 'broadcasts')) $db->exec('CREATE TABLE broadcasts (id CHAR(36) NOT NULL PRIMARY KEY, subject VARCHAR(160) NOT NULL, body TEXT NOT NULL, audience VARCHAR(20) NOT NULL, recipients INT NOT NULL DEFAULT 0, allow_reply TINYINT NOT NULL DEFAULT 1, sent_by CHAR(36) NULL, created_at DATETIME(3) NOT NULL)' . $engine($db));
            if (!table_exists($db, 'threads')) {
                $db->exec('CREATE TABLE threads (id CHAR(36) NOT NULL PRIMARY KEY, seller_id CHAR(36) NOT NULL, subject VARCHAR(160) NOT NULL, broadcast_id CHAR(36) NULL, allow_reply TINYINT NOT NULL DEFAULT 1, status VARCHAR(10) NOT NULL DEFAULT \'open\', team_unread TINYINT NOT NULL DEFAULT 0, seller_unread TINYINT NOT NULL DEFAULT 0, last_message_at DATETIME(3) NOT NULL, created_at DATETIME(3) NOT NULL)' . $engine($db));
                $db->exec('CREATE INDEX threads_seller_idx ON threads (seller_id, last_message_at)');
                $db->exec('CREATE INDEX threads_last_idx ON threads (last_message_at)');
            }
            if (!table_exists($db, 'messages')) {
                $db->exec('CREATE TABLE messages (id CHAR(36) NOT NULL PRIMARY KEY, thread_id CHAR(36) NOT NULL, sender_id CHAR(36) NULL, side VARCHAR(10) NOT NULL, body TEXT NOT NULL, created_at DATETIME(3) NOT NULL)' . $engine($db));
                $db->exec('CREATE INDEX messages_thread_idx ON messages (thread_id, created_at)');
            }
        },
        4 => function (Db $db) use ($engine) {
            if (!table_exists($db, 'assistant_messages')) {
                $db->exec('CREATE TABLE assistant_messages (id CHAR(36) NOT NULL PRIMARY KEY, user_id CHAR(36) NOT NULL, role VARCHAR(10) NOT NULL, body TEXT NOT NULL, created_at DATETIME(3) NOT NULL)' . $engine($db));
                $db->exec('CREATE INDEX assistant_user_idx ON assistant_messages (user_id, created_at)');
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
