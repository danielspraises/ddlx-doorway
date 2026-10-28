<?php
declare(strict_types=1);

// Adds a ready-made verified seller with one live product, so you can try orders straight away.
// Usage:  php dev/demo.php        (run dev/setup.php first)
// Seller login: seller@demo.test / password123

$root = dirname(__DIR__);
if (!is_file($root . '/config/config.php')) { fwrite(STDERR, "Run  php dev/setup.php  first (it creates the local database).\n"); exit(1); }
define('VH_TESTING', true);
require $root . '/app/bootstrap.php';
$GLOBALS['vh_config'] = require $root . '/config/config.php';

$db = db();
if ($db->val("SELECT 1 FROM users WHERE email = 'seller@demo.test'")) { echo "The demo seller already exists.\n"; exit(0); }
$sid = uuid();
$db->insert('users', ['id' => $sid, 'email' => 'seller@demo.test', 'password_hash' => password_hash('password123', PASSWORD_DEFAULT), 'created_at' => now_db()]);
$p1 = ['first_name' => 'Demo', 'last_name' => 'Seller', 'other_names' => '', 'email' => 'seller@demo.test', 'phone' => '08031234567', 'address' => '1 Test Road', 'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria', 'role' => 'Owner', 'id_type' => 'International Passport', 'id_number' => 'A1234567'];
$p2 = ['name' => 'Demo Ltd', 'type' => 'Partnership', 'type_other' => '', 'category' => 'Clothing', 'category_other' => '', 'phone' => '', 'email' => '', 'address' => '1 Test Road', 'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria', 'website' => '', 'years' => '2', 'employees' => '', 'description' => 'Demo clothing brand'];
$p3 = ['store_name' => 'Demo Store', 'instagram' => '@demo', 'facebook' => 'fb.com/demo', 'owns_brand' => 'no', 'brand_name' => '', 'brand_category' => '', 'brand_category_other' => '', 'brand_description' => '', 'tiktok' => '', 'x' => '', 'whatsapp' => '', 'snapchat' => '', 'other' => ''];
$p4 = ['registered' => 'no', 'reg_number' => '', 'reg_name' => ''];
foreach ([[1, $p1], [2, $p2], [3, $p3], [4, $p4]] as [$st, $d]) save_application($sid, $st, $d, false);
save_application($sid, 5, [], true, ['0' => 'yes', '1' => 'yes', '2' => 'yes', '3' => 'yes']);
$db->exec("UPDATE seller_applications SET status = 'approved' WHERE user_id = ?", [$sid]);
load_verification($sid);
$db->exec("UPDATE seller_verifications SET status = 'approved' WHERE user_id = ?", [$sid]);

$leaf = null;
$allCats = categories_all(true);
foreach (category_flat($allCats) as $row) if (!category_children($row['category']['id'], $allCats) && $row['category']['active'] && count(category_chain($row['category']['id'])) >= 3) { $leaf = $row['category']; break; }
$r = save_product($sid, ['id' => null, 'name' => 'Demo Ankara Shirt', 'sku' => 'DEMO-0001', 'product_type' => 'simple', 'brand' => 'Demo', 'category_id' => $leaf['id'], 'tags' => ['demo'],
    'short_description' => 'A demo shirt', 'description' => 'A demo shirt for testing.', 'currency' => 'NGN', 'regular_price' => 10000.0, 'sale_price' => 8000.0, 'sale_starts_at' => null, 'sale_ends_at' => null,
    'manage_stock' => true, 'stock' => 25, 'low_stock_threshold' => 3, 'allow_backorders' => false, 'weight_kg' => 0.5, 'length_cm' => 30.0, 'width_cm' => 20.0, 'height_cm' => 5.0, 'attributes' => [], 'variations' => []]);
if (!$r['ok']) { echo 'Could not create the product: ' . $r['error'] . "\n"; exit(1); }
$pid = $r['id'];
$im = imagecreatetruecolor(600, 600); imagefill($im, 0, 0, imagecolorallocate($im, 30, 60, 110)); imagestring($im, 5, 230, 290, 'DEMO PRODUCT', imagecolorallocate($im, 255, 255, 255));
ob_start(); imagepng($im); $png = (string) ob_get_clean();
$path = $sid . '/' . $pid . '/' . uuid() . '.png';
Storage::save('products', $path, $png);
$db->exec('UPDATE products SET featured_image = ? WHERE id = ?', [$path, $pid]);
$err = submit_product($pid, $sid);
if ($err) { echo "Submit failed: $err\n"; exit(1); }
$admin = $db->val("SELECT user_id FROM user_roles WHERE role = 'admin' LIMIT 1");
$prod = load_product($pid);
$err = review_product(['id' => $admin, 'isAdmin' => true], $pid, 'approve', '', $prod['updated_raw']);
echo $err ? "Approve failed: $err\n" : "Demo seller ready: seller@demo.test / password123\nLive product id: $pid\nTry an order:  php dev/market.php paid $pid 2\n";
