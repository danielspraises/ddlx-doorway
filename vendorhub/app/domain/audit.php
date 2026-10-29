<?php
declare(strict_types=1);

// Who did what in the admin area. Written after a successful action; a logging problem never blocks the action.

function audit(?array $actor, string $action, string $summary, ?string $targetId = null): void
{
    try {
        db()->insert('audit_log', ['id' => uuid(), 'actor_id' => $actor['id'] ?? null, 'actor_email' => (string) ($actor['email'] ?? ''), 'action' => $action,
            'summary' => mb_substr($summary, 0, 400), 'target_id' => $targetId, 'created_at' => now_db()]);
    } catch (Throwable $e) {
        log_error('Audit failed: ' . $e->getMessage());
    }
}

function audit_list(int $limit = 200, string $action = ''): array
{
    return db()->all('SELECT * FROM audit_log' . ($action !== '' ? ' WHERE action = ?' : '') . ' ORDER BY created_at DESC LIMIT ' . (int) $limit, $action !== '' ? [$action] : []);
}

const AUDIT_LABEL = ['application' => 'Application', 'verification' => 'Verification', 'product' => 'Product review', 'payout' => 'Payout', 'order' => 'Order', 'return' => 'Return', 'category' => 'Category', 'staff' => 'Staff', 'settings' => 'Settings'];
