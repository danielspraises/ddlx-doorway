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
const RETURN_ACTOR = ['customer' => 'Customer', 'seller' => 'Seller', 'admin' => 'DDLX', 'system' => 'Automatic'];

// A short case number people can quote, like a ticket: RT-000042.
function return_next_ref(): string
{
    $n = (int) setting('return_ref_seq', '0') + 1;
    set_setting('return_ref_seq', (string) $n);
    return 'RT-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

// Every step of a case is written to its history: who did what, and when.
function return_log(string $caseId, string $actor, string $text): void
{
    try {
        db()->insert('return_events', ['id' => uuid(), 'case_id' => $caseId, 'actor' => $actor, 'body' => str_limit(trim($text), 1000), 'created_at' => now_db()]);
    } catch (Throwable $e) {
        log_error('return history: ' . $e->getMessage());
    }
}

function return_events_map(array $caseIds): array
{
    if (!$caseIds) return [];
    $out = [];
    foreach (db()->all('SELECT case_id, actor, body, created_at FROM return_events WHERE case_id IN (' . db()->placeholders($caseIds) . ') ORDER BY created_at, id', array_values($caseIds)) as $r) $out[$r['case_id']][] = $r;
    return $out;
}

// What happens next, in one plain sentence for the people working the case.
function return_next_step(array $c): string
{
    return match ($c['status']) {
        'open' => 'Waiting for the seller to reply' . (!empty($c['respond_by']) ? ' by ' . human_date($c['respond_by'], true) . '. No reply counts as accepting.' : '.'),
        'seller_accepted' => $c['kind'] === 'refund_only' ? 'Refund is being processed.' : (!empty($c['pickup_order_id']) ? 'Courier pickup is being arranged from the customer.' : 'Waiting for the customer to send the item back' . (!empty($c['ship_by']) ? ' by ' . human_date($c['ship_by']) : '') . '.'),
        'return_shipped' => 'Item is on its way to the seller. The seller confirms when it arrives; after ' . RETURN_RECEIVE_DAYS . ' days without a reply it counts as received.',
        'seller_disputed' => 'DDLX is reviewing and will make the decision.',
        'refunded' => 'Closed. The customer was refunded and the earnings were reversed.',
        'rejected' => 'Closed. Nothing changed and the earnings were released.',
        default => '',
    };
}

function return_decorate(array $rows): array
{
    foreach ($rows as &$r) {
        $brand = jdec((string) ($r['brand'] ?? ''), []);
        $r['store_name'] = trim((string) ($brand['store_name'] ?? '')) ?: (string) ($r['seller_email'] ?? 'Seller');
        $r['next_step'] = return_next_step($r);
    }
    return $rows;
}

function return_base_sql(): string
{
    return 'SELECT c.*, i.name AS item_name, i.sku, i.quantity, i.line_total, i.net, i.status AS item_status, o.number, o.marketplace_order_id, o.currency, o.customer_name, u.email AS seller_email,
        (SELECT a.brand FROM seller_applications a WHERE a.user_id = c.seller_id) AS brand,
        (SELECT r.status FROM customer_refunds r WHERE r.case_id = c.id ORDER BY r.created_at DESC LIMIT 1) AS refund_status
        FROM return_cases c JOIN order_items i ON i.id = c.order_item_id JOIN orders o ON o.id = i.order_id JOIN users u ON u.id = c.seller_id';
}

function seller_returns(string $sellerId): array
{
    return return_decorate(db()->all(return_base_sql() . ' WHERE c.seller_id = ? ORDER BY c.created_at DESC LIMIT 200', [$sellerId]));
}

function admin_returns(string $filter = 'open', string $sellerId = '', string $q = ''): array
{
    $where = $filter === 'done' ? "c.status IN ('refunded', 'rejected')" : ($filter === 'all' ? '1 = 1' : "c.status NOT IN ('refunded', 'rejected')");
    $args = [];
    if ($sellerId !== '') { $where .= ' AND c.seller_id = ?'; $args[] = $sellerId; }
    if ($q !== '') { $where .= ' AND (c.ref LIKE ? OR i.name LIKE ? OR o.number LIKE ? OR o.customer_name LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like, $like); }
    return return_decorate(db()->all(return_base_sql() . ' WHERE ' . $where . ' ORDER BY c.created_at DESC LIMIT 300', $args));
}

// Sellers who have return cases, for the admin's filter.
function return_sellers(): array
{
    $out = [];
    foreach (db()->all('SELECT DISTINCT c.seller_id, u.email, (SELECT a.brand FROM seller_applications a WHERE a.user_id = c.seller_id) AS brand FROM return_cases c JOIN users u ON u.id = c.seller_id') as $r) {
        $brand = jdec((string) ($r['brand'] ?? ''), []);
        $out[$r['seller_id']] = trim((string) ($brand['store_name'] ?? '')) ?: $r['email'];
    }
    asort($out);
    return $out;
}

// A note on the case from the seller or from DDLX, kept in its history. The other side is told.
function return_message(string $actor, ?string $sellerId, string $caseId, string $text): ?string
{
    $db = db();
    $text = trim($text);
    if ($text === '') return 'Write a message first.';
    if (mb_strlen($text) > 1000) return 'The message is too long.';
    $c = $db->one('SELECT c.id, c.ref, c.seller_id, i.name FROM return_cases c JOIN order_items i ON i.id = c.order_item_id WHERE c.id = ?' . ($actor === 'seller' ? ' AND c.seller_id = ?' : ''), $actor === 'seller' ? [$caseId, $sellerId] : [$caseId]);
    if (!$c) return 'Case not found.';
    return_log($c['id'], $actor, $text);
    if ($actor === 'admin') notify($c['seller_id'], 'return', 'Message from DDLX on ' . ($c['ref'] ?: 'a return case') . ': ' . $c['name'], $text, '/seller?tab=returns');
    else announce('orders', 'return', 'A seller wrote on ' . ($c['ref'] ?: 'a return case'));
    return null;
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
    $db->insert('return_cases', ['id' => $id, 'ref' => return_next_ref(), 'order_item_id' => $itemId, 'seller_id' => $it['seller_id'], 'opened_by' => $admin['id'], 'reason' => $reason, 'details' => $details, 'status' => 'open',
        'seller_response' => '', 'resolution_note' => '', 'kind' => in_array($reason, RETURN_REFUND_ONLY, true) ? 'refund_only' : 'return', 'created_at' => $now, 'updated_at' => $now]);
    return_freeze($id, $itemId);
    return_log($id, 'admin', 'Case opened: ' . $reason . ($details !== '' ? '. ' . $details : ''));
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
    return_log($caseId, 'seller', ($action === 'accept' ? 'Agreed to the return' : 'Disagreed with the return') . ($response !== '' ? ': ' . $response : ''));
    if ($action === 'accept') return_after_accept($caseId);
    else return_tell_customer($caseId, 'Your return request is under review by our team. We will update you as soon as a decision is made. You do not need to do anything.');
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
        return_log($c['id'], 'admin', 'Approved the return' . ($note !== '' ? ': ' . $note : ''));
        return_after_accept($c['id']);
        return null;
    }
    $sellers = [];
    $err = $db->tx(function () use ($db, $c, $decision, $restock, $note, $admin, $label, &$sellers) {
        if ($decision === 'refund') {
            $line = $db->one("SELECT id, seller_id, product_id, variation_id, quantity, net FROM order_items WHERE id = ? AND status = 'paid'" . $db->lock(), [$c['order_item_id']]);
            if (!$line) return 'This item was already refunded.';
            refund_lines($db, $c['currency'], [$line], $label, $restock, $sellers, $c['id'], $c['reason']);
        }
        $db->exec('UPDATE return_cases SET status = ?, resolution_note = ?, resolved_by = ?, resolved_at = ?, updated_at = ? WHERE id = ?', [$decision === 'refund' ? 'refunded' : 'rejected', $note, $admin['id'], now_db(), now_db(), $c['id']]);
        if ($decision === 'refund') log_item_status($c['order_item_id'], 'refunded', 'admin', $note);
        else return_unfreeze($c['id'], $c['order_item_id']);
        return null;
    });
    if ($err) return $err;
    return_log($c['id'], $admin['id'] === RETURN_SYSTEM_ID ? 'system' : 'admin', $decision === 'refund' ? 'Refund approved' . ($restock ? ', item returned to stock' : '') . ($note !== '' ? ': ' . $note : '') : 'Request declined: ' . $note);
    notify($c['seller_id'], 'return', $decision === 'refund' ? 'Refunded: ' . $c['item_name'] . ' (order ' . $label . ')' : 'Return rejected: ' . $c['item_name'] . ' (order ' . $label . ')',
        $decision === 'refund' ? 'Your earnings for this item were taken back.' . ($note !== '' ? "\n" . $note : '') : $note, '/seller?tab=returns');
    if ($c['source'] === 'customer') return_tell_customer($c['id'], $decision === 'refund'
        ? 'Your refund of ' . money((float) $c['line_total'], $c['currency']) . ' for ' . $c['item_name'] . ' has been initiated. It will be returned to your original payment method, and depending on your bank it can take a few business days to show.'
        : 'We are unable to approve your return request for ' . $c['item_name'] . '.' . ($note !== '' ? ' ' . $note : '') . ' If you need more help, please contact our support team.');
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
        $db->insert('return_cases', ['id' => $id, 'ref' => return_next_ref(), 'order_item_id' => $it['id'], 'seller_id' => $it['seller_id'], 'opened_by' => '00000000-0000-4000-8000-000000000000', 'reason' => $reason, 'details' => $details,
            'status' => 'open', 'seller_response' => '', 'resolution_note' => '', 'source' => 'customer', 'kind' => $refundOnly ? 'refund_only' : 'return', 'respond_by' => db_plus('+' . RETURN_SELLER_HOURS . ' hours'),
            'photos' => $photos ? json_encode($photos) : null, 'created_at' => $now, 'updated_at' => $now]);
        return_freeze($id, $it['id']);
    });
    $label = $it['number'] ?: $it['marketplace_order_id'];
    notify($it['seller_id'], 'return', 'Return request on order ' . $label . ': ' . $it['name'], $reason . ($details !== '' ? "\n" . $details : '') . "\n\nPlease accept or dispute within " . RETURN_SELLER_HOURS . ' hours. No reply counts as accepting.', '/seller?tab=returns', true);
    announce('orders', 'return', 'A customer asked for a return');
    return_log($id, 'customer', 'Asked for a return: ' . $reason . ($details !== '' ? '. ' . $details : '') . ($photos ? ' (' . count($photos) . ' photo' . (count($photos) > 1 ? 's' : '') . ')' : ''));
    return_tell_customer($id, 'We have received your return request for ' . $it['name'] . ' (reference ' . (string) $db->val('SELECT ref FROM return_cases WHERE id = ?', [$id]) . '). We will keep you updated, and you can follow its progress on your order page.');
    return ['ok' => true, 'case_id' => $id, 'ref' => (string) $db->val('SELECT ref FROM return_cases WHERE id = ?', [$id])];
}

// What the customer sees for one marketplace order.
function customer_cases_for_order(string $marketplaceOrderId): array
{
    $out = [];
    foreach (db()->all('SELECT c.id, c.reason, c.status, c.kind, c.resolution_note, c.created_at, c.respond_by, c.ship_by, c.return_carrier, c.return_tracking, c.pickup_order_id, c.ref, (SELECT r.status FROM customer_refunds r WHERE r.case_id = c.id ORDER BY r.created_at DESC LIMIT 1) AS refund_status, i.product_id, i.variation_id FROM return_cases c JOIN order_items i ON i.id = c.order_item_id JOIN orders o ON o.id = i.order_id WHERE o.marketplace_order_id = ? ORDER BY c.created_at DESC', [$marketplaceOrderId]) as $r) {
        $waitingSend = $r['status'] === 'seller_accepted' && $r['kind'] === 'return';
        $booked = $waitingSend && !empty($r['pickup_order_id']);
        $send = $waitingSend && !$booked;
        $refunded = in_array($r['refund_status'] ?? '', ['refunded', 'manual'], true);
        $initiated = 'Refund initiated';
        $label = ['open' => 'Request received', 'seller_accepted' => $booked ? 'Approved. Pickup is being arranged' : ($send ? 'Approved. Please send the item back' : 'Approved. ' . $initiated), 'return_shipped' => 'Item on its way back',
            'received' => 'Item received. ' . $initiated, 'seller_disputed' => 'Under review', 'refunded' => $refunded ? 'Refund completed' : $initiated, 'rejected' => 'Request declined'][$r['status']] ?? $r['status'];
        $out[] = ['id' => $r['id'], 'ref' => $r['ref'], 'product_id' => $r['product_id'], 'variation_id' => $r['variation_id'], 'reason' => $r['reason'], 'status' => $r['status'], 'label' => $label, 'note' => $r['resolution_note'], 'created_at' => iso($r['created_at']),
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
    return_log($c['id'], 'customer', 'Sent the item back with ' . trim($carrier . ' ' . $tracking));
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
    return_log($caseId, 'seller', $action === 'received' ? 'Confirmed the item arrived' . ($note !== '' ? ': ' . $note : '') : 'Reported a problem with the returned item: ' . $note);
    if ($action === 'received') return_auto_refund($caseId, 'Item returned and received by the seller', true);
    else { announce('orders', 'return', 'A seller reported a problem with a returned item'); return_tell_customer($caseId, 'Your return is under review by our team. We will update you as soon as a decision is made. You do not need to do anything.'); }
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
    if ($c['kind'] === 'refund_only') { return_log($caseId, 'system', 'Refund without sending the item back'); return_auto_refund($caseId, 'Approved: refund without sending the item back', false); return; }
    $db->exec('UPDATE return_cases SET ship_by = ? WHERE id = ?', [db_plus('+' . RETURN_SHIP_DAYS . ' days'), $caseId]);
    // Preferred: the marketplace books the return as a courier pickup (customer to the seller's store) through the same delivery system.
    if (marketplace_connected() && setting('return_mode', 'pickup') !== 'self') {
        $ctx = $db->one('SELECT i.product_id, i.variation_id, i.quantity, i.name, i.seller_id, o.marketplace_order_id FROM return_cases c JOIN order_items i ON i.id = c.order_item_id JOIN orders o ON o.id = i.order_id WHERE c.id = ?', [$caseId]);
        $seller = $ctx && function_exists('marketplace_seller_payload') ? marketplace_seller_payload($ctx['seller_id']) : [];
        $res = $ctx ? marketplace_call('/return-pickup', 'POST', ['case_id' => $caseId, 'order_id' => $ctx['marketplace_order_id'], 'product_id' => $ctx['product_id'], 'variation_id' => $ctx['variation_id'], 'quantity' => (int) $ctx['quantity'],
            'item_name' => $ctx['name'], 'seller_id' => $ctx['seller_id'], 'seller_name' => $seller['store_name'] ?? '', 'pickup' => $seller['pickup'] ?? []], 15) : null;
        if (is_array($res) && !empty($res['order_id'])) {
            $db->exec('UPDATE return_cases SET pickup_order_id = ? WHERE id = ?', [str_limit((string) $res['order_id'], 40), $caseId]);
            return_log($caseId, 'system', 'Courier pickup booked (return order ' . (string) $res['order_id'] . ')');
            return_tell_customer($caseId, 'Your return request has been approved. Our courier will collect the item from your delivery address. Please have it packed and ready. You do not need to do anything else.');
            return;
        }
    }
    $addr = trim((string) setting('return_address', ''));
    return_log($caseId, 'system', 'Customer asked to send the item back' . ($addr !== '' ? ' to the return address' : ''));
    return_tell_customer($caseId, 'Your return request has been approved. Please send the item back within ' . RETURN_SHIP_DAYS . ' days' . ($addr !== '' ? ' to: ' . $addr : '') . ', then add the courier and tracking number in your account under this order.');
}

// The courier collected the return (shipped) or dropped it at the seller's store (delivered). Reported by the marketplace's return order.
function return_pickup_update(array $r): array
{
    $db = db();
    $c = $db->one("SELECT c.id, c.seller_id, c.status, i.name FROM return_cases c JOIN order_items i ON i.id = c.order_item_id WHERE c.id = ? AND c.pickup_order_id = ?", [(string) ($r['case_id'] ?? ''), (string) ($r['order_id'] ?? '')]);
    if (!$c) return ['error' => 'Unknown return.', 'code' => 404];
    $st = (string) ($r['status'] ?? '');
    if (!in_array($st, ['shipped', 'delivered'], true)) return ['error' => 'Unknown status.', 'code' => 400];
    if ($st === 'shipped' && $c['status'] === 'seller_accepted') {
        $db->exec("UPDATE return_cases SET status = 'return_shipped', return_carrier = 'DDLX courier', return_tracking = ?, shipped_at = ?, updated_at = ? WHERE id = ? AND status = 'seller_accepted'", ['return ' . str_limit((string) $r['order_id'], 30), now_db(), now_db(), $c['id']]);
        notify($c['seller_id'], 'return', 'A courier collected the return: ' . $c['name'], "It is on its way to your store.\n\nConfirm when it arrives. If you do not confirm within " . RETURN_RECEIVE_DAYS . ' days it counts as received and the customer is refunded.', '/seller?tab=returns');
        return_log($c['id'], 'system', 'Courier collected the item');
        return_tell_customer($c['id'], 'Your item has been collected and is on its way back. Your refund will be initiated as soon as it is received.');
    } elseif ($st === 'delivered' && $c['status'] === 'return_shipped') {
        return_log($c['id'], 'system', 'Courier delivered the item to the seller');
        notify($c['seller_id'], 'return', 'The return reached your store: ' . $c['name'], 'Please check the item and confirm it arrived, or tell us if something is wrong.', '/seller?tab=returns');
    }
    return ['ok' => true];
}

// Approves the refund as the system: seller earnings are reversed and, when Paystack is on, the customer is paid back at once.
function return_auto_refund(string $caseId, string $note, bool $restock): void
{
    $actor = ['id' => RETURN_SYSTEM_ID, 'email' => 'system'];
    $err = resolve_return($actor, $caseId, 'refund', $restock, $note);
    if ($err) { log_error('Automatic refund for case ' . $caseId . ': ' . $err); return; }
    audit($actor, 'return', 'Approved a refund automatically (' . $note . ')', $caseId);
    return_log($caseId, 'system', 'Refund initiated automatically');
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
            if ($n) { notify($c['seller_id'], 'return', 'Return accepted automatically: ' . $c['name'], 'You did not reply within ' . RETURN_SELLER_HOURS . ' hours.', '/seller?tab=returns'); announce('orders', 'return', 'A return request was accepted because the seller did not reply'); return_log($c['id'], 'system', 'No reply within ' . RETURN_SELLER_HOURS . ' hours, so the request was accepted'); return_after_accept($c['id']); }
        }
        foreach ($db->all("SELECT c.id, c.seller_id, c.order_item_id, i.name FROM return_cases c JOIN order_items i ON i.id = c.order_item_id WHERE c.status = 'seller_accepted' AND c.kind = 'return' AND c.source = 'customer' AND c.ship_by IS NOT NULL AND c.ship_by < ?", [now_db()]) as $c) {
            $n = $db->exec("UPDATE return_cases SET status = 'rejected', resolution_note = ?, resolved_at = ?, updated_at = ? WHERE id = ? AND status = 'seller_accepted'", ['The item was not sent back in time.', now_db(), now_db(), $c['id']]);
            if ($n) { return_unfreeze($c['id'], $c['order_item_id']); notify($c['seller_id'], 'return', 'Return closed: ' . $c['name'], 'The customer did not send the item back in time. Your earnings are released.', '/seller?tab=returns'); return_log($c['id'], 'system', 'Closed: the item was not sent back in ' . RETURN_SHIP_DAYS . ' days'); return_tell_customer($c['id'], 'Your return request for ' . $c['name'] . ' has been closed because we did not receive the item within ' . RETURN_SHIP_DAYS . ' days. If you still need help, please contact our support team.'); }
        }
        foreach ($db->all("SELECT id FROM return_cases WHERE status = 'return_shipped' AND source = 'customer' AND shipped_at IS NOT NULL AND shipped_at < ?", [db_plus('-' . RETURN_RECEIVE_DAYS . ' days')]) as $c) {
            if ($db->exec("UPDATE return_cases SET status = 'received', updated_at = ? WHERE id = ? AND status = 'return_shipped'", [now_db(), $c['id']])) { return_log($c['id'], 'system', 'The seller did not confirm within ' . RETURN_RECEIVE_DAYS . ' days, so the item counts as received'); return_auto_refund($c['id'], 'Item sent back; the seller did not confirm within ' . RETURN_RECEIVE_DAYS . ' days', true); }
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
