<?php
declare(strict_types=1);

// Returns and disputes. The admin opens a case on an order item when a customer complains, the seller answers
// (accept or dispute), and the admin decides: refund (earnings reversed) or reject.

const RETURN_REASONS = ['Damaged or defective', 'Wrong item sent', 'Not as described', 'Arrived late', 'Changed mind', 'Other'];
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
        'seller_response' => '', 'resolution_note' => '', 'created_at' => $now, 'updated_at' => $now]);
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
        return null;
    });
    if ($err) return $err;
    notify($c['seller_id'], 'return', $decision === 'refund' ? 'Refunded: ' . $c['item_name'] . ' (order ' . $label . ')' : 'Return rejected: ' . $c['item_name'] . ' (order ' . $label . ')',
        $decision === 'refund' ? 'Your earnings for this item were taken back.' . ($note !== '' ? "\n" . $note : '') : $note, '/seller?tab=returns');
    if (marketplace_connected()) marketplace_call('/order-status', 'POST', ['order_id' => $c['marketplace_order_id'], 'status' => 'note', 'note' => 'Return case ' . ($decision === 'refund' ? 'approved (refund the customer for ' . $c['item_name'] . ' in WooCommerce)' : 'rejected') . ($note !== '' ? ': ' . $note : '')]);
    return null;
}
