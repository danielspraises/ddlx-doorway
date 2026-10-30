<?php
declare(strict_types=1);

// Sales from the marketplace, the seller wallet, bank accounts, withdrawals and the store profile.

const MAX_BANK_ACCOUNTS = 3;
const WITHDRAWAL_LABEL = ['requested' => 'Waiting to be paid', 'processing' => 'Sending to your bank', 'paid' => 'Paid', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'failed' => 'Failed, money returned'];

function round2(float $v): float
{
    return round($v, 2);
}

// ---------------------------------------------------------------- wallet

// Per currency: available (hold passed), pending (still on hold) and total.
function wallet_balances(string $sellerId): array
{
    $now = now_db();
    $rows = db()->all('SELECT currency, amount, available_at FROM wallet_entries WHERE seller_id = ?', [$sellerId]);
    $out = [];
    foreach ($rows as $r) {
        $c = $r['currency'];
        $out[$c] ??= ['currency' => $c, 'available' => 0.0, 'pending' => 0.0, 'total' => 0.0];
        $a = (float) $r['amount'];
        $out[$c][$r['available_at'] <= $now ? 'available' : 'pending'] += $a;
        $out[$c]['total'] += $a;
    }
    ksort($out);
    return array_map(fn ($b) => ['currency' => $b['currency'], 'available' => round2($b['available']), 'pending' => round2($b['pending']), 'total' => round2($b['total'])], array_values($out));
}

function sales_last_30d(string $sellerId): array
{
    $rows = db()->all("SELECT o.currency, i.line_total, i.net, i.quantity FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.seller_id = ? AND i.status = 'paid' AND o.placed_at > ?", [$sellerId, db_plus('-30 days')]);
    $out = [];
    foreach ($rows as $r) {
        $c = $r['currency'];
        $out[$c] ??= ['currency' => $c, 'gross' => 0.0, 'net' => 0.0, 'units' => 0];
        $out[$c]['gross'] += (float) $r['line_total'];
        $out[$c]['net'] += (float) $r['net'];
        $out[$c]['units'] += (int) $r['quantity'];
    }
    ksort($out);
    return array_values($out);
}

function seller_sales(string $sellerId, int $limit = 200): array
{
    return db()->all('SELECT i.id, o.number AS order_number, o.placed_at, i.name, i.sku, i.options, i.quantity, i.unit_price, i.line_total, i.commission, i.net, o.currency, i.status, i.fulfilment, i.carrier, i.tracking_number, o.customer_name, o.ship_city, o.ship_state FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.seller_id = ? ORDER BY o.placed_at DESC, i.created_at DESC LIMIT ' . (int) $limit, [$sellerId]);
}

// Order lifecycle, shared with the marketplace:
//   placed -> processing -> ready_for_shipping   (the seller moves these, Vendor Hub tells the marketplace)
//   -> shipped -> delivered -> completed         (the marketplace moves these, it tells Vendor Hub)
// Refunds and cancellations come from the marketplace too (order_items.status = 'refunded').
const FULFILMENT_LABEL = ['placed' => 'New order', 'processing' => 'Processing', 'ready_for_shipping' => 'Ready for shipping', 'shipped' => 'Shipped',
    'delivered' => 'Delivered', 'completed' => 'Completed'];
const FULFILMENT_RANK = ['placed' => 0, 'processing' => 1, 'ready_for_shipping' => 2, 'shipped' => 3, 'delivered' => 4, 'completed' => 5];

// A sale's money stays "pending" until the order is delivered; then it is released after the hold period.
const NOT_DELIVERED_YET = '9999-12-31 00:00:00.000';
const LOG_LABEL = FULFILMENT_LABEL + ['refunded' => 'Refunded', 'cancelled' => 'Cancelled'];

function log_item_status(string $itemId, string $status, string $source, string $note = ''): void
{
    db()->insert('order_item_log', ['id' => uuid(), 'order_item_id' => $itemId, 'status' => $status, 'source' => $source, 'note' => str_limit($note, 250), 'created_at' => now_db()]);
}

function item_history(array $itemIds): array
{
    if (!$itemIds) return [];
    $out = [];
    foreach (db()->all('SELECT order_item_id, status, source, note, created_at FROM order_item_log WHERE order_item_id IN (' . db()->placeholders($itemIds) . ') ORDER BY created_at, id', $itemIds) as $r) $out[$r['order_item_id']][] = $r;
    return $out;
}

// The seller moves their items in one order forward. The marketplace is told first; if it cannot be reached nothing changes,
// so the two sides never disagree. Returns an error message or null.
function seller_set_fulfilment(string $sellerId, string $itemId, string $to): ?string
{
    $db = db();
    if (!in_array($to, ['processing', 'ready_for_shipping'], true)) return 'Unknown action.';
    $it = $db->one('SELECT i.id, i.order_id, i.fulfilment, i.status, o.marketplace_order_id, o.number FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.id = ? AND i.seller_id = ?', [$itemId, $sellerId]);
    if (!$it) return 'Order item not found.';
    if ($it['status'] === 'refunded') return 'This order was refunded or cancelled.';
    if (FULFILMENT_RANK[$it['fulfilment']] >= FULFILMENT_RANK[$to]) return 'This order has already moved past that step.';
    if (FULFILMENT_RANK[$it['fulfilment']] >= FULFILMENT_RANK['ready_for_shipping']) return 'The marketplace handles this order from here.';
    $lines = $db->all("SELECT id, product_id FROM order_items WHERE order_id = ? AND seller_id = ? AND status = 'paid' AND fulfilment IN ('placed', 'processing')", [$it['order_id'], $sellerId]);
    if (marketplace_connected()) {
        $r = marketplace_call('/order-status', 'POST', ['order_id' => $it['marketplace_order_id'], 'seller_id' => $sellerId, 'status' => $to,
            'product_ids' => array_values(array_unique(array_filter(array_column($lines, 'product_id'))))]);
        if ($r === null) return 'Could not reach the marketplace just now, so nothing was changed. Please try again in a minute.';
    }
    $db->tx(function () use ($db, $lines, $to) {
        foreach ($lines as $l) {
            $db->exec('UPDATE order_items SET fulfilment = ? WHERE id = ?', [$to, $l['id']]);
            log_item_status($l['id'], $to, 'seller');
        }
    });
    announce('orders', 'order', 'Order ' . ($it['number'] ?: $it['marketplace_order_id']) . ': seller marked ' . strtolower(FULFILMENT_LABEL[$to]));
    return null;
}

// A status the marketplace reports (shipped, delivered, completed, or ready_for_shipping set on its side). Only moves forward.
// Returns false when the order is unknown.
function apply_marketplace_status(array $o, string $source = 'marketplace'): bool
{
    $db = db();
    $sellers = [];
    $release = db_plus('+' . hold_days() . ' days');
    $found = $db->tx(function () use ($db, $o, $source, $release, &$sellers) {
        $order = $db->one('SELECT id, number FROM orders WHERE marketplace_order_id = ?', [$o['id']]);
        if (!$order) return false;
        $rank = FULFILMENT_RANK[$o['status']];
        foreach ($db->all("SELECT id, seller_id, fulfilment FROM order_items WHERE order_id = ? AND status = 'paid'" . $db->lock(), [$order['id']]) as $l) {
            if (FULFILMENT_RANK[$l['fulfilment']] >= $rank) {
                if ($l['fulfilment'] === $o['status'] && ($o['carrier'] !== '' || $o['tracking'] !== '')) $db->exec('UPDATE order_items SET carrier = ?, tracking_number = ? WHERE id = ?', [$o['carrier'], $o['tracking'], $l['id']]);
                continue;
            }
            $db->exec('UPDATE order_items SET fulfilment = ?, carrier = CASE WHEN ? <> \'\' THEN ? ELSE carrier END, tracking_number = CASE WHEN ? <> \'\' THEN ? ELSE tracking_number END WHERE id = ?',
                [$o['status'], $o['carrier'], $o['carrier'], $o['tracking'], $o['tracking'], $l['id']]);
            log_item_status($l['id'], $o['status'], $source, $o['note']);
            $sellers[$l['seller_id']] = $order['number'] ?: $o['id'];
        }
        // Delivered: start the hold clock on the sellers' money. (Completed also releases it, in case Delivered was skipped.)
        if (FULFILMENT_RANK[$o['status']] >= FULFILMENT_RANK['delivered']) {
            foreach (array_unique(array_column($db->all('SELECT seller_id FROM order_items WHERE order_id = ?', [$order['id']]), 'seller_id')) as $sellerOfLine) {
                $rel = db_plus('+' . max(0, hold_days() - level_perks(seller_level($sellerOfLine))['hold_off']) . ' days');
                $db->exec("UPDATE wallet_entries SET available_at = ? WHERE available_at = ? AND order_item_id IN (SELECT id FROM order_items WHERE order_id = ? AND seller_id = ?)", [$rel, NOT_DELIVERED_YET, $order['id'], $sellerOfLine]);
            }
        }
        return true;
    });
    if ($found && FULFILMENT_RANK[$o['status']] >= FULFILMENT_RANK['delivered']) foreach (array_keys($sellers) as $sid) { try { level_refresh($sid, true); } catch (Throwable $e) { log_error('level refresh: ' . $e->getMessage()); } }
    foreach ($sellers as $sid => $num) {
        announce('seller', 'order', 'Order ' . $num . ' is now ' . strtolower(FULFILMENT_LABEL[$o['status']]), $sid);
        notify($sid, 'order', 'Order ' . $num . ' is now ' . strtolower(FULFILMENT_LABEL[$o['status']]), $o['tracking'] !== '' ? trim($o['carrier'] . ' ' . $o['tracking']) : '', '/seller?tab=sales', in_array($o['status'], ['shipped', 'delivered'], true));
    }
    return $found;
}

// Validates a decoded order.status body. Returns the cleaned order or a string describing the problem.
function parse_status_event(mixed $d): array|string
{
    $o = is_array($d) ? ($d['order'] ?? null) : null;
    if (!is_array($o)) return 'Missing order.';
    $id = trim((string) ($o['id'] ?? ''));
    if ($id === '' || strlen($id) > 100) return 'Invalid order id.';
    $status = (string) ($o['status'] ?? '');
    if (!isset(FULFILMENT_RANK[$status]) || $status === 'placed' || $status === 'processing') return 'Invalid status.';
    return ['id' => $id, 'status' => $status, 'carrier' => str_limit(trim((string) ($o['carrier'] ?? '')), 80), 'tracking' => str_limit(trim((string) ($o['tracking_number'] ?? '')), 100),
        'note' => str_limit(trim((string) ($o['note'] ?? '')), 250)];
}

// ---------------------------------------------------------------- marketplace orders

class OrderRejected extends RuntimeException
{
}

// Validates a decoded webhook body. Returns the cleaned event or a string describing the problem.
function parse_order_event(mixed $d): array|string
{
    if (!is_array($d) || !in_array($d['event'] ?? '', ['order.paid', 'order.refunded'], true)) return 'Unknown event.';
    $o = $d['order'] ?? null;
    if (!is_array($o)) return 'Missing order.';
    $id = trim((string) ($o['id'] ?? ''));
    if ($id === '' || strlen($id) > 100) return 'Invalid order id.';
    if (!in_array($o['currency'] ?? '', CURRENCIES, true)) return 'Unsupported currency.';
    $placed = from_iso((string) ($o['placed_at'] ?? ''));
    if (!$placed) return 'Invalid placed_at.';
    $items = [];
    foreach ((array) ($o['items'] ?? []) as $it) {
        if (!is_array($it) || !is_uuid($it['product_id'] ?? null)) return 'Invalid item product_id.';
        $q = $it['quantity'] ?? null;
        $p = $it['unit_price'] ?? null;
        if (!is_int($q) || $q < 1 || $q > 10000) return 'Invalid item quantity.';
        if (!is_numeric($p) || $p < 0 || $p > 1000000000) return 'Invalid item unit_price.';
        $vid = $it['variation_id'] ?? null;
        if ($vid !== null && !is_uuid($vid)) return 'Invalid variation_id.';
        $disc = $it['discount'] ?? 0;
        $items[] = ['product_id' => $it['product_id'], 'variation_id' => $vid, 'quantity' => $q, 'unit_price' => (float) $p, 'discount' => is_numeric($disc) && $disc >= 0 && $disc <= 1000000000 ? (float) $disc : 0.0];
    }
    if (count($items) > 200) return 'Too many items.';
    return ['event' => $d['event'], 'order' => [
        'id' => $id, 'number' => str_limit((string) ($o['number'] ?? ''), 100), 'currency' => $o['currency'], 'placed_at' => $placed,
        'customer_name' => str_limit((string) ($o['customer_name'] ?? ''), 200), 'ship_city' => str_limit((string) ($o['ship_city'] ?? ''), 100),
        'ship_state' => str_limit((string) ($o['ship_state'] ?? ''), 100), 'restock' => !empty($o['restock']), 'payment_ref' => str_limit(trim((string) ($o['payment_ref'] ?? '')), 100), 'payment_method' => str_limit(trim((string) ($o['payment_method'] ?? '')), 40), 'items' => $items,
        'coupons' => array_slice(array_values(array_filter(array_map(fn ($c) => is_string($c) ? coupon_clean_code($c) : '', (array) ($o['coupons'] ?? [])))), 0, 10),
    ]];
}

// One line per product with the seller's commission, the wallet credit and the stock taken off.
// Safe to repeat: an order id that was already recorded changes nothing.
function record_paid_order(array $event): array
{
    $db = db();
    $o = $event['order'];
    if (!$o['items']) throw new OrderRejected('The order has no items.');
    $sellers = [];
    $names = [];
    $touched = [];
    $discBy = [];
    $result = $db->tx(function () use ($db, $o, &$sellers, &$names, &$touched, &$discBy) {
        if ($db->val('SELECT id FROM orders WHERE marketplace_order_id = ?', [$o['id']])) return ['duplicate' => true];
        $orderId = uuid();
        try {
            $db->insert('orders', ['id' => $orderId, 'marketplace_order_id' => $o['id'], 'number' => $o['number'], 'currency' => $o['currency'], 'customer_name' => $o['customer_name'],
                'ship_city' => $o['ship_city'], 'ship_state' => $o['ship_state'], 'payment_ref' => $o['payment_ref'] ?? '', 'payment_method' => $o['payment_method'] ?? '', 'placed_at' => $o['placed_at'], 'created_at' => now_db()]);
        } catch (Throwable $e) {
            if (Db::isDuplicate($e)) return ['duplicate' => true];
            throw $e;
        }
        foreach ($o['items'] as $it) {
            $p = $db->one('SELECT seller_id, name, sku, manage_stock, product_type, category_id FROM products WHERE id = ?' . $db->lock(), [$it['product_id']]);
            if (!$p) throw new OrderRejected('Unknown product ' . $it['product_id'] . '.');
            $sku = $p['sku'];
            $options = [];
            if ($it['variation_id']) {
                $v = $db->one('SELECT sku, options FROM product_variations WHERE id = ? AND product_id = ?', [$it['variation_id'], $it['product_id']]);
                if (!$v) throw new OrderRejected('Unknown variation ' . $it['variation_id'] . '.');
                $sku = $v['sku'];
                $options = jdec($v['options'], []);
            } elseif ($p['product_type'] === 'variable') {
                throw new OrderRejected('Product ' . $it['product_id'] . ' needs a variation_id.');
            }
            $rate = commission_rate_for($p['category_id'], $p['seller_id']);
            $line = round2($it['unit_price'] * $it['quantity']);
            $commission = round2($line * $rate / 100);
            $net = round2($line - $commission);
            $itemId = uuid();
            $db->insert('order_items', ['id' => $itemId, 'order_id' => $orderId, 'seller_id' => $p['seller_id'], 'product_id' => $it['product_id'], 'variation_id' => $it['variation_id'],
                'name' => $p['name'], 'sku' => $sku, 'options' => jenc((object) $options), 'quantity' => $it['quantity'], 'unit_price' => round2($it['unit_price']), 'line_total' => $line,
                'commission_rate' => $rate, 'commission' => $commission, 'net' => $net, 'status' => 'paid', 'fulfilment' => 'placed', 'created_at' => now_db()]);
            log_item_status($itemId, 'placed', 'marketplace', 'Order paid');
            $db->insert('wallet_entries', ['id' => uuid(), 'seller_id' => $p['seller_id'], 'kind' => 'sale', 'amount' => $net, 'currency' => $o['currency'], 'order_item_id' => $itemId,
                'note' => 'Order ' . ($o['number'] ?: $o['id']), 'available_at' => NOT_DELIVERED_YET, 'created_at' => now_db()]);
            if ($it['variation_id']) {
                $db->exec('UPDATE product_variations SET stock = CASE WHEN stock > ? THEN stock - ? ELSE 0 END WHERE id = ?', [$it['quantity'], $it['quantity'], $it['variation_id']]);
            } elseif ((int) $p['manage_stock'] === 1) {
                $db->exec('UPDATE products SET stock = CASE WHEN stock > ? THEN stock - ? ELSE 0 END WHERE id = ?', [$it['quantity'], $it['quantity'], $it['product_id']]);
            }
            $db->exec('UPDATE products SET updated_at = ? WHERE id = ?', [now_db(), $it['product_id']]);
            $sellers[$p['seller_id']] = round2(($sellers[$p['seller_id']] ?? 0) + $net);
            $names[$p['seller_id']][] = $it['quantity'] . ' × ' . $p['name'] . ($options ? ' (' . implode(' / ', $options) . ')' : '');
            $touched[$it['product_id']] = $p['seller_id'];
            $discBy[$p['seller_id']] = ($discBy[$p['seller_id']] ?? 0) + (float) ($it['discount'] ?? 0);
        }
        return ['duplicate' => false];
    });
    if (!$result['duplicate']) {
        if (!empty($o['coupons'])) { try { coupons_record_use($o['coupons'], $discBy); } catch (Throwable $e) { log_error('coupon use: ' . $e->getMessage()); } }
        announce('orders', 'order', 'New order ' . ($o['number'] ?: $o['id']));
        foreach ($sellers as $sid => $net) {
            announce('seller', 'sale', 'New sale: ' . $o['currency'] . ' ' . number_format($net, 2) . ' earned', $sid);
            notify($sid, 'sale', 'New order ' . ($o['number'] ?: $o['id']) . ': ' . $o['currency'] . ' ' . number_format($net, 2) . ' earned', implode("\n", $names[$sid]) . "\n\nStart processing it and mark it ready for shipping when it is packed.", '/seller?tab=sales');
        }
        foreach ($touched as $pid => $sid) {
            $p = $db->one('SELECT name, manage_stock, stock, low_stock_threshold, product_type FROM products WHERE id = ?', [$pid]);
            if ($p && (int) $p['manage_stock'] === 1 && $p['product_type'] === 'simple' && (int) $p['stock'] <= (int) $p['low_stock_threshold']) {
                notify($sid, 'stock', ((int) $p['stock'] === 0 ? 'Out of stock: ' : 'Low stock: ') . $p['name'] . ((int) $p['stock'] === 0 ? '' : ' (' . (int) $p['stock'] . ' left)'), 'Add stock so customers can keep buying it.', '/seller?tab=inventory');
            }
        }
    }
    return $result;
}

// Takes the listed paid lines back: earnings reversed (on the same availability date) and, if asked, stock returned.
function refund_lines(Db $db, string $currency, array $lines, string $label, bool $restock, array &$sellers): void
{
    $owed = [];
    foreach ($lines as $l) {
        $db->exec("UPDATE order_items SET status = 'refunded' WHERE id = ?", [$l['id']]);
        $money = $db->one('SELECT order_id, line_total FROM order_items WHERE id = ?', [$l['id']]);
        if ($money) $owed[$money['order_id']] = ($owed[$money['order_id']] ?? 0) + (float) $money['line_total'];
        $sale = $db->one("SELECT available_at FROM wallet_entries WHERE order_item_id = ? AND kind = 'sale'", [$l['id']]);
        if ($sale && !$db->val("SELECT 1 FROM wallet_entries WHERE order_item_id = ? AND kind = 'refund'", [$l['id']])) {
            $db->insert('wallet_entries', ['id' => uuid(), 'seller_id' => $l['seller_id'], 'kind' => 'refund', 'amount' => -((float) $l['net']), 'currency' => $currency,
                'order_item_id' => $l['id'], 'note' => 'Refund ' . $label, 'available_at' => $sale['available_at'], 'created_at' => now_db()]);
        }
        if ($restock) {
            if ($l['variation_id']) $db->exec('UPDATE product_variations SET stock = CASE WHEN stock + ? > 1000000 THEN 1000000 ELSE stock + ? END WHERE id = ?', [$l['quantity'], $l['quantity'], $l['variation_id']]);
            elseif ($l['product_id']) $db->exec('UPDATE products SET stock = CASE WHEN stock + ? > 1000000 THEN 1000000 ELSE stock + ? END WHERE id = ? AND manage_stock = 1', [$l['quantity'], $l['quantity'], $l['product_id']]);
            if ($l['product_id']) $db->exec('UPDATE products SET updated_at = ? WHERE id = ?', [now_db(), $l['product_id']]);
        }
        $sellers[$l['seller_id']] = true;
    }
    foreach ($owed as $oid => $amt) customer_refund_add($db, $oid, $currency, round2($amt));
}

// Reverses every line of an order: the credit is taken back (on the same availability date) and, if asked, the stock returns.
function record_refund(array $event): array
{
    $db = db();
    $o = $event['order'];
    $sellers = [];
    $found = $db->tx(function () use ($db, $o, &$sellers) {
        $order = $db->one('SELECT id, currency FROM orders WHERE marketplace_order_id = ?' . $db->lock(), [$o['id']]);
        if (!$order) return false;
        $lines = $db->all("SELECT id, seller_id, product_id, variation_id, quantity, net FROM order_items WHERE order_id = ? AND status = 'paid'" . $db->lock(), [$order['id']]);
        refund_lines($db, $order['currency'], $lines, $o['number'] ?: $o['id'], (bool) $o['restock'], $sellers);
        return true;
    });
    foreach (array_keys($sellers) as $sid) {
        announce('seller', 'refund', 'Order ' . ($o['number'] ?: $o['id']) . ' was refunded', $sid);
        notify($sid, 'refund', 'Order ' . ($o['number'] ?: $o['id']) . ' was refunded or cancelled', 'Your earnings for it were taken back.', '/seller?tab=sales');
    }
    return ['found' => $found];
}

// ---------------------------------------------------------------- bank accounts and withdrawals

function bank_accounts(string $sellerId): array
{
    return db()->all('SELECT id, bank_name, account_number, account_name, bank_code FROM bank_accounts WHERE seller_id = ? ORDER BY created_at', [$sellerId]);
}

function add_bank_account(string $sellerId, string $bank, string $number, string $name): ?string
{
    $bank = trim($bank);
    $name = trim($name);
    $number = trim($number);
    if (mb_strlen($bank) < 2 || mb_strlen($bank) > 100) return 'Enter the bank name.';
    if (!preg_match('/^[0-9]{10}$/', $number)) return 'The account number must be 10 digits.';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) return 'Enter the account name.';
    // With Paystack on, the bank must be one it knows, and the bank tells us the real account name.
    $code = null;
    if (paystack_on() && paystack_banks()) {
        $code = bank_code_for($bank);
        if (!$code) return 'Choose your bank from the list.';
        $bank = paystack_banks()[$code];
        $real = paystack_resolve_account($number, $code);
        if ($real !== null) $name = $real;
    }
    $db = db();
    return $db->tx(function () use ($db, $sellerId, $bank, $number, $name, $code) {
        $db->val('SELECT id FROM users WHERE id = ?' . $db->lock(), [$sellerId]);
        if ((int) $db->val('SELECT COUNT(*) FROM bank_accounts WHERE seller_id = ?', [$sellerId]) >= MAX_BANK_ACCOUNTS) return 'You can save up to ' . MAX_BANK_ACCOUNTS . ' bank accounts. Remove one first.';
        try {
            $db->insert('bank_accounts', ['id' => uuid(), 'seller_id' => $sellerId, 'bank_name' => $bank, 'account_number' => $number, 'account_name' => $name, 'bank_code' => $code, 'created_at' => now_db()]);
        } catch (Throwable $e) {
            if (Db::isDuplicate($e)) return 'This account is already saved.';
            throw $e;
        }
        return null;
    });
}

function remove_bank_account(string $sellerId, string $id): void
{
    db()->exec('DELETE FROM bank_accounts WHERE id = ? AND seller_id = ?', [$id, $sellerId]);
}

function seller_withdrawals(string $sellerId): array
{
    return db()->all('SELECT * FROM withdrawals WHERE seller_id = ? ORDER BY requested_at DESC LIMIT 100', [$sellerId]);
}

// The amount leaves the wallet at once, so it cannot be spent twice.
function request_withdrawal(string $sellerId, string $accountId, string $currency, float $amount): ?string
{
    $amount = round2($amount);
    $fee = withdrawal_fee();
    if (!in_array($currency, CURRENCIES, true)) return 'Choose a currency.';
    if ($amount < min_withdrawal()) return 'The smallest withdrawal is ' . number_format(min_withdrawal(), 2) . '.';
    if ($fee >= $amount) return 'The amount must be more than the withdrawal fee.';
    $db = db();
    $err = $db->tx(function () use ($db, $sellerId, $accountId, $currency, $amount, $fee) {
        $db->val('SELECT id FROM users WHERE id = ?' . $db->lock(), [$sellerId]); // one request at a time per seller
        $bank = $db->one('SELECT bank_name, account_number, account_name FROM bank_accounts WHERE id = ? AND seller_id = ?', [$accountId, $sellerId]);
        if (!$bank) return 'Choose one of your saved bank accounts.';
        $avail = 0.0;
        foreach (wallet_balances($sellerId) as $b) if ($b['currency'] === $currency) $avail = $b['available'];
        if ((int) round($avail * 100) < (int) round($amount * 100)) return 'That is more than your available balance.';
        if ((int) $db->val("SELECT COUNT(*) FROM withdrawals WHERE seller_id = ? AND status = 'requested'", [$sellerId]) >= 5) return 'You have several requests waiting. Wait for them to be paid first.';
        $wid = uuid();
        $now = now_db();
        $db->insert('withdrawals', ['id' => $wid, 'seller_id' => $sellerId, 'amount' => $amount, 'fee' => $fee, 'currency' => $currency, 'bank_name' => $bank['bank_name'],
            'account_number' => $bank['account_number'], 'account_name' => $bank['account_name'], 'status' => 'requested', 'requested_at' => $now]);
        $db->insert('wallet_entries', ['id' => uuid(), 'seller_id' => $sellerId, 'kind' => 'withdrawal', 'amount' => -$amount, 'currency' => $currency, 'withdrawal_id' => $wid,
            'note' => 'Withdrawal request', 'available_at' => $now, 'created_at' => $now]);
        return null;
    });
    if ($err === null) announce('admins', 'payout', 'A withdrawal is waiting to be paid');
    return $err;
}

// Puts a request's money back (cancel or reject). Once per withdrawal.
function give_back_withdrawal(string $withdrawalId, string $note): void
{
    $db = db();
    $w = $db->one('SELECT seller_id, amount, currency FROM withdrawals WHERE id = ?', [$withdrawalId]);
    if (!$w || $db->val("SELECT 1 FROM wallet_entries WHERE withdrawal_id = ? AND kind = 'adjustment'", [$withdrawalId])) return;
    $now = now_db();
    $db->insert('wallet_entries', ['id' => uuid(), 'seller_id' => $w['seller_id'], 'kind' => 'adjustment', 'amount' => (float) $w['amount'], 'currency' => $w['currency'], 'withdrawal_id' => $withdrawalId, 'note' => $note, 'available_at' => $now, 'created_at' => $now]);
}

function cancel_withdrawal(string $sellerId, string $id): ?string
{
    $db = db();
    return $db->tx(function () use ($db, $sellerId, $id) {
        $n = $db->exec("UPDATE withdrawals SET status = 'cancelled', decided_at = ? WHERE id = ? AND seller_id = ? AND status = 'requested'", [now_db(), $id, $sellerId]);
        if (!$n) return 'This request can no longer be cancelled.';
        give_back_withdrawal($id, 'Withdrawal cancelled');
        return null;
    });
}

function withdrawal_review_list(): array
{
    $rows = db()->all("SELECT w.*, u.email AS seller_email, a.brand AS brand_json FROM withdrawals w JOIN users u ON u.id = w.seller_id LEFT JOIN seller_applications a ON a.user_id = w.seller_id ORDER BY (w.status = 'requested') DESC, w.requested_at DESC LIMIT 200");
    foreach ($rows as &$r) {
        $b = jdec($r['brand_json'] ?? '', []);
        $r['store_name'] = trim((string) ($b['store_name'] ?? '')) ?: $r['seller_email'];
        unset($r['brand_json']);
    }
    return $rows;
}

function decide_withdrawal(array $admin, string $id, string $decision, string $text): ?string
{
    $text = trim($text);
    if ($decision === 'paid' && $text === '') return 'Enter the transfer reference you paid with.';
    if ($decision === 'rejected' && $text === '') return 'Tell the seller why it was rejected.';
    if (!in_array($decision, ['paid', 'rejected'], true)) return 'Unknown decision.';
    $db = db();
    $seller = null;
    $err = $db->tx(function () use ($db, $admin, $id, $decision, $text, &$seller) {
        $w = $db->one("SELECT seller_id FROM withdrawals WHERE id = ? AND status = 'requested'" . $db->lock(), [$id]);
        if (!$w) return 'This request has already been decided.';
        $db->exec('UPDATE withdrawals SET status = ?, reference = ?, admin_note = ?, decided_by = ?, decided_at = ? WHERE id = ?',
            [$decision, $decision === 'paid' ? $text : null, $decision === 'rejected' ? $text : null, $admin['id'], now_db(), $id]);
        if ($decision === 'rejected') give_back_withdrawal($id, 'Withdrawal rejected');
        $seller = $w['seller_id'];
        return null;
    });
    if ($err === null && $seller) {
        $msg = $decision === 'paid' ? 'Your withdrawal has been paid' : 'Your withdrawal was rejected and refunded to your wallet';
        announce('seller', 'payout', $msg, $seller);
        notify($seller, 'payout', $msg, $decision === 'paid' ? 'Reference: ' . $text : trim($text), '/seller?tab=payouts');
    }
    return $err;
}

// ---------------------------------------------------------------- store profile

function store_profile(string $sellerId): array
{
    $r = db()->one('SELECT * FROM store_profiles WHERE seller_id = ?', [$sellerId]);
    $d = ['tagline' => '', 'description' => '', 'phone' => '', 'support_email' => '', 'return_policy' => '', 'shipping_policy' => '', 'logo' => null, 'banner' => null, 'vacation' => false, 'vacation_message' => ''];
    if ($r) {
        foreach ($d as $k => $_) if (array_key_exists($k, $r)) $d[$k] = $r[$k];
        $d['vacation'] = (int) $r['vacation'] === 1;
    }
    return $d;
}

// Changing anything the marketplace shows (or vacation mode) makes the seller's products sync again.
function touch_seller_products(string $sellerId): void
{
    db()->exec('UPDATE products SET updated_at = ? WHERE seller_id = ? AND published_snapshot IS NOT NULL', [now_db(), $sellerId]);
}

function save_store_profile(string $sellerId, array $f): ?string
{
    if ($f['support_email'] !== '' && !filter_var($f['support_email'], FILTER_VALIDATE_EMAIL)) return 'Enter a valid support email.';
    $db = db();
    $cols = ['tagline' => str_limit($f['tagline'], 160), 'description' => str_limit($f['description'], 3000), 'phone' => str_limit($f['phone'], 30), 'support_email' => str_limit($f['support_email'], 255),
        'return_policy' => str_limit($f['return_policy'], 3000), 'shipping_policy' => str_limit($f['shipping_policy'], 3000), 'vacation' => $f['vacation'] ? 1 : 0,
        'vacation_message' => str_limit($f['vacation_message'], 300), 'updated_at' => now_db()];
    $db->tx(function () use ($db, $sellerId, $cols) {
        $n = $db->exec('UPDATE store_profiles SET ' . implode(', ', array_map(fn ($c) => $c . ' = ?', array_keys($cols))) . ' WHERE seller_id = ?', array_merge(array_values($cols), [$sellerId]));
        if (!$n && !$db->val('SELECT 1 FROM store_profiles WHERE seller_id = ?', [$sellerId])) $db->insert('store_profiles', $cols + ['seller_id' => $sellerId]);
    });
    touch_seller_products($sellerId);
    return null;
}

function set_store_image(string $sellerId, string $kind, ?array $upload): ?string
{
    if (!in_array($kind, ['logo', 'banner'], true)) return 'Unknown image.';
    $db = db();
    $old = store_profile($sellerId)[$kind];
    $path = null;
    if ($upload !== null) {
        $got = Storage::fromUpload($upload, Storage::IMAGE_TYPES, PRODUCT_IMAGE_MAX);
        if (is_string($got)) return $got;
        [$bytes, $mime] = $got;
        $path = $sellerId . '/' . uuid() . '.' . Storage::EXT[$mime];
        Storage::save('stores', $path, Storage::cleanImage($bytes, $mime, 1800));
    }
    $db->tx(function () use ($db, $sellerId, $kind, $path) {
        if (!$db->val('SELECT 1 FROM store_profiles WHERE seller_id = ?', [$sellerId])) {
            $db->insert('store_profiles', ['seller_id' => $sellerId, 'description' => '', 'return_policy' => '', 'shipping_policy' => '', 'updated_at' => now_db()]);
        }
        $db->exec('UPDATE store_profiles SET ' . $kind . ' = ?, updated_at = ? WHERE seller_id = ?', [$path, now_db(), $sellerId]);
    });
    if ($old && preg_match(Storage::storeRe(), $old)) Storage::remove('stores', $old);
    touch_seller_products($sellerId);
    return null;
}
