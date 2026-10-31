<?php
declare(strict_types=1);
// Self-tests on SQLite:  php tests/run.php   (production uses MySQL/MariaDB; the SQL is kept portable)
define('VH_TESTING', true);
require __DIR__ . '/../app/bootstrap.php';

$tmp = sys_get_temp_dir() . '/vh-test-' . getmypid();
if (is_dir($tmp)) { foreach (glob($tmp . '/*') ?: [] as $f) is_file($f) && unlink($f); }
@mkdir($tmp, 0775, true);
$GLOBALS['vh_config'] = ['db' => ['driver' => 'sqlite', 'path' => $tmp . '/t.db'], 'storage_dir' => $tmp . '/storage'];
run_schema(db());
$fail = 0; $pass = 0;
function ok(bool $cond, string $msg): void { global $fail, $pass; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $msg\n"; } }
function eq(mixed $a, mixed $b, string $msg): void { ok($a === $b, $msg . ' (got ' . var_export($a, true) . ', want ' . var_export($b, true) . ')'); }

// ---- categories
$n = seed_categories(db());
ok($n > 100, "seeded $n categories");
$all = categories_all(true);
$fashion = null; foreach ($all as $c) if ($c['name'] === 'Fashion' && !$c['parent_id']) $fashion = $c;
ok($fashion !== null, 'Fashion exists');
eq(category_create($fashion['id'], 'Fashion Test'), null, 'create sub');
ok(category_create($fashion['id'], 'fashion test') !== null, 'sibling name clash is case-insensitive');
$leaf = null; foreach (category_flat() as $row) if (count(category_chain($row['category']['id'])) === 4) { $leaf = $row['category']; break; }
ok($leaf !== null, 'a 4-level leaf exists');
ok(category_create($leaf['id'], 'Too deep') !== null, 'depth limit enforced');
$men = null; foreach ($all as $c) if ($c['name'] === 'Men' && $c['parent_id'] === $fashion['id']) $men = $c;
ok(category_move($fashion['id'], $men['id']) !== null, 'cannot move into own child');
ok(category_delete($fashion['id']) !== null, 'cannot delete with children');

// ---- users and application
$sid = uuid();
db()->insert('users', ['id' => $sid, 'email' => 's@x.test', 'password_hash' => 'x', 'created_at' => now_db()]);
$p1 = ['first_name' => 'A', 'last_name' => 'B', 'other_names' => '', 'email' => 's@x.test', 'phone' => '08031234567', 'address' => 'x', 'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria', 'role' => 'Owner', 'id_type' => 'International Passport', 'id_number' => '123'];
eq(validate_section(1, $p1), [], 'personal valid');
ok(isset(validate_section(1, ['phone' => '123'] + $p1)['phone']), 'bad phone rejected');
$p2 = ['name' => 'Demo', 'type' => 'Partnership', 'type_other' => '', 'category' => 'Clothing', 'category_other' => '', 'phone' => '', 'email' => '', 'address' => 'x', 'city' => 'L', 'state' => 'Lagos', 'country' => 'Nigeria', 'website' => '', 'years' => '2', 'employees' => '', 'description' => 'd'];
$p3 = ['store_name' => 'Demo Store', 'instagram' => '@a', 'facebook' => 'fb', 'owns_brand' => 'no', 'brand_name' => '', 'brand_category' => '', 'brand_category_other' => '', 'brand_description' => '', 'tiktok' => '', 'x' => '', 'whatsapp' => '', 'snapchat' => '', 'other' => ''];
$p4 = ['registered' => 'no', 'reg_number' => '', 'reg_name' => ''];
foreach ([[2, $p2], [3, $p3], [4, $p4]] as [$st, $dd]) eq(validate_section($st, $dd), [], "step $st valid");
foreach ([[1, $p1], [2, $p2], [3, $p3], [4, $p4]] as [$st, $dd]) { $r = save_application($sid, $st, $dd, false); ok($r['ok'], "save step $st"); }
$r = save_application($sid, 5, [], true, ['0' => 'yes', '1' => 'yes', '2' => 'yes']);
ok(!$r['ok'], 'submit needs all declarations');
$r = save_application($sid, 5, [], true, ['0' => 'yes', '1' => 'yes', '2' => 'yes', '3' => 'yes']);
ok($r['ok'], 'submit ok');
eq(db()->val('SELECT status FROM seller_applications WHERE user_id = ?', [$sid]), 'submitted', 'status submitted');
ok(!save_application($sid, 1, $p1, false)['ok'], 'submitted application not editable');
db()->exec("UPDATE seller_applications SET status = 'approved' WHERE user_id = ?", [$sid]);
$ver = load_verification($sid);
ok($ver !== null && $ver['status'] === 'draft', 'verification created after approval');
eq(sku_prefix($sid), 'DEMO', 'sku prefix');
eq(local_next_sku('DEMO'), 'DEMO-0001', 'first sku');

// ---- products
$cat = $leaf['id'];
$base = ['id' => null, 'name' => 'Ankara Shirt', 'sku' => 'DEMO-0001', 'product_type' => 'simple', 'brand' => '', 'category_id' => $cat, 'tags' => ['ankara'], 'short_description' => 's', 'description' => 'd', 'currency' => 'NGN',
    'regular_price' => 10000.0, 'sale_price' => 8000.0, 'sale_starts_at' => null, 'sale_ends_at' => null, 'manage_stock' => true, 'stock' => 10, 'low_stock_threshold' => 3, 'allow_backorders' => false,
    'weight_kg' => 0.5, 'length_cm' => 30.0, 'width_cm' => 20.0, 'height_cm' => 5.0, 'attributes' => [], 'variations' => []];
$r = save_product($sid, $base);
ok($r['ok'], 'save simple product: ' . ($r['error'] ?? ''));
$pid = $r['id'];
$other = uuid(); db()->insert('users', ['id' => $other, 'email' => 'o@x.test', 'password_hash' => 'x', 'created_at' => now_db()]);
ok(!save_product($other, ['sku' => 'demo-0001'] + $base)['ok'], 'SKU unique across sellers (case-insensitive)');
ok(!save_product($sid, ['sale_price' => 12000.0] + $base + ['id' => $pid])['ok'], 'sale price must be lower');
eq(submit_product($pid, $sid), 'Add a featured image.', 'needs featured image');
db()->exec('UPDATE products SET featured_image = ? WHERE id = ?', [$sid . '/' . $pid . '/' . uuid() . '.jpg', $pid]);
eq(submit_product($pid, $sid), null, 'submit ok');
eq(db()->val('SELECT status FROM products WHERE id = ?', [$pid]), 'pending_review', 'pending');
ok(!save_product($sid, ['id' => $pid] + $base)['ok'], 'cannot edit while in review');
$admin = ['id' => uuid(), 'isAdmin' => true];
$prod = load_product($pid);
eq(review_product($admin, $pid, 'approve', '', 'wrong'), 'This product changed while you were reviewing it. Reopen it and review again.', 'stale review blocked');
eq(review_product($admin, $pid, 'approve', '', $prod['updated_raw']), null, 'approve');
$prod = load_product($pid);
ok($prod['live'] && $prod['status'] === 'approved' && $prod['has_snapshot'], 'live after approval');
eq(review_product($admin, $pid, 'reject', 'no', $prod['updated_raw']), 'This product is not waiting for review.', 'decided review locked');
// editing an approved product -> draft, still live
$r = save_product($sid, ['id' => $pid, 'name' => 'Ankara Shirt v2'] + $base);
ok($r['ok'], 'edit approved');
$prod = load_product($pid);
ok($prod['status'] === 'draft' && $prod['live'], 'edit keeps live, status draft');
eq(adjust_stock($pid, $sid, null, 'delta', -4), null, 'stock delta');
eq(load_product($pid)['stock'], 6, 'stock 6');
eq(adjust_stock($pid, $sid, null, 'delta', -100), null, 'stock floor');
eq(load_product($pid)['stock'], 0, 'stock not below zero');

// variable product
$attrs = parse_attributes("Size: S, M\nColour: Red");
$combos = combinations($attrs);
eq(count($combos), 2, 'two combos');
$vars = [];
foreach ($combos as $i => $o) $vars[] = ['id' => null, 'sku' => 'DEMO-V' . $i, 'options' => $o, 'regular_price' => 5000.0, 'sale_price' => null, 'sale_starts_at' => null, 'sale_ends_at' => null, 'stock' => 5, 'enabled' => true];
$var = ['name' => 'Hoodie', 'sku' => 'DEMO-0002', 'product_type' => 'variable', 'attributes' => $attrs, 'variations' => $vars, 'id' => null] + $base;
$r = save_product($sid, $var);
ok($r['ok'], 'variable saved: ' . ($r['error'] ?? ''));
$vp = load_product($r['id']);
eq(count($vp['variations']), 2, 'variations stored');
// swap SKUs between variations
$swap = $vp['variations'];
[$swap[0]['sku'], $swap[1]['sku']] = [$swap[1]['sku'], $swap[0]['sku']];
$r2 = save_product($sid, ['id' => $vp['id'], 'variations' => $swap] + $var);
ok($r2['ok'], 'SKU swap works: ' . ($r2['error'] ?? ''));

// ---- sales, wallet, refunds
$evRaw = ['event' => 'order.paid', 'order' => ['id' => 'WC-1', 'number' => '1001', 'currency' => 'NGN', 'placed_at' => gmdate('c', time() - 10 * 86400), 'customer_name' => 'Ada', 'ship_city' => 'Lagos', 'ship_state' => 'Lagos',
    'items' => [['product_id' => $pid, 'quantity' => 1, 'unit_price' => 10000], ['product_id' => $vp['id'], 'variation_id' => $vp['variations'][0]['id'], 'quantity' => 2, 'unit_price' => 5000]]]];
ok(is_array(parse_order_event($evRaw)), 'order parses');
ok(is_string(parse_order_event(['event' => 'x'])), 'bad event rejected');
db()->exec('UPDATE products SET stock = 10 WHERE id = ?', [$pid]);
$ev = parse_order_event($evRaw);
eq(record_paid_order($ev)['duplicate'], false, 'order recorded');
eq(record_paid_order($ev)['duplicate'], true, 'order idempotent');
$bal = wallet_balances($sid)[0];
eq($bal["total"], 18000.0, "wallet total (20000 less 10 percent)");
eq(load_product($pid)['stock'], 9, 'stock reduced by sale');
eq(load_product($vp['id'])['variations'][0]['stock'], 3, 'variation stock reduced');
eq(count(seller_sales($sid)), 2, 'two sale lines');
// money stays pending until the order is delivered
eq($bal['available'], 0.0, 'nothing withdrawable before delivery');
eq($bal['pending'], 18000.0, 'earnings pending before delivery');

// ---- fulfilment (shared with the marketplace)
$itemId = (string) db()->val('SELECT id FROM order_items WHERE seller_id = ? ORDER BY created_at LIMIT 1', [$sid]);
$mk = (string) db()->val('SELECT o.marketplace_order_id FROM orders o JOIN order_items i ON i.order_id = o.id WHERE i.id = ?', [$itemId]);
eq(db()->val('SELECT fulfilment FROM order_items WHERE id = ?', [$itemId]), 'placed', 'new order is placed');
ok(seller_set_fulfilment('other-seller', $itemId, 'processing') !== null, 'other seller cannot move it');
ok(seller_set_fulfilment($sid, $itemId, 'shipped') !== null, 'seller cannot ship');
$pushed = [];
$GLOBALS['vh_market_stub'] = function ($path, $method, $body) use (&$pushed) { $pushed[] = [$path, $body]; return ['ok' => true]; };
set_setting('marketplace_url', 'https://shop.test'); set_setting('marketplace_api_key', 'k');
eq(seller_set_fulfilment($sid, $itemId, 'processing'), null, 'processing');
eq(count($pushed), 1, 'marketplace told about processing');
eq(seller_set_fulfilment($sid, $itemId, 'ready_for_shipping'), null, 'ready for shipping');
eq($pushed[1][1]['status'], 'ready_for_shipping', 'marketplace told ready_for_shipping');
eq($pushed[1][1]['order_id'], $mk, 'marketplace told which order');
ok(seller_set_fulfilment($sid, $itemId, 'processing') !== null, 'cannot go backwards');
ok(seller_set_fulfilment($sid, $itemId, 'ready_for_shipping') !== null, 'cannot repeat');
$GLOBALS['vh_market_stub'] = fn () => null;
$other = (string) db()->val("SELECT id FROM order_items WHERE seller_id = ? AND fulfilment = 'placed' LIMIT 1", [$sid]);
if ($other) { ok(seller_set_fulfilment($sid, $other, 'processing') !== null, 'marketplace unreachable: refused'); eq(db()->val('SELECT fulfilment FROM order_items WHERE id = ?', [$other]), 'placed', 'unreachable: nothing changed'); }
unset($GLOBALS['vh_market_stub']); set_setting('marketplace_url', ''); set_setting('marketplace_api_key', '');
ok(is_string(parse_status_event(['order' => ['id' => $mk, 'status' => 'bogus']])), 'bad status rejected');
ok(is_string(parse_status_event(['order' => ['id' => $mk, 'status' => 'processing']])), 'processing is not a marketplace status');
eq(apply_marketplace_status(['id' => 'nope', 'status' => 'shipped', 'carrier' => '', 'tracking' => '', 'note' => '']), false, 'unknown order');
$st = fn ($s, $c = '', $t = '') => parse_status_event(['order' => ['id' => $mk, 'status' => $s, 'carrier' => $c, 'tracking_number' => $t]]);
ok(apply_marketplace_status($st('shipped', 'DHL', 'TRK1')), 'shipped applied');
eq(db()->val('SELECT tracking_number FROM order_items WHERE id = ?', [$itemId]), 'TRK1', 'tracking stored');
apply_marketplace_status($st('ready_for_shipping'));
eq(db()->val('SELECT fulfilment FROM order_items WHERE id = ?', [$itemId]), 'shipped', 'status never goes backwards');
set_setting('payout_hold_days', '7');
apply_marketplace_status($st('delivered'));
eq(wallet_balances($sid)[0]['available'], 0.0, 'still held for the hold period after delivery');
db()->exec("UPDATE wallet_entries SET available_at = ? WHERE available_at > ?", [db_plus('-1 days'), db_plus('+1 days')]);
eq(wallet_balances($sid)[0]['available'], 18000.0, 'withdrawable once the hold has passed');
apply_marketplace_status($st('completed'));
eq(db()->val('SELECT fulfilment FROM order_items WHERE id = ?', [$itemId]), 'completed', 'completed');
ok(count(item_history([$itemId])[$itemId]) >= 5, 'history recorded');

// ---- notifications
$unread = notifications_unread($sid);
ok($unread >= 3, "seller has notifications for sale/status/product ($unread)");
$titles = array_column(notifications_list($sid), 'title');
ok(count(array_filter($titles, fn ($t) => str_starts_with($t, 'New order'))) >= 1, 'new order notification');
ok(count(array_filter($titles, fn ($t) => str_contains($t, 'is now shipped'))) >= 1, 'shipped notification');
ok(count(array_filter($GLOBALS['vh_mail_outbox'] ?? [], fn ($m) => $m['to'] === 's@x.test' && str_starts_with($m['subject'], 'New order'))) >= 1, 'new order email sent');
$mailsBefore = count($GLOBALS['vh_mail_outbox'] ?? []);
db()->exec('UPDATE users SET email_alerts = 0 WHERE id = ?', [$sid]);
notify($sid, 'test', 'Quiet one');
eq(count($GLOBALS['vh_mail_outbox'] ?? []), $mailsBefore, 'no email when alerts are off');
db()->exec('UPDATE users SET email_alerts = 1 WHERE id = ?', [$sid]);
notifications_mark_read($sid);
eq(notifications_unread($sid), 0, 'mark all read');
notify($other, 'test', 'Not yours');
notifications_mark_read($sid);
eq(notifications_unread($other), 1, 'cannot clear another user\'s inbox');

// ---- reports and exports
[$h, $rows] = export_seller_sales($sid, null, null);
ok(count($rows) >= 2 && count($rows[0]) === count($h), 'sales export has rows matching the header');
[$h, $rows] = export_seller_products($sid);
ok(count($rows) >= 1 && count($rows[0]) === count($h), 'products export');
[$h, $rows] = export_seller_payouts($sid);
ok(count($rows) >= 1 && count($rows[0]) === count($h), 'payouts export');
$ch = seller_daily_earnings($sid);
eq(count($ch['series']), 30, '30 days charted');
ok(array_sum($ch['series']) > 0, 'earnings charted');
ok(str_contains(bar_chart($ch['series'], $ch['currency']), '<svg'), 'chart renders');
ok(str_contains(bar_chart(array_fill_keys(array_keys($ch['series']), 0.0)), 'No sales'), 'empty chart message');
$ov = admin_overview();
ok(count($ov['sales']) >= 1 && $ov['sales'][0]['gross'] > 0, 'overview sales');
eq(admin_overview()['live_products'] >= 1, true, 'overview live products');
[$f, $t] = range_from_input('2026-01-01', 'bad');
ok($f !== null && $t === null, 'date range parsing');
eq(csv_line(['=HYPERLINK("x")', '-5', 'ok', 'a,b']), "\"'=HYPERLINK(\"\"x\"\")\",\"-5\",\"ok\",\"a,b\"\r\n", 'csv defuses formulas, keeps numbers, quotes commas');

// ---- admin moves orders from Vendor Hub
$adm = $admin;
$ev2 = parse_order_event(['event' => 'order.paid', 'order' => ['id' => 'WC-2', 'number' => '1002', 'currency' => 'NGN', 'placed_at' => gmdate('c', time() - 86400), 'customer_name' => 'Bo',
    'items' => [['product_id' => $pid, 'quantity' => 1, 'unit_price' => 10000]]]]);
record_paid_order($ev2);
$o2 = (string) db()->val("SELECT id FROM orders WHERE marketplace_order_id = 'WC-2'");
$i2 = (string) db()->val('SELECT id FROM order_items WHERE order_id = ?', [$o2]);
eq(count(admin_orders('ready')), 0, 'new order not ready yet');
ok(admin_set_order_status($adm, $o2, 'delivered') !== null, 'cannot deliver before shipping');
ok(admin_set_order_status($adm, $o2, 'shipped', 'Nope', 'T') !== null, 'carrier must be known');
ok(admin_set_order_status($adm, $o2, 'shipped', 'DHL', '') !== null, 'tracking required');
$GLOBALS['vh_market_stub'] = fn () => null; set_setting('marketplace_url', 'https://shop.test'); set_setting('marketplace_api_key', 'k');
ok(admin_set_order_status($adm, $o2, 'shipped', 'DHL', 'ADM1') !== null, 'unreachable marketplace: nothing changes');
eq(db()->val('SELECT fulfilment FROM order_items WHERE id = ?', [$i2]), 'placed', 'still placed');
$sent = [];
$GLOBALS['vh_market_stub'] = function ($path, $method, $body) use (&$sent) { $sent[] = $body; return ['ok' => true]; };
eq(seller_set_fulfilment($sid, $i2, 'ready_for_shipping'), null, 'seller packs it');
eq(count(admin_orders('ready')), 1, 'now ready for pickup');
eq(admin_set_order_status($adm, $o2, 'shipped', 'DHL', 'ADM1'), null, 'admin ships from the hub');
eq($sent[count($sent) - 1]['status'], 'shipped', 'marketplace told shipped');
eq($sent[count($sent) - 1]['tracking_number'], 'ADM1', 'marketplace given tracking');
eq(db()->val('SELECT tracking_number FROM order_items WHERE id = ?', [$i2]), 'ADM1', 'tracking stored locally');
ok(admin_set_order_status($adm, $o2, 'cancelled') !== null, 'shipped order cannot be cancelled');
eq(admin_set_order_status($adm, $o2, 'delivered'), null, 'delivered');
eq(admin_set_order_status($adm, $o2, 'refunded', '', '', true, 'damaged'), null, 'admin refunds from the hub');
eq($sent[count($sent) - 1]['status'], 'refunded', 'marketplace told refunded');
eq(db()->val('SELECT status FROM order_items WHERE id = ?', [$i2]), 'refunded', 'item refunded');
ok(admin_set_order_status($adm, $o2, 'refunded') !== null, 'cannot refund twice');
eq(admin_orders('done')[0]['state'] ?? '', 'refunded', 'listed as finished');
unset($GLOBALS['vh_market_stub']); set_setting('marketplace_url', ''); set_setting('marketplace_api_key', '');

// ---- returns and disputes
record_paid_order(parse_order_event(['event' => 'order.paid', 'order' => ['id' => 'WC-3', 'number' => '1003', 'currency' => 'NGN', 'placed_at' => gmdate('c'), 'customer_name' => 'Cy',
    'items' => [['product_id' => $pid, 'quantity' => 2, 'unit_price' => 8000]]]]));
$i3 = (string) db()->val("SELECT i.id FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.marketplace_order_id = 'WC-3'");
$stock0 = (int) load_product($pid)['stock'];
ok(open_return($adm, $i3, 'Nonsense', '') !== null, 'reason must be from the list');
eq(open_return($adm, $i3, 'Damaged or defective', 'Zip broken'), null, 'case opened');
ok(open_return($adm, $i3, 'Wrong item sent', '') !== null, 'one open case per item');
$case = (string) db()->val('SELECT id FROM return_cases WHERE order_item_id = ?', [$i3]);
ok(seller_respond_return($other, $case, 'accept', '') !== null, 'other seller cannot answer');
ok(seller_respond_return($sid, $case, 'dispute', '') !== null, 'dispute needs an explanation');
eq(seller_respond_return($sid, $case, 'dispute', 'Item was fine when packed'), null, 'seller disputes');
ok(seller_respond_return($sid, $case, 'accept', '') !== null, 'cannot answer twice');
ok(resolve_return($adm, $case, 'reject', false, '') !== null, 'rejecting needs a note');
$bal0 = wallet_balances($sid)[0]['total'];
eq(resolve_return($adm, $case, 'refund', true, 'Photos show a broken zip'), null, 'admin refunds');
eq(db()->val('SELECT status FROM order_items WHERE id = ?', [$i3]), 'refunded', 'item refunded');
eq(round($bal0 - wallet_balances($sid)[0]['total'], 2), 14400.0, 'earnings reversed');
eq((int) load_product($pid)['stock'], $stock0 + 2, 'stock restored');
ok(resolve_return($adm, $case, 'refund', false, '') !== null, 'closed case cannot be decided again');
eq(count(admin_returns('open')), 0, 'no open cases left');
eq(count(admin_returns('done')), 1, 'one closed case');
ok(count(seller_returns($sid)) === 1 && count(seller_returns($other)) === 0, 'sellers see only their cases');

// ---- withdrawals
eq(add_bank_account($sid, 'GTBank', '0123456789', 'A B'), null, 'bank added');
ok(add_bank_account($sid, 'GTBank', '0123456789', 'A B') !== null, 'duplicate bank rejected');
ok(add_bank_account($sid, 'GTBank', '12345', 'A B') !== null, 'bad account number rejected');
$acct = bank_accounts($sid)[0]['id'];
ok(request_withdrawal($sid, $acct, 'NGN', 500) !== null, 'below minimum');
ok(request_withdrawal($sid, $acct, 'NGN', 99999) !== null, 'above balance');
eq(request_withdrawal($sid, $acct, 'NGN', 5000), null, 'withdrawal requested');
eq(wallet_balances($sid)[0]['available'], 13000.0, 'balance reduced at once');
$w = seller_withdrawals($sid)[0];
eq(cancel_withdrawal($sid, $w['id']), null, 'cancel');
eq(wallet_balances($sid)[0]['available'], 18000.0, 'cancel returns money');
ok(cancel_withdrawal($sid, $w['id']) !== null, 'cannot cancel twice');
eq(request_withdrawal($sid, $acct, 'NGN', 4000), null, 'second request');
$w2 = seller_withdrawals($sid)[0];
ok(decide_withdrawal($admin, $w2['id'], 'paid', '') !== null, 'paid needs reference');
eq(decide_withdrawal($admin, $w2['id'], 'paid', 'TRF-1'), null, 'paid');
ok(decide_withdrawal($admin, $w2['id'], 'rejected', 'no') !== null, 'cannot decide twice');
eq(wallet_balances($sid)[0]['available'], 14000.0, 'paid keeps money out');
eq(request_withdrawal($sid, $acct, 'NGN', 3000), null, 'third request');
$w3 = seller_withdrawals($sid)[0];
eq(decide_withdrawal($admin, $w3['id'], 'rejected', 'bad account'), null, 'rejected');
eq(wallet_balances($sid)[0]['available'], 14000.0, 'rejected returns money');
$cnt = (int) db()->val("SELECT COUNT(*) FROM wallet_entries WHERE kind = 'adjustment'");
give_back_withdrawal($w3['id'], 'again');
eq((int) db()->val("SELECT COUNT(*) FROM wallet_entries WHERE kind = 'adjustment'"), $cnt, 'give-back is once only');

// ---- refund
$rf = parse_order_event(['event' => 'order.refunded', 'order' => $evRaw['order'] + ['restock' => true]]);
eq(record_refund($rf)['found'], true, 'refund found');
eq(wallet_balances($sid)[0]['total'], 18000.0 - 18000.0 - 4000.0, 'refund reverses sale credit');
eq(load_product($pid)['stock'], 10, 'restocked');
eq(record_refund($rf)['found'], true, 'refund repeatable');
eq(wallet_balances($sid)[0]['total'], -4000.0, 'refund not applied twice');
eq(record_refund(parse_order_event(['event' => 'order.refunded', 'order' => ['id' => 'nope'] + $evRaw['order']]))['found'], false, 'unknown order');

// ---- store profile and vacation
eq(save_store_profile($sid, ['tagline' => 't', 'description' => 'd', 'phone' => '1', 'support_email' => 'bad', 'return_policy' => '', 'shipping_policy' => '', 'vacation' => true, 'vacation_message' => 'back soon']), 'Enter a valid support email.', 'bad email');
eq(save_store_profile($sid, ['tagline' => 't', 'description' => 'd', 'phone' => '1', 'support_email' => 'a@b.co', 'return_policy' => '', 'shipping_policy' => '', 'vacation' => true, 'vacation_message' => 'back soon']), null, 'store saved');
ok(store_profile($sid)['vacation'] === true, 'vacation on');

// ---- review of a never-approved product + take down
$r3 = save_product($sid, ['name' => 'Cap', 'sku' => 'DEMO-0003', 'id' => null] + $base);
db()->exec('UPDATE products SET featured_image = ? WHERE id = ?', [$sid . '/' . $r3['id'] . '/' . uuid() . '.jpg', $r3['id']]);
submit_product($r3['id'], $sid);
$cp = load_product($r3['id']);
eq(review_product($admin, $r3['id'], 'request_changes', '', $cp['updated_raw']), 'Add a note so the seller knows why.', 'note required');
eq(review_product($admin, $r3['id'], 'request_changes', 'fix photo', $cp['updated_raw']), null, 'changes requested');
eq(load_product($r3['id'])['status'], 'changes_requested', 'status changes_requested');
eq(delete_product($r3['id'], $sid), null, 'delete draft-ish product');

// ---- CSV import
$leafPath = implode(' > ', category_path_names($cat));
$hdr = implode(',', IMPORT_COLUMNS);
$line = fn (array $c) => implode(',', array_map(fn ($k) => '"' . ($c[$k] ?? '') . '"', IMPORT_COLUMNS));
$good = ['name' => 'Imp One', 'category' => $leafPath, 'regular_price' => '1,500', 'stock' => '4', 'weight_kg' => '0.3', 'length_cm' => '10', 'width_cm' => '10', 'height_cm' => '2', 'tags' => 'a, b'];
$csv = "\xEF\xBB\xBF$hdr\n" . $line($good) . "\n" . $line(['name' => 'Imp Two'] + $good) . "\n";
$before = (int) db()->val('SELECT COUNT(*) FROM products WHERE seller_id = ?', [$sid]);
$res = import_products($sid, $csv);
ok($res['ok'], 'csv import ok');
eq((int) db()->val('SELECT COUNT(*) FROM products WHERE seller_id = ?', [$sid]), $before + 2, 'two drafts created');
$imp = load_product(array_values($res['created'])[0]);
eq($imp['status'], 'draft', 'imported as draft');
eq((float) $imp['regular_price'], 1500.0, 'price parsed');
ok($imp['sku'] !== '', 'sku auto-assigned');
$bad = import_products($sid, "$hdr\n" . $line($good) . "\n" . $line(['name' => '', 'category' => 'Nowhere'] + $good) . "\n");
ok(!$bad['ok'] && isset($bad['errors'][3]), 'bad row reported with row number');
eq((int) db()->val('SELECT COUNT(*) FROM products WHERE seller_id = ?', [$sid]), $before + 2, 'nothing imported when a row is bad');
ok(!import_products($sid, "$hdr\n" . $line(['sku' => 'ZZ-1'] + $good) . "\n" . $line(['sku' => 'zz-1'] + $good) . "\n")['ok'], 'duplicate sku in file rejected');
ok(!import_products($sid, "name,category\nx,y\n")['ok'], 'missing columns rejected');

// ---- category commission, audit log, upgrade steps
$topCat = category_chain($cat)[0]['id'];
eq(commission_rate_for($cat), commission_percent(), 'default commission');
eq(category_set_commission($topCat, '15'), null, 'set category commission');
eq(commission_rate_for($cat), 15.0, 'child inherits parent commission');
eq(category_set_commission($cat, '5'), null, 'set leaf commission');
eq(commission_rate_for($cat), 5.0, 'leaf overrides parent');
ok(category_set_commission($cat, '101') !== null, 'commission over 100 rejected');
ok(category_set_commission($cat, 'abc') !== null, 'commission must be a number');
eq(category_set_commission($cat, ''), null, 'clear leaf commission');
eq(commission_rate_for($cat), 15.0, 'back to inherited');
eq(category_set_commission($topCat, ''), null, 'clear top commission');
audit($admin, 'settings', 'Test entry');
eq(audit_list(10, 'settings')[0]['summary'], 'Test entry', 'audit entry saved');
db()->exec('DROP TABLE audit_log');
db()->exec('DELETE FROM settings WHERE k = ?', ['schema_version']);
unset($GLOBALS['vh_settings']);
migrate_if_needed();
ok(table_exists(db(), 'audit_log'), 'upgrade recreates missing table');
eq((int) setting('schema_version'), SCHEMA_VERSION, 'upgrade stamps version');
migrate_if_needed();
ok(commission_by_category(db_plus('-30 days')) !== null, 'commission report runs');

// ---- messages
$msgOther = uuid(); db()->insert('users', ['id' => $msgOther, 'email' => 'm@x.test', 'password_hash' => 'x', 'created_at' => now_db()]);
$adm2 = ['id' => $admin['id'], 'email' => $admin['email'] ?? 'a@x.test', 'isAdmin' => true, 'canMessages' => true];
$selU = ['id' => $sid, 'email' => 's@x.test', 'isAdmin' => false, 'canMessages' => false];
$othU = ['id' => $msgOther, 'email' => 'o@x.test', 'isAdmin' => false, 'canMessages' => false];
ok(!inbox_seller_start($sid, '', 'hi')['ok'], 'subject required');
ok(!inbox_seller_start($sid, 'Help', '   ')['ok'], 'body required');
$st = inbox_seller_start($sid, 'Payout question', "Where is my money?\nThanks");
ok($st['ok'], 'seller starts thread');
$th = inbox_thread($st['id']);
eq((int) $th['team_unread'], 1, 'team sees it unread');
eq(inbox_reply($othU, $st['id'], 'sneaky'), 'Conversation not found.', 'other seller cannot reply');
eq(inbox_reply($adm2, $st['id'], 'On its way'), null, 'team replies');
$th = inbox_thread($st['id']);
eq([(int) $th['team_unread'], (int) $th['seller_unread']], [0, 1], 'unread flips to seller');
eq(count($th['messages']), 2, 'two messages');
inbox_mark_seen($th, 'seller');
eq((int) inbox_thread($st['id'])['seller_unread'], 0, 'seller read it');
eq(inbox_set_status($st['id'], 'closed'), null, 'close');
inbox_reply($selU, $st['id'], 'one more thing');
eq(inbox_thread($st['id'])['status'], 'open', 'seller reply reopens');
$bc = inbox_send($adm2, 'selected', [$sid, $msgOther], 'Holiday hours', 'We close on Friday.', false);
ok($bc['ok'] && $bc['count'] === 2, 'announcement to chosen sellers');
$ann = array_values(array_filter(inbox_threads_for_seller($sid), fn ($t) => $t['subject'] === 'Holiday hours'))[0];
eq((int) $ann['allow_reply'], 0, 'announcement replies off');
ok(inbox_reply($selU, $ann['id'], 'ok') !== null, 'cannot reply to announcement');
ok(!inbox_send($selU, 'everyone', [], 'x', 'y', true)['ok'], 'seller cannot broadcast');
$single = inbox_send($adm2, 'selected', [$sid], 'Just you', 'Hello', true);
ok($single['ok'] && $single['count'] === 1, 'message to one seller');
ok(!inbox_send($adm2, 'selected', [], 'x', 'y', true)['ok'], 'must choose someone');
$all = inbox_send($adm2, 'everyone', [], 'To all', 'Hi everyone', true);
ok($all['ok'] && $all['count'] >= 2, 'announcement to everyone');
ok(!in_array($admin['id'], inbox_audience_ids('everyone', []), true), 'admin is not a recipient');
ok(count(inbox_threads_team('announcements')) >= 4, 'team sees announcement threads');
ok(mb_strlen(inbox_clean("a\x07b")) === 2, 'control characters stripped');

// ---- two-step sign-in
eq(b32_decode(b32_encode('hello world!!')), 'hello world!!', 'base32 round trip');
eq(totp_at(b32_encode('12345678901234567890'), 1), '287082', 'RFC 6238 test vector (t=59)');
eq(totp_check(b32_encode('12345678901234567890'), '287082', 0, 59), 1, 'totp accepts current code');
eq(totp_check(b32_encode('12345678901234567890'), '287082', 1, 59), null, 'totp code cannot be reused');
eq(totp_check(b32_encode('12345678901234567890'), '000000', 0, 59), null, 'totp rejects wrong code');
ok(!twofa_enabled($sid), '2fa off by default');
$sec = twofa_begin($sid);
ok(!twofa_enable($sid, '000000')['ok'], 'enable needs a valid code');
$en = twofa_enable($sid, totp_at($sec, intdiv(time(), 30)));
ok($en['ok'] && count($en['codes']) === 8, 'enable ok with backup codes');
ok(twofa_enabled($sid), '2fa on');
db()->exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('Passw0rd!x', PASSWORD_DEFAULT), $sid]);
$lg = attempt_login('s@x.test', 'Passw0rd!x');
ok(!empty($lg['challenge']), 'login with 2fa asks for a challenge');
{
    ok(!login_challenge_finish($lg['challenge'], '111111')['ok'], 'wrong 2fa code refused');
    $bc = $en['codes'][0];
    ok(login_challenge_finish($lg['challenge'], $bc)['ok'], 'backup code signs in');
    eq(twofa_codes_left($sid), 7, 'backup code is used up');
    ok(!twofa_verify($sid, $bc), 'backup code works once');
}
ok(twofa_disable($sid, 'wrong') !== null, 'disable needs password');
eq(twofa_disable($sid, 'Passw0rd!x'), null, 'disable with password');
ok(!twofa_enabled($sid), '2fa off again');

// ---- bulk stock update
$stk = stock_template_rows($sid);
ok(count($stk) >= 2 && $stk[0] === ['sku', 'name', 'stock'], 'stock template lists products');
$skuA = $stk[1][0];
$r1 = import_stock($sid, "sku,stock\n$skuA,42\n");
ok($r1['ok'] && $r1['updated'] === 1, 'stock csv ok');
$bad = import_stock($sid, "sku,stock\n$skuA,7\nNOPE-1,3\n");
ok(!$bad['ok'] && isset($bad['errors'][3]), 'unknown sku reported');
ok(!import_stock($sid, "sku,stock\n$skuA,-3\n")['ok'], 'negative stock rejected');
ok(!import_stock($other, "sku,stock\n$skuA,5\n")['ok'], 'cannot update another seller');
$cur = array_values(array_filter(seller_products($sid), fn ($p) => $p['sku'] === $skuA))[0]['stock'];
eq($cur, 42, 'bad file changed nothing');

// ---- ratings and seller levels
$pid2 = $pid;
$rt = parse_rating_event(['event' => 'rating.updated', 'product' => ['product_id' => $pid2, 'average' => 4.6, 'count' => 25]]);
ok(is_array($rt), 'rating event parses');
ok(is_string(parse_rating_event(['event' => 'rating.updated', 'product' => ['product_id' => $pid2, 'average' => 7, 'count' => 1]])), 'rating out of range refused');
ok(record_rating($rt), 'rating recorded');
eq(seller_rating_summary($sid), ['count' => 25, 'average' => 4.6], 'seller rating summary');
ok(!record_rating(['product_id' => uuid(), 'average' => 4.0, 'count' => 1]), 'rating for unknown product');
$m = level_metrics($sid);
ok($m['delivered'] >= 0 && $m['rating'] === 4.6, 'metrics include rating');
$fake = ['delivered' => 200, 'items' => 300, 'returns' => 3, 'return_pct' => 1.0, 'packed' => 100, 'dispatch_pct' => 97.0, 'ratings' => 30, 'rating' => 4.8];
eq(level_earned($fake), 'blue', 'strong seller earns Blue');
eq(level_earned(['delivered' => 60, 'return_pct' => 6.0, 'dispatch_pct' => 90.0, 'ratings' => 10, 'rating' => 4.2] + $fake), 'gold', 'gold needs 50 orders and 4.0 rating');
eq(level_earned(['ratings' => 2, 'rating' => 5.0, 'delivered' => 60] + $fake), 'silver', 'gold needs enough ratings');
eq(level_earned(['delivered' => 1] + $fake), 'starter', 'too few orders stays Starter');
eq(level_earned(['delivered' => 20, 'return_pct' => 20.0] + $fake), 'starter', 'high returns blocks every level');
eq(level_earned(['delivered' => 20, 'dispatch_pct' => null] + $fake), 'bronze', 'no packing data stops at Bronze');
$row = level_refresh($sid, true);
ok(in_array($row['auto_level'], LEVELS, true), 'level stored');
eq(level_set_override($admin, $sid, 'gold', 'invited'), null, 'admin pins a level');
eq(seller_level($sid), 'gold', 'pinned level wins');
ok(count(db()->all("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'level'", [$sid])) >= 1, 'seller told about the new level');
ok(level_set_override($admin, $sid, 'platinum', '') !== null, 'unknown level refused');
eq(save_level_rules(['gold' => ['commission_off' => '2', 'hold_off' => '3']]), null, 'rules saved');
eq(level_perks('gold'), ['commission_off' => 2.0, 'hold_off' => 3], 'perks read back');
eq(commission_rate_for(null, $sid), commission_percent() - 2.0, 'commission perk applied');
ok(save_level_rules(['silver' => ['orders' => '500']]) !== null, 'levels must rise in order');
eq(level_set_override($admin, $sid, '', ''), null, 'pin removed');
db()->exec('DELETE FROM settings WHERE k = ?', ['level_rules']); unset($GLOBALS['vh_settings']);
ok(level_badge('blue') !== '' && str_contains(level_badge('blue'), 'Blue'), 'badge renders');

// ---- discount codes
$cin = ['code' => 'welcome10', 'kind' => 'percent', 'amount' => '10', 'currency' => 'NGN', 'scope' => 'all', 'one_per_customer' => '1'];
$cr = coupon_save($sid, $cin);
ok($cr['ok'], 'coupon created');
eq(coupons_for_seller($sid)[0]['code'], 'WELCOME10', 'code uppercased');
ok(!coupon_save($other, $cin)['ok'], 'code is unique across sellers');
ok(!coupon_save($sid, ['code' => 'ab'] + $cin)['ok'], 'short code refused');
ok(!coupon_save($sid, ['code' => 'BIGONE', 'amount' => '90'] + $cin)['ok'], 'percent above 70 refused');
ok(!coupon_save($sid, ['code' => 'BADDATE', 'starts_at' => '2030-02-01T10:00', 'ends_at' => '2030-01-01T10:00'] + $cin)['ok'], 'end before start refused');
ok(!coupon_save($sid, ['code' => 'NOPROD', 'scope' => 'products', 'product_ids' => [uuid()]] + $cin)['ok'], 'products must be the seller\'s own');
$cp = coupon_save($sid, ['code' => 'PICKED', 'scope' => 'products', 'product_ids' => [$pid], 'kind' => 'fixed', 'amount' => '500', 'usage_limit' => '2'] + $cin);
ok($cp['ok'], 'product-scoped fixed code');
db()->exec('UPDATE store_profiles SET vacation = 0 WHERE seller_id = ?', [$sid]);
$feed = coupons_feed();
$fw = array_values(array_filter($feed, fn ($c) => $c['code'] === 'WELCOME10'))[0];
ok($fw['enabled'] && $fw['seller_id'] === $sid && $fw['kind'] === 'percent', 'feed lists the code as enabled');
eq(array_values(array_filter($feed, fn ($c) => $c['code'] === 'PICKED'))[0]['product_ids'], [$pid], 'feed carries product ids');
eq(coupon_toggle($sid, $cr['id'], false), null, 'pause');
ok(!array_values(array_filter(coupons_feed(), fn ($c) => $c['code'] === 'WELCOME10'))[0]['enabled'], 'paused code disabled in feed');
coupon_toggle($sid, $cr['id'], true);
eq(coupon_admin_set($admin, $cr['id'], true), null, 'admin switches off');
eq(coupons_for_seller($sid)[count(coupons_for_seller($sid)) - 1]['state'] ?? '', 'blocked', 'blocked state');
coupon_admin_set($admin, $cr['id'], false);
$ev = parse_order_event(['event' => 'order.paid', 'order' => ['id' => 'WC-C1', 'number' => 'C1', 'currency' => 'NGN', 'placed_at' => gmdate('c'), 'coupons' => ['welcome10'], 'items' => [['product_id' => $pid, 'quantity' => 1, 'unit_price' => 9000, 'discount' => 1000]]]]);
ok(is_array($ev) && $ev['order']['coupons'] === ['WELCOME10'] && $ev['order']['items'][0]['discount'] === 1000.0, 'order carries coupon and discount');
record_paid_order($ev);
$cw = array_values(array_filter(coupons_for_seller($sid), fn ($c) => $c['code'] === 'WELCOME10'))[0];
eq([$cw['used_count'], $cw['discount_total']], [1, 1000.0], 'redemption counted');
record_paid_order($ev);
eq(array_values(array_filter(coupons_for_seller($sid), fn ($c) => $c['code'] === 'WELCOME10'))[0]['used_count'], 1, 'repeat order does not count twice');
eq(coupon_delete($sid, $cp['id']), null, 'unused code deleted');
ok(!in_array('PICKED', array_column(coupons_for_seller($sid), 'code'), true), 'deleted code gone');
ok(coupon_save($other, ['code' => 'PICKED'] + $cin)['ok'], 'deleted code can be reused');

// ---- level downgrading
$T0 = '2026-01-01 00:00:00.000';
$auto = ['mode' => 'auto', 'grace_days' => 14, 'one_step' => false];
$grace = ['mode' => 'grace', 'grace_days' => 14, 'one_step' => false];
$never = ['mode' => 'never', 'grace_days' => 14, 'one_step' => false];
eq(level_decide('gold', 'blue', null, $auto, $T0), ['blue', null, false], 'earning more upgrades at once');
eq(level_decide('gold', 'silver', null, $auto, $T0), ['silver', null, false], 'auto: drops straight away');
eq(level_decide('gold', 'bronze', null, ['one_step' => true] + $auto, $T0), ['silver', $T0, false], 'auto one step: drops one and keeps the clock');
eq(level_decide('gold', 'silver', null, $never, $T0), ['gold', null, false], 'never: keeps the level');
eq(level_decide('gold', 'silver', null, $grace, $T0), ['gold', $T0, true], 'grace: first miss starts the clock and warns');
eq(level_decide('gold', 'silver', $T0, $grace, '2026-01-10 00:00:00.000'), ['gold', $T0, false], 'grace: inside the window keeps the level');
eq(level_decide('gold', 'silver', $T0, $grace, '2026-01-16 00:00:00.000'), ['silver', null, false], 'grace: after the window it drops');
eq(level_decide('gold', 'gold', $T0, $grace, '2026-01-05 00:00:00.000'), ['gold', null, false], 'meeting the bar again clears the risk');
eq(save_level_policy(['level_downgrade' => 'grace', 'level_grace_days' => '30', 'level_one_step' => 'yes']), null, 'policy saved');
eq(level_policy(), ['mode' => 'grace', 'grace_days' => 30, 'one_step' => true], 'policy read back');
ok(save_level_policy(['level_downgrade' => 'sometimes']) !== null, 'bad mode refused');
ok(save_level_policy(['level_downgrade' => 'auto', 'level_grace_days' => '0']) !== null, 'bad grace refused');
db()->exec("DELETE FROM settings WHERE k IN ('level_downgrade','level_grace_days','level_one_step')"); unset($GLOBALS['vh_settings']);
// end to end: a seller who held Gold but now only earns Starter falls straight down under "auto"
db()->exec('UPDATE seller_levels SET auto_level = ?, override_level = NULL, below_since = NULL WHERE seller_id = ?', ['gold', $sid]);
$r = level_refresh($sid, true);
ok(LEVELS[level_rank($r['auto_level'])] !== 'gold', 'a seller who no longer qualifies loses the badge automatically');
ok((int) db()->val("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title LIKE 'Your seller level is now%'", [$sid]) >= 1, 'seller told about the downgrade');
level_sweep_if_due();
ok(setting('level_last_sweep', '') !== '', 'daily sweep ran');

// ---- assistant
$u = ['id' => $sid, 'email' => 's@x.test', 'stage' => 'verified', 'isAdmin' => false, 'canReview' => false, 'canOrders' => false, 'canMessages' => false];
ok(str_contains(assistant_fallback('how do I withdraw money'), 'withdrawal'), 'fallback finds payouts');
ok(str_contains(assistant_fallback('qqq zzz'), 'not sure'), 'fallback admits when unsure');
ok(str_contains(assistant_system_prompt($u), 'Products:'), 'prompt includes seller summary');
ok(!assistant_ask($u, '   ')['ok'], 'empty question refused');
$r = assistant_ask($u, 'How do I add many products?');
ok($r['ok'] && !$r['ai'] && str_contains($r['reply'], 'CSV'), 'no key: built-in answer');
set_setting('ai_api_key', 'sk-ant-test-key-aaaaaaaaaaaaaaaaaaaa');
$seen = null;
$GLOBALS['vh_ai_stub'] = function ($system, $msgs) use (&$seen) { $seen = [$system, $msgs]; return 'Stub answer'; };
$r = assistant_ask($u, 'Second question');
ok($r['ok'] && $r['ai'] && $r['reply'] === 'Stub answer', 'AI answer used when key set');
ok(str_contains($seen[0], 'FACTS ABOUT VENDOR HUB'), 'AI gets the facts');
eq($seen[1][count($seen[1]) - 1], ['role' => 'user', 'content' => 'Second question'], 'AI gets the question last');
eq($seen[1][0]['role'], 'user', 'conversation starts with the person');
$GLOBALS['vh_ai_stub'] = fn () => null;
$r = assistant_ask($u, 'when is payout released');
ok($r['ok'] && !$r['ai'], 'AI failure falls back to built-in help');
unset($GLOBALS['vh_ai_stub']);
eq(count(assistant_history($sid)), 6, 'history kept');
db()->exec("DELETE FROM assistant_messages WHERE user_id = ?", [$sid]);
for ($i = 0; $i < AI_DAILY_LIMIT; $i++) db()->insert('assistant_messages', ['id' => uuid(), 'user_id' => $sid, 'role' => 'user', 'body' => 'q', 'created_at' => now_db()]);
ok(!assistant_ask($u, 'one more')['ok'], 'daily limit enforced');
db()->exec("DELETE FROM assistant_messages WHERE user_id = ?", [$sid]);
eq(save_settings(['commission_percent' => '10', 'payout_hold_days' => '7', 'min_withdrawal' => '1000', 'withdrawal_fee' => '0', 'ai_api_key' => 'bad key!']), 'That does not look like an Anthropic API key.', 'bad AI key rejected');

// ---- backup
$dump = '';
backup_stream(function ($c) use (&$dump) { $dump .= $c; });
ok(str_contains($dump, 'INSERT INTO `users`') && str_contains($dump, 's@x.test'), 'backup contains data');
ok(!str_contains($dump, 'INSERT INTO `sessions`'), 'backup skips sessions');
ok(!in_array('sessions', backup_tables(), true) && in_array('products', backup_tables(), true), 'backup table list');

// ---- system check
$chk = system_checks(false);
ok(count($chk) > 10, 'system check returns rows');
eq(count(array_filter($chk, fn ($r) => $r[0] === 'Tables' && $r[1] === 'ok')), 1, 'system check sees all tables');
eq(ini_bytes('8M'), 8388608, 'ini_bytes');

// ---- Paystack
$calls = [];
$mode = ['transfer' => 'success', 'refund' => 'pending'];
$GLOBALS['vh_paystack_stub'] = function ($method, $path, $body) use (&$calls, &$mode) {
    $calls[] = [$method, $path, $body];
    if ($path === '/balance') return ['code' => 200, 'data' => [['currency' => 'NGN', 'balance' => 12345600]]];
    if (str_starts_with($path, '/bank?')) return ['code' => 200, 'data' => [['code' => '058', 'name' => 'Guaranty Trust Bank'], ['code' => '044', 'name' => 'Access Bank']]];
    if (str_starts_with($path, '/bank/resolve')) return ['code' => 200, 'data' => ['account_name' => 'ADA OBI']];
    if ($path === '/transferrecipient') return ['code' => 200, 'data' => ['recipient_code' => 'RCP_test']];
    if ($path === '/transfer') return $mode['transfer'] === 'refuse' ? ['code' => 400, 'data' => [], 'message' => 'Insufficient balance'] : ['code' => 200, 'data' => ['status' => $mode['transfer'], 'transfer_code' => 'TRF_abc']];
    if (str_starts_with($path, '/transfer/verify/')) return $mode['transfer'] === 'missing' ? ['code' => 404, 'data' => [], 'message' => 'not found'] : ['code' => 200, 'data' => ['status' => 'success', 'transfer_code' => 'TRF_abc']];
    if ($path === '/refund') return $mode['refund'] === 'refuse' ? ['code' => 400, 'data' => [], 'message' => 'Transaction not found'] : ['code' => 200, 'data' => ['status' => $mode['refund'], 'id' => 777]];
    return ['code' => 404, 'data' => []];
};
eq(save_paystack_settings(['paystack_secret_key' => 'nonsense']), 'That does not look like a Paystack secret key. It starts with sk_test_ or sk_live_.', 'bad Paystack key refused');
eq(save_paystack_settings(['paystack_secret_key' => 'sk_' . 'test_' . 'abcdefghijklmnopqrstuvwx', 'paystack_enabled' => 'yes']), null, 'key saved');
ok(paystack_on() && paystack_test_mode(), 'Paystack on in test mode');
eq(paystack_balance(), ['ok' => true, 'ngn' => 123456.0], 'balance read in naira');
eq(kobo(1234.565), 123457, 'kobo rounding');
eq(bank_code_for('guaranty trust bank'), '058', 'bank found by name');
eq(bank_code_for('GTBank'), null, 'unknown bank name not guessed');
// bank accounts: the bank must be known and the name comes from the bank
ok(add_bank_account($other, 'Nowhere Bank', '0123456789', 'Someone') === 'Choose your bank from the list.', 'unknown bank refused');
eq(add_bank_account($other, 'Guaranty Trust Bank', '0123456789', 'typed name'), null, 'known bank accepted');
$acct = db()->one('SELECT * FROM bank_accounts WHERE seller_id = ?', [$other]);
eq([$acct['bank_code'], $acct['account_name']], ['058', 'ADA OBI'], 'bank code stored and name taken from the bank');
// withdrawals
db()->exec("DELETE FROM withdrawals WHERE seller_id = ?", [$other]);
db()->insert('wallet_entries', ['id' => uuid(), 'seller_id' => $other, 'kind' => 'adjustment', 'amount' => 50000, 'currency' => 'NGN', 'note' => 'test', 'available_at' => '2000-01-01 00:00:00.000', 'created_at' => now_db()]);
eq(request_withdrawal($other, $acct['id'], 'NGN', 10000), null, 'withdrawal requested');
$w = seller_withdrawals($other)[0];
$before = wallet_balances($other)[0]['available'];
$calls = [];
$mode['transfer'] = 'refuse';
ok(str_contains((string) paystack_send_withdrawal($admin, $w['id']), 'Insufficient balance'), 'refused transfer reports why');
eq(db()->val('SELECT status FROM withdrawals WHERE id = ?', [$w['id']]), 'requested', 'refused transfer leaves it requested');
$mode['transfer'] = 'pending';
eq(paystack_send_withdrawal($admin, $w['id']), null, 'transfer started');
$wd = db()->one('SELECT * FROM withdrawals WHERE id = ?', [$w['id']]);
eq([$wd['status'], $wd['transfer_ref'], $wd['transfer_code']], ['processing', 'vh-' . $w['id'] . '-2', 'TRF_abc'], 'processing with a fresh reference per attempt');
$tr = array_values(array_filter($calls, fn ($c) => $c[1] === '/transfer'));
eq(end($tr)[2]['amount'], 1000000, 'transfer amount in kobo');
ok(paystack_send_withdrawal($admin, $w['id']) !== null, 'cannot send twice (double click)');
eq(count(array_filter($calls, fn ($c) => $c[1] === '/transfer')), 2, 'only the refused and the real transfer were sent');
eq(count(array_filter($calls, fn ($c) => $c[1] === '/transferrecipient')), 1, 'recipient created once and reused');
ok(decide_withdrawal($admin, $w['id'], 'paid', 'x') !== null, 'manual decision blocked while sending');
eq(wallet_balances($other)[0]['available'], $before, 'money stays held while sending');
// webhook
ok(!paystack_signature_ok('{}', 'wrong'), 'bad signature refused');
$raw = '{"event":"transfer.success","data":{"reference":"' . $wd['transfer_ref'] . '"}}';
ok(paystack_signature_ok($raw, hash_hmac('sha512', $raw, paystack_key())), 'good signature accepted');
paystack_handle_event(json_decode($raw, true));
eq(db()->val('SELECT status FROM withdrawals WHERE id = ?', [$w['id']]), 'paid', 'webhook marks paid');
eq(db()->val('SELECT reference FROM withdrawals WHERE id = ?', [$w['id']]), 'TRF_abc', 'transfer code kept as the reference');
paystack_handle_event(json_decode($raw, true));
eq(wallet_balances($other)[0]['available'], $before, 'repeat webhook changes nothing');
// failure returns the money
eq(request_withdrawal($other, $acct['id'], 'NGN', 5000), null, 'second withdrawal');
$w2 = seller_withdrawals($other)[0];
$mid = wallet_balances($other)[0]['available'];
$mode['transfer'] = 'pending';
paystack_send_withdrawal($admin, $w2['id']);
$ref2 = db()->val('SELECT transfer_ref FROM withdrawals WHERE id = ?', [$w2['id']]);
paystack_handle_event(['event' => 'transfer.failed', 'data' => ['reference' => $ref2, 'reason' => 'Account closed']]);
eq(db()->val('SELECT status FROM withdrawals WHERE id = ?', [$w2['id']]), 'failed', 'failed transfer marked failed');
eq(wallet_balances($other)[0]['available'], $mid + 5000.0, 'failed transfer returns the money');
paystack_handle_event(['event' => 'transfer.failed', 'data' => ['reference' => $ref2]]);
eq(wallet_balances($other)[0]['available'], $mid + 5000.0, 'money returned only once');
// lost answer, then check
eq(request_withdrawal($other, $acct['id'], 'NGN', 2000), null, 'third withdrawal');
$w3 = seller_withdrawals($other)[0];
$mode['transfer'] = 'missing';
db()->exec("UPDATE withdrawals SET status = 'processing', transfer_ref = 'vh-lost', transfer_attempts = 1 WHERE id = ?", [$w3['id']]);
eq(paystack_check_withdrawal($w3['id']), null, 'check runs');
eq(db()->val('SELECT status FROM withdrawals WHERE id = ?', [$w3['id']]), 'requested', 'no record at Paystack: can send again');
// refunds: customer money queue
$cr = db()->one("SELECT * FROM customer_refunds ORDER BY created_at LIMIT 1");
ok($cr !== null && $cr['status'] === 'to_pay', 'refunding an order queued the customer refund');
$ord = db()->one('SELECT * FROM orders WHERE id = ?', [$cr['order_id']]);
ok(str_contains((string) paystack_send_refund($admin, $cr['id'], 100), 'no Paystack payment reference'), 'no payment reference: cannot refund through Paystack');
db()->exec("UPDATE orders SET payment_ref = 'PSK_123' WHERE id = ?", [$cr['order_id']]);
$mode['refund'] = 'refuse';
ok(str_contains((string) paystack_send_refund($admin, $cr['id'], 100), 'Transaction not found'), 'refused refund reports why');
eq(db()->val('SELECT status FROM customer_refunds WHERE id = ?', [$cr['id']]), 'failed', 'refused refund marked failed, can retry');
$mode['refund'] = 'pending';
$calls = [];
eq(paystack_send_refund($admin, $cr['id'], 150.5), null, 'refund started');
eq(db()->val('SELECT status FROM customer_refunds WHERE id = ?', [$cr['id']]), 'sending', 'refund sending');
eq($calls[0][2]['amount'], 15050, 'refund amount in kobo');
eq($calls[0][2]['transaction'], 'PSK_123', 'refund uses the payment reference');
ok(paystack_send_refund($admin, $cr['id'], 150.5) !== null && count($calls) === 1, 'double click does not refund twice');
paystack_handle_event(['event' => 'refund.processed', 'data' => ['id' => 777, 'transaction_reference' => 'PSK_123']]);
eq(db()->val('SELECT status FROM customer_refunds WHERE id = ?', [$cr['id']]), 'refunded', 'webhook marks refunded');
ok(customer_refund_mark($admin, $cr['id'], '') !== null, 'finished refund cannot be marked again');
ok(customer_refund_open_count() >= 0, 'open refund count works');
$GLOBALS['vh_paystack_stub'] = null; unset($GLOBALS['vh_paystack_stub']);
ok(!paystack_call('GET', '/balance')['ok'], 'no real Paystack calls while testing');

// ---- linking to the existing marketplace seller, product and order import ----
$lk = uuid();
db()->insert('users', ['id' => $lk, 'email' => 'old@shop.test', 'password_hash' => 'x', 'created_at' => now_db()]);
$appId = uuid();
db()->insert('seller_applications', ['id' => $appId, 'user_id' => $lk, 'status' => 'approved', 'current_step' => 5, 'personal' => '{}', 'business' => '{}', 'brand' => jenc(['store_name' => 'Old Shop']), 'registration' => '{}', 'declaration' => '{}', 'submitted_at' => now_db(), 'created_at' => now_db(), 'updated_at' => now_db()]);
ok(!is_verified_seller($lk), 'not verified yet');
ok(link_seller(['id' => $lk, 'email' => 'a'], $lk, 7, 'Old Shop') !== null, 'unverified seller cannot be linked');
db()->insert('seller_verifications', ['id' => uuid(), 'application_id' => $appId, 'user_id' => $lk, 'status' => 'approved', 'current_step' => 5, 'details' => '{}', 'documents' => '{}', 'created_at' => now_db(), 'updated_at' => now_db()]);
ok(is_verified_seller($lk), 'verified now');
$admin = ['id' => $lk, 'email' => 'admin@x.test'];
set_setting('marketplace_url', 'https://shop.test'); set_setting('marketplace_api_key', 'k');
$calls = [];
$wcProducts = [
  ['id' => 501, 'type' => 'simple', 'name' => 'Linen Shirt', 'status' => 'publish', 'sku' => 'OLD-1', 'description' => '<p>Nice</p>', 'short_description' => 'Nice', 'currency' => 'NGN', 'manage_stock' => true, 'stock' => 12, 'backorders' => false, 'low_stock' => 3,
   'weight' => '0.4', 'length' => '30', 'width' => '20', 'height' => '5', 'category_path' => ['Fashion', 'Men'], 'tags' => ['linen'], 'images' => ['https://shop.test/a.jpg', 'https://shop.test/b.jpg'], 'regular_price' => '8000', 'sale_price' => '7000', 'attributes' => []],
  ['id' => 502, 'type' => 'variable', 'name' => 'Ankara Dress', 'status' => 'publish', 'sku' => 'OLD-2', 'description' => '', 'short_description' => '', 'currency' => 'NGN', 'manage_stock' => false, 'stock' => 0, 'backorders' => false, 'low_stock' => 0, 'category_path' => ['Nowhere'], 'tags' => [], 'images' => [],
   'regular_price' => '', 'sale_price' => '', 'attributes' => [['name' => 'Size', 'values' => ['S', 'M'], 'variation' => true]],
   'variations' => [['id' => 601, 'sku' => 'OLD-2-S', 'options' => ['Size' => 'S'], 'regular_price' => '12000', 'sale_price' => '', 'stock' => 4, 'enabled' => true], ['id' => 602, 'sku' => '', 'options' => ['Size' => 'M'], 'regular_price' => '12000', 'sale_price' => '', 'stock' => 6, 'enabled' => true]]],
  ['id' => 503, 'type' => 'grouped', 'name' => 'Bundle', 'status' => 'publish', 'sku' => 'OLD-3'],
  ['id' => 504, 'type' => 'simple', 'name' => 'Clash', 'status' => 'publish', 'sku' => 'OLD-1', 'regular_price' => '100', 'attributes' => []],
];
$wcOrders = [
  ['id' => '9001', 'number' => '9001', 'status' => 'completed', 'currency' => 'NGN', 'placed_at' => gmdate('c', time() - 86400 * 10), 'customer_name' => 'Ada O.', 'ship_city' => 'Lagos', 'ship_state' => 'Lagos', 'payment_ref' => 'ref1', 'payment_method' => 'paystack', 'items' => [['wc_product_id' => 501, 'wc_variation_id' => null, 'quantity' => 2, 'unit_price' => 7000]]],
  ['id' => '9002', 'number' => '9002', 'status' => 'shipped', 'currency' => 'NGN', 'placed_at' => gmdate('c', time() - 86400 * 2), 'customer_name' => 'Bo K.', 'ship_city' => 'Abuja', 'ship_state' => 'FCT', 'carrier' => 'DHL', 'tracking_number' => 'T1', 'items' => [['wc_product_id' => 502, 'wc_variation_id' => 602, 'quantity' => 1, 'unit_price' => 12000], ['wc_product_id' => 999, 'quantity' => 1, 'unit_price' => 5]]],
  ['id' => '9003', 'number' => '9003', 'status' => 'processing', 'currency' => 'NGN', 'placed_at' => gmdate('c', time() - 3600), 'items' => [['wc_product_id' => 999, 'quantity' => 1, 'unit_price' => 5]]],
];
$GLOBALS['vh_fetch_stub'] = fn ($u) => base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
$GLOBALS['vh_market_stub'] = function ($path, $method, $body) use (&$calls, $wcProducts, $wcOrders) {
    $calls[] = [$path, $method, $body];
    if (str_starts_with($path, '/vendors')) return ['vendors' => [['id' => 7, 'email' => 'old@shop.test', 'name' => 'Old Shop', 'product_count' => 3], ['id' => 8, 'email' => 'other@shop.test', 'name' => 'Other', 'product_count' => 1]]];
    if ($path === '/link') return ['ok' => true];
    if ($path === '/unlink') return ['ok' => true];
    if (str_starts_with($path, '/vendor/products')) { parse_str((string) parse_url($path, PHP_URL_QUERY), $q); return ['products' => (int) $q['after'] === 0 ? $wcProducts : [], 'next' => null, 'remaining' => 0]; }
    if ($path === '/adopt') return ['ok' => true, 'adopted' => count($body['items'])];
    if (str_starts_with($path, '/vendor/orders')) { parse_str((string) parse_url($path, PHP_URL_QUERY), $q); return ['orders' => (int) $q['after'] === 0 ? $wcOrders : [], 'next' => null, 'remaining' => 0]; }
    return null;
};
$vend = marketplace_vendors('');
eq(count($vend), 2, 'marketplace sellers listed');
$sug = suggest_matches($vend, 'old@shop.test', 'Old Shop');
eq($sug[0]['id'], 7, 'same email suggested first'); eq($sug[0]['why'], 'Same email', 'reason shown');
eq(suggest_matches($vend, 'zzz@x.test', 'other')[0]['id'], 8, 'store name match suggested');
ok(import_products_batch($admin, $lk, 0)['error'] === 'Link the seller first.', 'import needs a link');
eq(link_seller($admin, $lk, 7, 'Old Shop'), null, 'linked');
ok(link_seller($admin, $lk, 7, 'Old Shop') !== null, 'cannot link twice');
$lk2 = uuid(); db()->insert('users', ['id' => $lk2, 'email' => 'second@shop.test', 'password_hash' => 'x', 'created_at' => now_db()]);
eq(seller_link($lk)['uid'], 7, 'link stored');
$r = import_products_batch($admin, $lk, 0);
eq($r['created'], 2, 'two products imported');
ok(count($r['notes']) === 2, 'grouped and SKU clash reported: ' . implode(' | ', $r['notes']));
$shirt = db()->one('SELECT * FROM products WHERE marketplace_product_id = 501');
eq($shirt['status'], 'approved', 'published product is live'); eq((int) $shirt['live'], 1, 'live flag'); eq((int) $shirt['stock'], 12, 'stock copied');
eq((float) $shirt['sale_price'], 7000.0, 'sale price copied'); ok(!empty($shirt['published_snapshot']), 'snapshot written so the feed has it');
ok($shirt['category_id'] !== null, 'category matched by name path'); ok(!empty($shirt['featured_image']) && count(jdec($shirt['gallery'], [])) === 1, 'images downloaded');
$dress = db()->one('SELECT * FROM products WHERE marketplace_product_id = 502');
eq($dress['product_type'], 'variable', 'variable imported'); eq($dress['category_id'], null, 'unknown category left empty');
eq((int) db()->val('SELECT COUNT(*) FROM product_variations WHERE product_id = ?', [$dress['id']]), 2, 'variations imported');
ok((bool) db()->val("SELECT 1 FROM product_variations WHERE sku = 'OLD-2-2'"), 'blank variation SKU generated');
$adopt = array_values(array_filter($calls, fn ($c) => $c[0] === '/adopt'))[0][2];
eq(count($adopt['items']), 2, 'marketplace told about both products'); eq(count($adopt['items'][1]['variations']), 2, 'variation ids sent');
$again = import_products_batch($admin, $lk, 0);
eq((int) db()->val('SELECT COUNT(*) FROM products WHERE seller_id = ?', [$lk]), 2, 'importing again creates no duplicates');
eq(import_orders_batch($admin, $lk, 0)['imported'], 2, 'two orders imported (third has no matching products)');
$hist = db()->one("SELECT * FROM orders WHERE marketplace_order_id = '9001'"); eq((int) $hist['history'], 1, 'finished order is history');
eq((float) db()->val("SELECT COALESCE(SUM(amount),0) FROM wallet_entries WHERE seller_id = ?", [$lk]), 10800.0, 'only the open order pays the wallet (12000 less 10%)');
eq((int) db()->val("SELECT COUNT(*) FROM wallet_entries WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = ?)", [$hist['id']]), 0, 'history order adds no wallet entry');
$open = db()->one("SELECT i.* FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.marketplace_order_id = '9002'");
eq($open['fulfilment'], 'shipped', 'open order keeps its stage'); eq($open['carrier'], 'DHL', 'carrier kept');
eq(import_orders_batch($admin, $lk, 0)['imported'], 0, 'orders import is repeatable');
eq((int) db()->val('SELECT COUNT(*) FROM orders'), 2 + (int) db()->val("SELECT COUNT(*) FROM orders WHERE marketplace_order_id NOT IN ('9001','9002')"), 'no duplicate orders');
// the store later reports the same open order: nothing doubles
$dup = record_paid_order(['event' => 'order.paid', 'order' => ['id' => '9002', 'number' => '9002', 'currency' => 'NGN', 'placed_at' => now_db(), 'customer_name' => '', 'ship_city' => '', 'ship_state' => '', 'restock' => false, 'items' => [['product_id' => $dress['id'], 'variation_id' => db()->val("SELECT id FROM product_variations WHERE marketplace_variation_id = 602"), 'quantity' => 1, 'unit_price' => 12000.0, 'discount' => 0.0]]]]);
ok($dup['duplicate'], 'live reports of an imported order are ignored');
// delivering the open order starts the normal payout hold
apply_marketplace_status(['id' => '9002', 'status' => 'delivered', 'carrier' => '', 'tracking' => '', 'note' => ''], 'marketplace');
ok((string) db()->val("SELECT available_at FROM wallet_entries WHERE seller_id = ?", [$lk]) !== NOT_DELIVERED_YET, 'delivery releases the imported open order after the hold');
// a refund of a history order reverses nothing in the wallet
$before = (float) db()->val("SELECT COALESCE(SUM(amount),0) FROM wallet_entries WHERE seller_id = ?", [$lk]);
record_refund(['event' => 'order.refunded', 'order' => ['id' => '9001', 'number' => '9001', 'restock' => false]]);
eq((float) db()->val("SELECT COALESCE(SUM(amount),0) FROM wallet_entries WHERE seller_id = ?", [$lk]), $before, 'refunding a history order leaves the wallet alone');
ok(unlink_seller($admin, $lk) !== null, 'cannot unlink after importing');
unset($GLOBALS['vh_market_stub'], $GLOBALS['vh_fetch_stub']); set_setting('marketplace_url', ''); set_setting('marketplace_api_key', '');

// ---- SMTP settings ----
ok(save_smtp_settings(['smtp_host' => 'bad host!', 'smtp_port' => '587', 'smtp_secure' => 'tls']) !== null, 'smtp: bad host refused');
ok(save_smtp_settings(['smtp_host' => 'smtp.example.com', 'smtp_port' => '99999', 'smtp_secure' => 'tls']) !== null, 'smtp: bad port refused');
ok(save_smtp_settings(['smtp_host' => 'smtp.example.com', 'smtp_port' => '587', 'smtp_secure' => 'weird']) !== null, 'smtp: bad mode refused');
eq(save_smtp_settings(['smtp_host' => 'smtp.example.com', 'smtp_port' => '465', 'smtp_secure' => 'ssl', 'smtp_user' => 'u', 'smtp_pass' => 'secret']), null, 'smtp saved');
eq(setting('smtp_pass', ''), 'secret', 'smtp password stored');
save_smtp_settings(['smtp_host' => 'smtp.example.com', 'smtp_port' => '465', 'smtp_secure' => 'ssl', 'smtp_user' => 'u', 'smtp_pass' => '']);
eq(setting('smtp_pass', ''), 'secret', 'blank password keeps the saved one');
save_smtp_settings(['smtp_host' => '', 'smtp_port' => '587', 'smtp_secure' => 'tls', 'smtp_clear' => 'yes']);
eq(setting('smtp_pass', ''), '', 'password can be removed');
ok(!smtp_on(), 'smtp never used while testing');

echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
