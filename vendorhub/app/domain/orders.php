<?php
declare(strict_types=1);

// Admin side of orders. The admin can move an order from here or from WooCommerce; both stay in step.

const CARRIERS = ['DHL', 'GIG Logistics', 'FedEx', 'UPS', 'Kwik', 'Sendbox', 'Own delivery', 'Other'];
const ADMIN_ORDER_FILTERS = ['ready' => 'Ready for pickup', 'open' => 'In progress', 'done' => 'Finished', 'all' => 'All'];

// One row per marketplace order. "stage" is the slowest seller's progress, so an order is only "ready" when every seller is.
function admin_orders(string $filter = 'open', int $limit = 300): array
{
    $rows = db()->all('SELECT o.id, o.marketplace_order_id, o.number, o.currency, o.customer_name, o.ship_city, o.ship_state, o.placed_at,
            i.id AS item_id, i.seller_id, i.name, i.quantity, i.line_total, i.status, i.fulfilment, i.carrier, i.tracking_number
        FROM orders o JOIN order_items i ON i.order_id = o.id
        WHERE o.id IN (SELECT id FROM (SELECT id FROM orders ORDER BY placed_at DESC LIMIT ' . (int) $limit . ') x)
        ORDER BY o.placed_at DESC, i.created_at');
    $orders = [];
    foreach ($rows as $r) {
        $o = &$orders[$r['id']];
        $o ??= ['id' => $r['id'], 'marketplace_order_id' => $r['marketplace_order_id'], 'number' => $r['number'], 'currency' => $r['currency'], 'customer_name' => $r['customer_name'],
            'ship_city' => $r['ship_city'], 'ship_state' => $r['ship_state'], 'placed_at' => $r['placed_at'], 'items' => [], 'total' => 0.0, 'sellers' => [], 'stage' => null,
            'carrier' => '', 'tracking' => '', 'refunded' => 0, 'live' => 0];
        $o['items'][] = ['id' => $r['item_id'], 'name' => $r['name'], 'quantity' => (int) $r['quantity'], 'seller_id' => $r['seller_id'], 'fulfilment' => $r['fulfilment'], 'status' => $r['status']];
        $o['sellers'][$r['seller_id']] = true;
        if ($r['status'] === 'refunded') { $o['refunded']++; continue; }
        $o['live']++;
        $o['total'] += (float) $r['line_total'];
        $rank = FULFILMENT_RANK[$r['fulfilment']];
        if ($o['stage'] === null || $rank < FULFILMENT_RANK[$o['stage']]) $o['stage'] = $r['fulfilment'];
        if ($r['carrier'] !== '') { $o['carrier'] = $r['carrier']; $o['tracking'] = $r['tracking_number']; }
        unset($o);
    }
    $names = [];
    foreach (db()->all('SELECT id, email FROM users') as $u) $names[$u['id']] = $u['email'];
    $out = [];
    foreach ($orders as $o) {
        $o['state'] = $o['live'] === 0 ? 'refunded' : $o['stage'];
        $ready = $o['state'] === 'ready_for_shipping';
        $finished = in_array($o['state'], ['completed', 'refunded'], true);
        if ($filter === 'ready' && !$ready) continue;
        if ($filter === 'open' && $finished) continue;
        if ($filter === 'done' && !$finished) continue;
        $o['seller_labels'] = array_map(fn ($id) => store_label((string) $id, $names), array_keys($o['sellers']));
        $out[] = $o;
    }
    return $out;
}

function store_label(string $sellerId, array $emails): string
{
    $b = db()->val('SELECT brand FROM seller_applications WHERE user_id = ?', [$sellerId]);
    $name = trim((string) (jdec((string) $b, [])['store_name'] ?? ''));
    return $name !== '' ? $name : ($emails[$sellerId] ?? 'Seller');
}

function orders_needing_pickup(): int
{
    $n = 0;
    foreach (admin_orders('ready') as $_) $n++;
    return $n;
}

// Moves a whole order. shipped / delivered / completed go forward only; cancelled / refunded close it.
// The marketplace is told first, so if it cannot be reached nothing changes. Returns an error message or null.
function admin_set_order_status(array $admin, string $orderId, string $to, string $carrier = '', string $tracking = '', bool $restock = true, string $note = ''): ?string
{
    $db = db();
    $order = $db->one('SELECT id, marketplace_order_id, number FROM orders WHERE id = ?', [$orderId]);
    if (!$order) return 'Order not found.';
    if (!in_array($to, ['shipped', 'delivered', 'completed', 'cancelled', 'refunded'], true)) return 'Unknown action.';
    $items = $db->all("SELECT id, fulfilment FROM order_items WHERE order_id = ? AND status = 'paid'", [$order['id']]);
    if (!$items) return 'This order is already refunded or cancelled.';
    $lowest = min(array_map(fn ($i) => FULFILMENT_RANK[$i['fulfilment']], $items));
    $carrier = trim($carrier); $tracking = trim($tracking);
    if (in_array($to, ['shipped', 'delivered', 'completed'], true)) {
        $r = FULFILMENT_RANK[$to];
        if ($lowest >= $r) return 'This order is already ' . strtolower(FULFILMENT_LABEL[$to]) . ' or further along.';
        if ($to !== 'shipped' && $lowest < FULFILMENT_RANK['shipped']) return 'Mark the order as shipped first.';
        if ($to === 'shipped') {
            if (!in_array($carrier, CARRIERS, true)) return 'Choose a carrier.';
            if ($carrier !== 'Own delivery' && $tracking === '') return 'Enter the tracking number.';
            if (mb_strlen($tracking) > 100) return 'Tracking number is too long.';
        }
    } elseif ($to === 'cancelled' && $lowest >= FULFILMENT_RANK['shipped']) {
        return 'A shipped order cannot be cancelled. Use refund instead.';
    }
    if (marketplace_connected()) {
        $res = marketplace_call('/order-status', 'POST', ['order_id' => $order['marketplace_order_id'], 'status' => $to, 'carrier' => $carrier, 'tracking_number' => $tracking, 'restock' => $restock, 'by' => 'admin']);
        if ($res === null) return 'Could not reach the marketplace just now, so nothing was changed. Please try again in a minute.';
    }
    if (in_array($to, ['cancelled', 'refunded'], true)) {
        record_refund(['event' => 'order.refunded', 'order' => ['id' => $order['marketplace_order_id'], 'number' => $order['number'], 'restock' => $restock]]);
        foreach ($items as $i) log_item_status($i['id'], $to, 'admin', $note);
    } else {
        apply_marketplace_status(['id' => $order['marketplace_order_id'], 'status' => $to, 'carrier' => $carrier, 'tracking' => $tracking, 'note' => $note], 'admin');
    }
    return null;
}
