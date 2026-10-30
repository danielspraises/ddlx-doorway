<?php
declare(strict_types=1);

// Customer reviews live on the marketplace (WooCommerce). It tells Vendor Hub each product's average and number of ratings,
// so sellers can see them and they count towards the seller level.

function parse_rating_event(mixed $d): array|string
{
    if (!is_array($d) || ($d['event'] ?? '') !== 'rating.updated') return 'Unknown event.';
    $p = $d['product'] ?? null;
    if (!is_array($p) || !is_uuid($p['product_id'] ?? null)) return 'Invalid product_id.';
    $avg = $p['average'] ?? null;
    $n = $p['count'] ?? null;
    if (!is_numeric($avg) || $avg < 0 || $avg > 5) return 'average must be between 0 and 5.';
    if (!is_int($n) || $n < 0 || $n > 10000000) return 'count must be a whole number.';
    return ['product_id' => $p['product_id'], 'average' => round((float) $avg, 2), 'count' => $n];
}

function record_rating(array $e): bool
{
    $p = db()->one('SELECT seller_id, name, rating_count FROM products WHERE id = ?', [$e['product_id']]);
    if (!$p) return false;
    db()->exec('UPDATE products SET rating_avg = ?, rating_count = ? WHERE id = ?', [$e['count'] > 0 ? $e['average'] : null, $e['count'], $e['product_id']]);
    if ($e['count'] > (int) $p['rating_count'] && $e['average'] <= 3.0 && $e['count'] >= 3) {
        notify($p['seller_id'], 'rating', 'Ratings are dropping on ' . $p['name'], 'The average is now ' . number_format($e['average'], 1) . ' from ' . $e['count'] . ' ratings. Check the product and your orders for the cause.', '/seller?tab=products');
    }
    return true;
}

function seller_rating_summary(string $sellerId): array
{
    $r = db()->one('SELECT SUM(rating_avg * rating_count) AS pts, SUM(rating_count) AS n FROM products WHERE seller_id = ? AND rating_count > 0', [$sellerId]) ?: [];
    $n = (int) ($r['n'] ?? 0);
    return ['count' => $n, 'average' => $n > 0 ? round((float) $r['pts'] / $n, 2) : null];
}
