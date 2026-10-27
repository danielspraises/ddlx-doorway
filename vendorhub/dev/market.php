<?php
declare(strict_types=1);

// Pretends to be WooCommerce so you can test orders without a real store.
//   php dev/market.php list                          show live products (with their ids)
//   php dev/market.php paid <product_id> [qty] [variation_id]   a customer pays for an order
//   php dev/market.php status <order_id> <shipped|delivered|completed|ready_for_shipping> [carrier] [tracking]
//   php dev/market.php refund <order_id>
// Options: --url=http://127.0.0.1:8000

$root = dirname(__DIR__);
define('VH_TESTING', true);
require $root . '/app/bootstrap.php';
$GLOBALS['vh_config'] = require $root . '/config/config.php';

$args = array_values(array_filter($argv, fn ($a) => !str_starts_with($a, '--')));
$opt = getopt('', ['url::']);
$base = rtrim((string) ($opt['url'] ?? setting('app_url', 'http://127.0.0.1:8000')), '/');
$secret = (string) setting('marketplace_webhook_secret', '');
$cmd = $args[1] ?? 'help';

function send(string $base, string $secret, array $payload): void
{
    $body = json_encode($payload);
    $ts = (string) time();
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 15,
        'header' => "Content-Type: application/json\r\nX-Verola-Timestamp: $ts\r\nX-Verola-Signature: sha256=" . hash_hmac('sha256', $ts . '.' . $body, $secret) . "\r\n", 'content' => $body]]);
    $res = file_get_contents($base . '/api/marketplace/orders', false, $ctx);
    echo ($http_response_header[0] ?? 'no response') . "\n" . $res . "\n";
}

if ($cmd === 'list') {
    foreach (db()->all("SELECT id, name, sku, stock, product_type FROM products WHERE live = 1 ORDER BY name") as $p) echo "{$p['id']}  {$p['sku']}  {$p['name']}  (stock {$p['stock']})\n";
    foreach (db()->all("SELECT v.id, v.sku, v.options, p.name FROM product_variations v JOIN products p ON p.id = v.product_id WHERE p.live = 1") as $v) echo "  variation {$v['id']}  {$v['sku']}  {$v['name']} " . $v['options'] . "\n";
} elseif ($cmd === 'paid' && isset($args[2])) {
    $n = random_int(1000, 9999);
    $item = ['product_id' => $args[2], 'quantity' => (int) ($args[3] ?? 1), 'unit_price' => 0.0];
    $snap = db()->val('SELECT published_snapshot FROM products WHERE id = ?', [$args[2]]);
    $d = $snap ? jdec($snap, []) : [];
    $item['unit_price'] = (float) ($d['sale_price'] ?? $d['regular_price'] ?? 1000);
    if (!empty($args[4])) $item['variation_id'] = $args[4];
    echo "Order id: WC-$n\n";
    send($base, $secret, ['event' => 'order.paid', 'order' => ['id' => "WC-$n", 'number' => (string) $n, 'currency' => $d['currency'] ?? 'NGN', 'placed_at' => gmdate('c'), 'customer_name' => 'Test C.', 'ship_city' => 'Lagos', 'ship_state' => 'Lagos', 'items' => [$item]]]);
} elseif ($cmd === 'status' && isset($args[2], $args[3])) {
    send($base, $secret, ['event' => 'order.status', 'order' => ['id' => $args[2], 'status' => $args[3], 'carrier' => $args[4] ?? '', 'tracking_number' => $args[5] ?? '']]);
} elseif ($cmd === 'refund' && isset($args[2])) {
    send($base, $secret, ['event' => 'order.refunded', 'order' => ['id' => $args[2], 'currency' => 'NGN', 'placed_at' => gmdate('c'), 'restock' => true, 'items' => []]]);
} else {
    echo "Commands: list | paid <product_id> [qty] [variation_id] | status <order_id> <status> [carrier] [tracking] | refund <order_id>\n";
}
