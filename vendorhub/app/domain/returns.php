<?php
declare(strict_types=1);

// Returns and disputes. The admin opens a case on an order item when a customer complains, the seller answers
// (accept or dispute), and the admin decides: refund (earnings reversed) or reject.

const RETURN_REASONS = ['Damaged or defective', 'Wrong item sent', 'Not as described', 'Never arrived', 'Arrived late', 'Changed mind', 'Other'];
// Refunded on photo evidence without sending the item back; every other reason is return first, then refund.
const RETURN_REFUND_ONLY = ['Damaged or defective', 'Never arrived'];
const RETURN_SELLER_HOURS = 48;
const RETURN_LABEL = ['open' => 'Waiting for seller', 'seller_accepted' => 'Seller agrees', 'seller_disputed' => 'Seller disputes', 'refunded' => 'Refunded', 'rejected' => 'Rejected'];
const RETURN_KINDS = ['open' => 'warn', 'seller_accepted' => 'warn', 'seller_disputed' => 'bad', 'refunded' => 'ok', 'rejected' => ''];
const RETURN_FINAL = ['refunded', 'rejected'];

function return_base_sql(): string
{
    return 'SELECT c.*, i.name AS item_name, i.sku, i.quantity, i.line_total, i.net, i.status AS item_status, o.number, o.marketplace_order_id, o.currency, o.customer_name, u.email AS seller_email
        FROM return_cases c JOIN order_items i ON i.id = c.order_item_id JOIN orders o ON o.id = i.order_id JOIN users u ON u.id = c.seller_id';
}

function seller_returns(string $sellerId): array
{
    return db()->all(return_base_sql() . ' WHERE c.seller_id = ? ORDER BY c.created_at DESC LIMIT 200', [$sellerId]);
}

function admin_returns(string $filter = 'open'): array
{
    $where = $filter === 'done' ? "c.status IN ('refunded', 'rejected')" : ($filter === 'all' ? '1 = 1' : "c.status NOT IN ('refunded', 'rejected')");
    return db()->all(return_base_sql() . ' WHERE ' . $where . ' ORDER BY c.created_at DESC LIMIT 300');
}

function open_return(array $admin, string $itemId, string $reason, string $details): ?string
{
    $db = db();
    $reason = trim($reason);
    $details = trim($details);
    if (!in_array($reason, RETURN_REASONS, true)) return 'Choose a reason.';
    if (mb_strlen($details) > 2000) return 'The details are too long.';
    $it = $db->one('SELECT i.id, i.seller_id, i.name, i.status, o.number, o.marketplace_order_id FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.id = ?', [$itemId]);
    if (!$it) return 'Order item not found.';
    if ($it['status'] === 'refunded') return 'This item was already refunded.';
    if ($db->val("SELECT 1 FROM return_cases WHERE order_item_id = ? AND status NOT IN ('refunded', 'rejected')", [$itemId])) return 'There is already an open case for this item.';
    $id = uuid();
    $now = now_db();
    $db->insert('return_cases', ['id' => $id, 'order_item_id' => $itemId, 'seller_id' => $it['seller_id'], 'opened_by' => $admin['id'], 'reason' => $reason, 'details' => $details, 'status' => 'open',
        'seller_response' => '', 'resolution_note' => '', 'kind' => in_array($reason, RETURN_REFUND_ONLY, true) ? 'refund_only' : 'return', 'created_at' => $now, 'updated_at' => $now]);
    return_freeze($id, $itemId);
    $label = $it['number'] ?: $it['marketplace_order_id'];
    notify($it['seller_id'], 'return', 'Return case on order ' . $label . ': ' . $it['name'], $reason . ($details !== '' ? "\n" . $details : '') . "\n\nPlease accept it or explain why you disagree.", '/seller?tab=returns');
    if (marketplace_connected()) marketplace_call('/order-status', 'POST', ['order_id' => $it['marketplace_order_id'], 'status' => 'note', 'note' => 'Return case opened for ' . $it['name'] . ': ' . $reason]);
    return null;
}

function seller_respond_return(string $sellerId, string $caseId, string $action, string $response): ?string
{
    $db = db();
    $response = trim($response);
    if (!in_array($action, ['accept', 'dispute'], true)) return 'Unknown action.';
    if ($action === 'dispute' && $response === '') return 'Tell us why you disagree.';
    if (mb_strlen($response) > 2000) return 'The reply is too long.';
    $to = $action === 'accept' ? 'seller_accepted' : 'seller_disputed';
    $n = $db->exec("UPDATE return_cases SET status = ?, seller_response = ?, updated_at = ? WHERE id = ? AND seller_id = ? AND status = 'open'", [$to, $response, now_db(), $caseId, $sellerId]);
    if (!$n) return 'This case is no longer waiting for you.';
    announce('orders', 'return', 'A seller answered a return case');
    return null;
}

// Refund: the item's earnings are reversed (stock returns if asked). Reject: the case is closed and nothing changes.
function resolve_return(array $admin, string $caseId, string $decision, bool $restock, string $note): ?string
{
    $db = db();
    $note = trim($note);
    if (!in_array($decision, ['refund', 'reject'], true)) return 'Unknown decision.';
    if ($decision === 'reject' && $note === '') return 'Add a note so the seller knows why it was rejected.';
    $c = $db->one(return_base_sql() . ' WHERE c.id = ?', [$caseId]);
    if (!$c) return 'Case not found.';
    if (in_array($c['status'], RETURN_FINAL, true)) return 'This case is already closed.';
    $label = $c['number'] ?: $c['marketplace_order_id'];
    $sellers = [];
    $err = $db->tx(function () use ($db, $c, $decision, $restock, $note, $admin, $label, &$sellers) {
        if ($decision === 'refund') {
            $line = $db->one("SELECT id, seller_id, product_id, variation_id, quantity, net FROM order_items WHERE id = ? AND status = 'paid'" . $db->lock(), [$c['order_item_id']]);
            if (!$line) return 'This item was already refunded.';
            refund_lines($db, $c['currency'], [$line], $label, $restock, $sellers);
        }
        $db->exec('UPDATE return_cases SET status = ?, resolution_note = ?, resolved_by = ?, resolved_at = ?, updated_at = ? WHERE id = ?', [$decision === 'refund' ? 'refunded' : 'rejected', $note, $admin['id'], now_db(), now_db(), $c['id']]);
        if ($decision === 'refund') log_item_status($c['order_item_id'], 'refunded', 'admin', $note);
        else return_unfreeze($c['id'], $c['order_item_id']);
        return null;
    });
    if ($err) return $err;
    notify($c['seller_id'], 'return', $decision === 'refund' ? 'Refunded: ' . $c['item_name'] . ' (order ' . $label . ')' : 'Return rejected: ' . $c['item_name'] . ' (order ' . $label . ')',
        $decision === 'refund' ? 'Your earnings for this item were taken back.' . ($note !== '' ? "\n" . $note : '') : $note, '/seller?tab=returns');
    if (marketplace_connected()) marketplace_call('/order-status', 'POST', ['order_id' => $c['marketplace_order_id'], 'status' => 'note', 'note' => 'Return case ' . ($decision === 'refund' ? 'approved (refund the customer for ' . $c['item_name'] . ' in WooCommerce)' : 'rejected') . ($note !== '' ? ': ' . $note : '')]);
    return null;
}

// ---------------------------------------------------------------- earnings freeze

// While a case is open the item's earnings cannot be withdrawn, even if the hold period has ended.
function return_freeze(string $caseId, string $itemId): void
{
    $db = db();
    $sale = $db->one("SELECT id, available_at FROM wallet_entries WHERE order_item_id = ? AND kind = 'sale'", [$itemId]);
    if (!$sale || $sale['available_at'] >= NOT_DELIVERED_YET) return;
    $db->exec('UPDATE return_cases SET frozen_from = ? WHERE id = ?', [$sale['available_at'], $caseId]);
    $db->exec('UPDATE wallet_entries SET available_at = ? WHERE id = ?', [NOT_DELIVERED_YET, $sale['id']]);
}

// A rejected case gives the money back its original date (or makes it available now if that date has passed).
function return_unfreeze(string $caseId, string $itemId): void
{
    $db = db();
    $from = $db->val('SELECT frozen_from FROM return_cases WHERE id = ?', [$caseId]);
    if (!$from) return;
    $when = $from > now_db() ? $from : now_db();
    $db->exec("UPDATE wallet_entries SET available_at = ? WHERE order_item_id = ? AND kind = 'sale' AND available_at >= ?", [$when, $itemId, NOT_DELIVERED_YET]);
}

// ---------------------------------------------------------------- customer requests (from the marketplace)

function return_deadline(string $itemId): ?string
{
    $db = db();
    $at = $db->val("SELECT MIN(created_at) FROM order_item_log WHERE order_item_id = ? AND status IN ('delivered', 'completed')", [$itemId]);
    if (!$at) $at = $db->val('SELECT o.placed_at FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.id = ?', [$itemId]);
    return $at ? db_plus('+' . hold_days() . ' days', (string) $at) : null;
}

// A customer asks for a return from their account on the marketplace. Returns ['ok' => true, 'case_id' => ...] or ['error' => message, 'code' => http status].
function customer_open_return(array $r): array
{
    $db = db();
    $reason = trim((string) ($r['reason'] ?? ''));
    $details = trim((string) ($r['details'] ?? ''));
    if (!in_array($reason, RETURN_REASONS, true)) return ['error' => 'Choose a reason.', 'code' => 422];
    if (mb_strlen($details) > 2000) return ['error' => 'The details are too long.', 'code' => 422];
    $photos = [];
    foreach (array_slice((array) ($r['photos'] ?? []), 0, 4) as $u) if (is_string($u) && preg_match('#^https://#i', $u) && strlen($u) <= 500) $photos[] = $u;
    $refundOnly = in_array($reason, RETURN_REFUND_ONLY, true);
    if ($reason === 'Damaged or defective' && !$photos) return ['error' => 'Please add at least one photo of the damage.', 'code' => 422];
    $q = "SELECT i.id, i.seller_id, i.name, i.status, i.fulfilment, o.number, o.marketplace_order_id FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.marketplace_order_id = ? AND i.product_id = ?";
    $args = [(string) ($r['order_id'] ?? ''), (string) ($r['product_id'] ?? '')];
    if (!empty($r['variation_id'])) { $q .= ' AND i.variation_id = ?'; $args[] = (string) $r['variation_id']; }
    $it = $db->one($q . " AND i.status = 'paid' ORDER BY i.created_at LIMIT 1", $args);
    if (!$it) return ['error' => 'We could not find that item on the order, or it was already refunded.', 'code' => 404];
    if (FULFILMENT_RANK[$it['fulfilment']] < FULFILMENT_RANK['delivered'] && $reason !== 'Never arrived') return ['error' => 'You can ask for a return once the order is delivered.', 'code' => 422];
    $deadline = return_deadline($it['id']);
    if ($deadline && $deadline < now_db()) return ['error' => 'The ' . hold_days() . '-day return period for this item has ended.', 'code' => 422];
    if ($db->val("SELECT 1 FROM return_cases WHERE order_item_id = ? AND status NOT IN ('refunded', 'rejected')", [$it['id']])) return ['error' => 'There is already an open request for this item.', 'code' => 422];
    $id = uuid();
    $now = now_db();
    $db->tx(function () use ($db, $id, $it, $reason, $details, $photos, $refundOnly, $now) {
        $db->insert('return_cases', ['id' => $id, 'order_item_id' => $it['id'], 'seller_id' => $it['seller_id'], 'opened_by' => '00000000-0000-4000-8000-000000000000', 'reason' => $reason, 'details' => $details,
            'status' => 'open', 'seller_response' => '', 'resolution_note' => '', 'source' => 'customer', 'kind' => $refundOnly ? 'refund_only' : 'return', 'respond_by' => db_plus('+' . RETURN_SELLER_HOURS . ' hours'),
            'photos' => $photos ? json_encode($photos) : null, 'created_at' => $now, 'updated_at' => $now]);
        return_freeze($id, $it['id']);
    });
    $label = $it['number'] ?: $it['marketplace_order_id'];
    notify($it['seller_id'], 'return', 'Return request on order ' . $label . ': ' . $it['name'], $reason . ($details !== '' ? "\n" . $details : '') . "\n\nPlease accept or dispute within " . RETURN_SELLER_HOURS . ' hours. No reply counts as accepting.', '/seller?tab=returns', true);
    announce('orders', 'return', 'A customer asked for a return');
    return ['ok' => true, 'case_id' => $id];
}

// What the customer sees for one marketplace order.
function customer_cases_for_order(string $marketplaceOrderId): array
{
    $out = [];
    foreach (db()->all('SELECT c.id, c.reason, c.status, c.kind, c.resolution_note, c.created_at, c.respond_by, i.product_id, i.variation_id FROM return_cases c JOIN order_items i ON i.id = c.order_item_id JOIN orders o ON o.id = i.order_id WHERE o.marketplace_order_id = ? ORDER BY c.created_at DESC', [$marketplaceOrderId]) as $r) {
        $label = ['open' => 'Waiting for the seller', 'seller_accepted' => $r['kind'] === 'refund_only' ? 'Approved. Refund being processed' : 'Approved. Please return the item', 'seller_disputed' => 'Under review by DDLX', 'refunded' => 'Refunded', 'rejected' => 'Request declined'][$r['status']] ?? $r['status'];
        $out[] = ['id' => $r['id'], 'product_id' => $r['product_id'], 'variation_id' => $r['variation_id'], 'reason' => $r['reason'], 'status' => $r['status'], 'label' => $label, 'note' => $r['resolution_note'], 'created_at' => iso($r['created_at'])];
    }
    return $out;
}

// No reply within the time limit counts as accepting. Checked hourly on whatever request comes first.
function returns_sweep_if_due(): void
{
    try {
        $last = (string) setting('returns_last_sweep', '');
        if ($last !== '' && strtotime($last . ' UTC') > time() - 3600) return;
        set_setting('returns_last_sweep', now_db());
        $db = db();
        foreach ($db->all("SELECT c.id, c.seller_id, i.name FROM return_cases c JOIN order_items i ON i.id = c.order_item_id WHERE c.status = 'open' AND c.source = 'customer' AND c.respond_by IS NOT NULL AND c.respond_by < ?", [now_db()]) as $c) {
            $n = $db->exec("UPDATE return_cases SET status = 'seller_accepted', seller_response = ?, updated_at = ? WHERE id = ? AND status = 'open'", ['No reply within ' . RETURN_SELLER_HOURS . ' hours, so the request was accepted.', now_db(), $c['id']]);
            if ($n) { notify($c['seller_id'], 'return', 'Return accepted automatically: ' . $c['name'], 'You did not reply within ' . RETURN_SELLER_HOURS . ' hours.', '/seller?tab=returns'); announce('orders', 'return', 'A return request was accepted because the seller did not reply'); }
        }
    } catch (Throwable $e) {
        log_error('returns sweep: ' . $e->getMessage());
    }
}
