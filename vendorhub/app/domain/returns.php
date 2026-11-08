<?php
declare(strict_types=1);

// Returns and disputes. The admin opens a case on an order item when a customer complains, the seller answers
// (accept or dispute), and the admin decides: refund (earnings reversed) or reject.

const RETURN_REASONS = ['Damaged or defective', 'Wrong item sent', 'Not as described', 'Never arrived', 'Arrived late', 'Changed mind', 'Other'];
// Refunded on photo evidence without sending the item back; every other reason is return first, then refund.
const RETURN_REFUND_ONLY = ['Damaged or defective', 'Never arrived'];
const RETURN_SELLER_HOURS = 48;
const RETURN_LABEL = ['open' => 'Waiting for seller', 'seller_accepted' => 'Seller agrees', 'return_shipped' => 'Item on its way back', 'received' => 'Item received', 'seller_disputed' => 'Seller disputes', 'refunded' => 'Refunded', 'rejected' => 'Rejected'];
const RETURN_KINDS = ['open' => 'warn', 'seller_accepted' => 'warn', 'return_shipped' => 'warn', 'received' => 'ok', 'seller_disputed' => 'bad', 'refunded' => 'ok', 'rejected' => ''];
const RETURN_SHIP_DAYS = 7;     // the customer has this long to send the item back after the seller agrees
const RETURN_RECEIVE_DAYS = 5;  // the seller has this long to confirm it arrived; after that it counts as received
const RETURN_SYSTEM_ID = '00000000-0000-4000-8000-000000000001';
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
    if ($action === 'accept') return_after_accept($caseId);
    else return_tell_customer($caseId, 'The seller disagrees with your return request. DDLX will review it and decide. You do not need to do anything.');
    return null;
}

// Refund: the item's earnings are reversed (stock returns if asked). Reject: the case is closed and nothing changes.
function resolve_return(array $admin, string $caseId, string $decision, bool $restock, string $note): ?string
{
    $db = db();
    $note = trim($note);
    if (!in_array($decision, ['refund', 'reject', 'approve'], true)) return 'Unknown decision.';
    if ($decision === 'reject' && $note === '') return 'Add a note so the seller knows why it was rejected.';
    $c = $db->one(return_base_sql() . ' WHERE c.id = ?', [$caseId]);
    if (!$c) return 'Case not found.';
    if (in_array($c['status'], RETURN_FINAL, true)) return 'This case is already closed.';
    $label = $c['number'] ?: $c['marketplace_order_id'];
    if ($decision === 'approve') {
        if ($c['kind'] !== 'return' || $c['source'] !== 'customer' || !in_array($c['status'], ['open', 'seller_disputed'], true)) return 'This case cannot be approved for a return.';
        $db->exec("UPDATE return_cases SET status = 'seller_accepted', resolution_note = ?, updated_at = ? WHERE id = ?", [$note, now_db(), $c['id']]);
        notify($c['seller_id'], 'return', 'Return approved by DDLX: ' . $c['item_name'] . ' (order ' . $label . ')', 'The customer will send the item back to DDLX. Your earnings stay on hold meanwhile.' . ($note !== '' ? "\n" . $note : ''), '/seller?tab=returns');
        return_after_accept($c['id']);
        return null;
    }
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
    if ($c['source'] === 'customer') return_tell_customer($c['id'], $decision === 'refund' ? 'Your return for ' . $c['item_name'] . ' was approved and your refund is being sent to your original payment method. It can take a few days to show on your statement.' : 'Your return request for ' . $c['item_name'] . ' was declined.' . ($note !== '' ? ' ' . $note : ''));
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
    foreach (db()->all('SELECT c.id, c.reason, c.status, c.kind, c.resolution_note, c.created_at, c.respond_by, c.ship_by, c.return_carrier, c.return_tracking, i.product_id, i.variation_id FROM return_cases c JOIN order_items i ON i.id = c.order_item_id JOIN orders o ON o.id = i.order_id WHERE o.marketplace_order_id = ? ORDER BY c.created_at DESC', [$marketplaceOrderId]) as $r) {
        $send = $r['status'] === 'seller_accepted' && $r['kind'] === 'return';
        $label = ['open' => 'Waiting for the seller', 'seller_accepted' => $send ? 'Approved. Please send the item back' : 'Approved. Your refund is being processed', 'return_shipped' => 'Item on its way back to the seller', 'received' => 'Item received. Your refund is being processed',
            'seller_disputed' => 'Under review by DDLX', 'refunded' => 'Refunded', 'rejected' => 'Request declined'][$r['status']] ?? $r['status'];
        $out[] = ['id' => $r['id'], 'product_id' => $r['product_id'], 'variation_id' => $r['variation_id'], 'reason' => $r['reason'], 'status' => $r['status'], 'label' => $label, 'note' => $r['resolution_note'], 'created_at' => iso($r['created_at']),
            'can_ship' => $send, 'ship_by' => $send ? iso($r['ship_by']) : null, 'return_address' => $send ? (string) setting('return_address', '') : '', 'tracking' => trim($r['return_carrier'] . ' ' . $r['return_tracking'])];
    }
    return $out;
}

// The customer tells us they sent the item back.
function customer_ship_return(array $r): array
{
    $db = db();
    $carrier = trim((string) ($r['carrier'] ?? ''));
    $tracking = trim((string) ($r['tracking'] ?? ''));
    if ($carrier === '' || $tracking === '') return ['error' => 'Enter the courier and the tracking number.', 'code' => 422];
    $c = $db->one("SELECT c.id, c.seller_id, c.status, c.kind, i.name FROM return_cases c JOIN order_items i ON i.id = c.order_item_id JOIN orders o ON o.id = i.order_id WHERE c.id = ? AND o.marketplace_order_id = ? AND c.source = 'customer'", [(string) ($r['case_id'] ?? ''), (string) ($r['order_id'] ?? '')]);
    if (!$c) return ['error' => 'We could not find that request.', 'code' => 404];
    $n = $db->exec("UPDATE return_cases SET status = 'return_shipped', return_carrier = ?, return_tracking = ?, shipped_at = ?, updated_at = ? WHERE id = ? AND status = 'seller_accepted' AND kind = 'return'", [str_limit($carrier, 80), str_limit($tracking, 100), now_db(), now_db(), $c['id']]);
    if (!$n) return ['error' => 'This request is not waiting for you to send the item.', 'code' => 422];
    notify($c['seller_id'], 'return', 'A customer sent back: ' . $c['name'], trim($carrier . ' ' . $tracking) . "\n\nConfirm when it arrives. If you do not confirm within " . RETURN_RECEIVE_DAYS . ' days it counts as received and the customer is refunded.', '/seller?tab=returns');
    return ['ok' => true];
}

// The seller says the returned item arrived (refund follows) or that something is wrong (DDLX decides).
function seller_return_arrived(string $sellerId, string $caseId, string $action, string $note): ?string
{
    $db = db();
    $note = trim($note);
    if (!in_array($action, ['received', 'problem'], true)) return 'Unknown action.';
    if ($action === 'problem' && $note === '') return 'Tell us what is wrong with the item.';
    $to = $action === 'received' ? 'received' : 'seller_disputed';
    $n = $db->exec("UPDATE return_cases SET status = ?, seller_response = CASE WHEN ? <> '' THEN ? ELSE seller_response END, updated_at = ? WHERE id = ? AND seller_id = ? AND status = 'return_shipped'", [$to, $note, str_limit($note, 2000), now_db(), $caseId, $sellerId]);
    if (!$n) return 'This case is no longer waiting for you.';
    if ($action === 'received') return_auto_refund($caseId, 'Item returned and received by the seller', true);
    else { announce('orders', 'return', 'A seller reported a problem with a returned item'); return_tell_customer($caseId, 'The seller reported a problem with the item you sent back. DDLX will review it and decide. You do not need to do anything.'); }
    return null;
}

// Tells the customer: becomes a note on their order that WooCommerce also emails to them.
function return_tell_customer(string $caseId, string $text): void
{
    if (!marketplace_connected()) return;
    $mid = db()->val('SELECT o.marketplace_order_id FROM return_cases c JOIN order_items i ON i.id = c.order_item_id JOIN orders o ON o.id = i.order_id WHERE c.id = ? AND c.source = \'customer\'', [$caseId]);
    if ($mid) marketplace_call('/order-status', 'POST', ['order_id' => $mid, 'status' => 'note', 'note' => $text, 'customer' => true]);
}

// Seller agreed (or did not reply). A refund-without-return is paid straight away; otherwise the customer has to send the item back.
function return_after_accept(string $caseId): void
{
    $db = db();
    $c = $db->one('SELECT id, kind, source FROM return_cases WHERE id = ?', [$caseId]);
    if (!$c || $c['source'] !== 'customer') return;
    if ($c['kind'] === 'refund_only') { return_auto_refund($caseId, 'Approved: refund without sending the item back', false); return; }
    $db->exec('UPDATE return_cases SET ship_by = ? WHERE id = ?', [db_plus('+' . RETURN_SHIP_DAYS . ' days'), $caseId]);
    $addr = trim((string) setting('return_address', ''));
    return_tell_customer($caseId, 'Your return request was approved. Please send the item back within ' . RETURN_SHIP_DAYS . ' days' . ($addr !== '' ? ' to: ' . $addr : '') . ', then add the courier and tracking number in your account under this order.');
}

// Approves the refund as the system: seller earnings are reversed and, when Paystack is on, the customer is paid back at once.
function return_auto_refund(string $caseId, string $note, bool $restock): void
{
    $actor = ['id' => RETURN_SYSTEM_ID, 'email' => 'system'];
    $err = resolve_return($actor, $caseId, 'refund', $restock, $note);
    if ($err) { log_error('Automatic refund for case ' . $caseId . ': ' . $err); return; }
    audit($actor, 'return', 'Approved a refund automatically (' . $note . ')', $caseId);
    if (!paystack_on()) { announce('admins', 'payout', 'A customer refund is waiting to be sent'); return; }
    $orderId = (string) db()->val('SELECT i.order_id FROM return_cases c JOIN order_items i ON i.id = c.order_item_id WHERE c.id = ?', [$caseId]);
    $r = db()->one("SELECT id, amount FROM customer_refunds WHERE order_id = ? AND status = 'to_pay' ORDER BY created_at DESC", [$orderId]);
    if ($r && ($e = paystack_send_refund($actor, $r['id'], (float) $r['amount']))) { log_error('Automatic Paystack refund: ' . $e); announce('admins', 'payout', 'A customer refund needs attention'); }
}

// Runs hourly on whatever request comes first: no reply counts as accepting, a return not sent back in time is closed,
// and an unconfirmed arrival counts as received so a seller cannot stall a refund.
function returns_sweep_if_due(): void
{
    try {
        $last = (string) setting('returns_last_sweep', '');
        if ($last !== '' && strtotime($last . ' UTC') > time() - 3600) return;
        set_setting('returns_last_sweep', now_db());
        $db = db();
        foreach ($db->all("SELECT c.id, c.seller_id, i.name FROM return_cases c JOIN order_items i ON i.id = c.order_item_id WHERE c.status = 'open' AND c.source = 'customer' AND c.respond_by IS NOT NULL AND c.respond_by < ?", [now_db()]) as $c) {
            $n = $db->exec("UPDATE return_cases SET status = 'seller_accepted', seller_response = ?, updated_at = ? WHERE id = ? AND status = 'open'", ['No reply within ' . RETURN_SELLER_HOURS . ' hours, so the request was accepted.', now_db(), $c['id']]);
            if ($n) { notify($c['seller_id'], 'return', 'Return accepted automatically: ' . $c['name'], 'You did not reply within ' . RETURN_SELLER_HOURS . ' hours.', '/seller?tab=returns'); announce('orders', 'return', 'A return request was accepted because the seller did not reply'); return_after_accept($c['id']); }
        }
        foreach ($db->all("SELECT c.id, c.seller_id, c.order_item_id, i.name FROM return_cases c JOIN order_items i ON i.id = c.order_item_id WHERE c.status = 'seller_accepted' AND c.kind = 'return' AND c.source = 'customer' AND c.ship_by IS NOT NULL AND c.ship_by < ?", [now_db()]) as $c) {
            $n = $db->exec("UPDATE return_cases SET status = 'rejected', resolution_note = ?, resolved_at = ?, updated_at = ? WHERE id = ? AND status = 'seller_accepted'", ['The item was not sent back in time.', now_db(), now_db(), $c['id']]);
            if ($n) { return_unfreeze($c['id'], $c['order_item_id']); notify($c['seller_id'], 'return', 'Return closed: ' . $c['name'], 'The customer did not send the item back in time. Your earnings are released.', '/seller?tab=returns'); return_tell_customer($c['id'], 'Your return request for ' . $c['name'] . ' was closed because the item was not sent back within ' . RETURN_SHIP_DAYS . ' days.'); }
        }
        foreach ($db->all("SELECT id FROM return_cases WHERE status = 'return_shipped' AND source = 'customer' AND shipped_at IS NOT NULL AND shipped_at < ?", [db_plus('-' . RETURN_RECEIVE_DAYS . ' days')]) as $c) {
            if ($db->exec("UPDATE return_cases SET status = 'received', updated_at = ? WHERE id = ? AND status = 'return_shipped'", [now_db(), $c['id']])) return_auto_refund($c['id'], 'Item sent back; the seller did not confirm within ' . RETURN_RECEIVE_DAYS . ' days', true);
        }
    } catch (Throwable $e) {
        log_error('returns sweep: ' . $e->getMessage());
    }
}

// For the marketplace admin: every case with its stage, who is waiting on whom, and the customer's refund.
function marketplace_returns_feed(string $filter, int $limit): array
{
    $where = $filter === 'done' ? "c.status IN ('refunded', 'rejected')" : ($filter === 'all' ? '1 = 1' : "c.status NOT IN ('refunded', 'rejected')");
    $waiting = ['open' => 'Seller to reply', 'seller_accepted' => 'Customer to send the item', 'return_shipped' => 'Seller to confirm arrival', 'seller_disputed' => 'DDLX to decide', 'received' => 'Refund being processed', 'refunded' => 'Done', 'rejected' => 'Done'];
    $out = [];
    foreach (db()->all('SELECT c.id, c.reason, c.kind, c.status, c.source, c.details, c.resolution_note, c.respond_by, c.ship_by, c.return_carrier, c.return_tracking, c.created_at, c.updated_at,
            i.name AS item_name, i.line_total, o.number, o.marketplace_order_id, o.currency, o.customer_name,
            (SELECT status FROM customer_refunds r WHERE r.order_id = o.id ORDER BY r.created_at DESC LIMIT 1) AS refund_status,
            (SELECT a.brand FROM seller_applications a WHERE a.user_id = c.seller_id) AS brand
        FROM return_cases c JOIN order_items i ON i.id = c.order_item_id JOIN orders o ON o.id = i.order_id WHERE ' . $where . ' ORDER BY c.created_at DESC LIMIT ' . $limit) as $r) {
        $brand = jdec($r['brand'] ?? '', []);
        $out[] = ['id' => $r['id'], 'order_id' => $r['marketplace_order_id'], 'order_number' => $r['number'], 'item' => $r['item_name'], 'amount' => (float) $r['line_total'], 'currency' => $r['currency'], 'customer' => $r['customer_name'],
            'seller' => trim((string) ($brand['store_name'] ?? '')), 'reason' => $r['reason'], 'kind' => $r['kind'], 'status' => $r['status'], 'stage' => RETURN_LABEL[$r['status']] ?? $r['status'], 'waiting_on' => $waiting[$r['status']] ?? '',
            'tracking' => trim($r['return_carrier'] . ' ' . $r['return_tracking']), 'refund_status' => $r['refund_status'], 'note' => $r['resolution_note'], 'created_at' => iso($r['created_at']), 'updated_at' => iso($r['updated_at'])];
    }
    return $out;
}
