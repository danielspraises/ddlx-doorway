<?php
declare(strict_types=1);
// Self-tests on SQLite:  php tests/run.php   (production uses MySQL/MariaDB; the SQL is kept portable)
define('VH_TESTING', true);
require __DIR__ . '/../app/bootstrap.php';

$tmp = sys_get_temp_dir() . '/vh-test-' . getmypid();
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
// hold: nothing available yet? order was 10 days ago and hold is 7 -> available
eq($bal['available'], 18000.0, 'available after hold');

// ---- fulfilment
$itemId = (string) db()->val('SELECT id FROM order_items WHERE seller_id = ? ORDER BY created_at LIMIT 1', [$sid]);
ok(update_fulfilment($sid, $itemId, 'deliver') !== null, 'cannot deliver before shipping');
ok(update_fulfilment($sid, $itemId, 'ship', 'Nope', 'X1') !== null, 'unknown carrier rejected');
ok(update_fulfilment($sid, $itemId, 'ship', 'DHL', '') !== null, 'tracking required');
ok(update_fulfilment('other-seller', $itemId, 'ship', 'DHL', 'X1') !== null, 'other seller cannot ship');
eq(update_fulfilment($sid, $itemId, 'ship', 'DHL', 'X1'), null, 'shipped');
ok(update_fulfilment($sid, $itemId, 'ship', 'DHL', 'X2') !== null, 'cannot ship twice');
eq(update_fulfilment($sid, $itemId, 'deliver'), null, 'delivered');
eq(db()->val('SELECT fulfilment FROM order_items WHERE id = ?', [$itemId]), 'delivered', 'fulfilment stored');

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

echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
