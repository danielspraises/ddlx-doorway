<?php
declare(strict_types=1);

// Sales from the marketplace, the seller wallet, bank accounts, withdrawals and the store profile.

const MAX_BANK_ACCOUNTS = 3;
const WITHDRAWAL_LABEL = ['requested' => 'Waiting to be paid', 'paid' => 'Paid', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

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
    return db()->all('SELECT i.id, o.number AS order_number, o.placed_at, i.name, i.sku, i.options, i.quantity, i.unit_price, i.line_total, i.commission, i.net, o.currency, i.status, o.customer_name, o.ship_city, o.ship_state FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.seller_id = ? ORDER BY o.placed_at DESC, i.created_at DESC LIMIT ' . (int) $limit, [$sellerId]);
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
        $items[] = ['product_id' => $it['product_id'], 'variation_id' => $vid, 'quantity' => $q, 'unit_price' => (float) $p];
    }
    if (count($items) > 200) return 'Too many items.';
    return ['event' => $d['event'], 'order' => [
        'id' => $id, 'number' => str_limit((string) ($o['number'] ?? ''), 100), 'currency' => $o['currency'], 'placed_at' => $placed,
        'customer_name' => str_limit((string) ($o['customer_name'] ?? ''), 200), 'ship_city' => str_limit((string) ($o['ship_city'] ?? ''), 100),
        'ship_state' => str_limit((string) ($o['ship_state'] ?? ''), 100), 'restock' => !empty($o['restock']), 'items' => $items,
    ]];
}

// One line per product with the seller's commission, the wallet credit and the stock taken off.
// Safe to repeat: an order id that was already recorded changes nothing.
function record_paid_order(array $event): array
{
    $db = db();
    $o = $event['order'];
    if (!$o['items']) throw new OrderRejected('The order has no items.');
    $rate = commission_percent();
    $hold = hold_days();
    $sellers = [];
    $result = $db->tx(function () use ($db, $o, $rate, $hold, &$sellers) {
        if ($db->val('SELECT id FROM orders WHERE marketplace_order_id = ?', [$o['id']])) return ['duplicate' => true];
        $orderId = uuid();
        try {
            $db->insert('orders', ['id' => $orderId, 'marketplace_order_id' => $o['id'], 'number' => $o['number'], 'currency' => $o['currency'], 'customer_name' => $o['customer_name'],
                'ship_city' => $o['ship_city'], 'ship_state' => $o['ship_state'], 'placed_at' => $o['placed_at'], 'created_at' => now_db()]);
        } catch (Throwable $e) {
            if (Db::isDuplicate($e)) return ['duplicate' => true];
            throw $e;
        }
        foreach ($o['items'] as $it) {
            $p = $db->one('SELECT seller_id, name, sku, manage_stock, product_type FROM products WHERE id = ?' . $db->lock(), [$it['product_id']]);
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
            $line = round2($it['unit_price'] * $it['quantity']);
            $commission = round2($line * $rate / 100);
            $net = round2($line - $commission);
            $itemId = uuid();
            $db->insert('order_items', ['id' => $itemId, 'order_id' => $orderId, 'seller_id' => $p['seller_id'], 'product_id' => $it['product_id'], 'variation_id' => $it['variation_id'],
                'name' => $p['name'], 'sku' => $sku, 'options' => jenc((object) $options), 'quantity' => $it['quantity'], 'unit_price' => round2($it['unit_price']), 'line_total' => $line,
                'commission_rate' => $rate, 'commission' => $commission, 'net' => $net, 'status' => 'paid', 'created_at' => now_db()]);
            $db->insert('wallet_entries', ['id' => uuid(), 'seller_id' => $p['seller_id'], 'kind' => 'sale', 'amount' => $net, 'currency' => $o['currency'], 'order_item_id' => $itemId,
                'note' => 'Order ' . ($o['number'] ?: $o['id']), 'available_at' => db_plus('+' . $hold . ' days', $o['placed_at']), 'created_at' => now_db()]);
            if ($it['variation_id']) {
                $db->exec('UPDATE product_variations SET stock = CASE WHEN stock > ? THEN stock - ? ELSE 0 END WHERE id = ?', [$it['quantity'], $it['quantity'], $it['variation_id']]);
            } elseif ((int) $p['manage_stock'] === 1) {
                $db->exec('UPDATE products SET stock = CASE WHEN stock > ? THEN stock - ? ELSE 0 END WHERE id = ?', [$it['quantity'], $it['quantity'], $it['product_id']]);
            }
            $db->exec('UPDATE products SET updated_at = ? WHERE id = ?', [now_db(), $it['product_id']]);
            $sellers[$p['seller_id']] = round2(($sellers[$p['seller_id']] ?? 0) + $net);
        }
        return ['duplicate' => false];
    });
    if (!$result['duplicate']) {
        foreach ($sellers as $sid => $net) announce('seller', 'sale', 'New sale: ' . $o['currency'] . ' ' . number_format($net, 2) . ' earned', $sid);
    }
    return $result;
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
        foreach ($lines as $l) {
            $db->exec("UPDATE order_items SET status = 'refunded' WHERE id = ?", [$l['id']]);
            $sale = $db->one("SELECT available_at FROM wallet_entries WHERE order_item_id = ? AND kind = 'sale'", [$l['id']]);
            if ($sale && !$db->val("SELECT 1 FROM wallet_entries WHERE order_item_id = ? AND kind = 'refund'", [$l['id']])) {
                $db->insert('wallet_entries', ['id' => uuid(), 'seller_id' => $l['seller_id'], 'kind' => 'refund', 'amount' => -((float) $l['net']), 'currency' => $order['currency'],
                    'order_item_id' => $l['id'], 'note' => 'Refund ' . ($o['number'] ?: $o['id']), 'available_at' => $sale['available_at'], 'created_at' => now_db()]);
            }
            if ($o['restock']) {
                if ($l['variation_id']) $db->exec('UPDATE product_variations SET stock = CASE WHEN stock + ? > 1000000 THEN 1000000 ELSE stock + ? END WHERE id = ?', [$l['quantity'], $l['quantity'], $l['variation_id']]);
                elseif ($l['product_id']) $db->exec('UPDATE products SET stock = CASE WHEN stock + ? > 1000000 THEN 1000000 ELSE stock + ? END WHERE id = ? AND manage_stock = 1', [$l['quantity'], $l['quantity'], $l['product_id']]);
                if ($l['product_id']) $db->exec('UPDATE products SET updated_at = ? WHERE id = ?', [now_db(), $l['product_id']]);
            }
            $sellers[$l['seller_id']] = true;
        }
        return true;
    });
    foreach (array_keys($sellers) as $sid) announce('seller', 'refund', 'Order ' . ($o['number'] ?: $o['id']) . ' was refunded', $sid);
    return ['found' => $found];
}

// ---------------------------------------------------------------- bank accounts and withdrawals

function bank_accounts(string $sellerId): array
{
    return db()->all('SELECT id, bank_name, account_number, account_name FROM bank_accounts WHERE seller_id = ? ORDER BY created_at', [$sellerId]);
}

function add_bank_account(string $sellerId, string $bank, string $number, string $name): ?string
{
    $bank = trim($bank);
    $name = trim($name);
    $number = trim($number);
    if (mb_strlen($bank) < 2 || mb_strlen($bank) > 100) return 'Enter the bank name.';
    if (!preg_match('/^[0-9]{10}$/', $number)) return 'The account number must be 10 digits.';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) return 'Enter the account name.';
    $db = db();
    return $db->tx(function () use ($db, $sellerId, $bank, $number, $name) {
        $db->val('SELECT id FROM users WHERE id = ?' . $db->lock(), [$sellerId]);
        if ((int) $db->val('SELECT COUNT(*) FROM bank_accounts WHERE seller_id = ?', [$sellerId]) >= MAX_BANK_ACCOUNTS) return 'You can save up to ' . MAX_BANK_ACCOUNTS . ' bank accounts. Remove one first.';
        try {
            $db->insert('bank_accounts', ['id' => uuid(), 'seller_id' => $sellerId, 'bank_name' => $bank, 'account_number' => $number, 'account_name' => $name, 'created_at' => now_db()]);
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
    if ($err === null && $seller) announce('seller', 'payout', $decision === 'paid' ? 'Your withdrawal has been paid' : 'Your withdrawal was rejected and refunded to your wallet', $seller);
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
