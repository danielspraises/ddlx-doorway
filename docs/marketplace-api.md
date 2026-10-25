# Marketplace integration

Verola is the seller platform. The marketplace (**verola.ng** in production, **ddlxapparel.com** while testing)
shows the products sellers create here and reports the sales back. Two things connect them:

| Direction | What | Endpoint |
| --- | --- | --- |
| Marketplace pulls from Verola | Approved products, grouped under their seller, with live stock | `GET /api/marketplace/products` |
| Marketplace pushes to Verola | A paid order, or a refund | `POST /api/marketplace/orders` |

Only products **approved** by an admin, or by a staff account an admin created with the "review and publish products"
permission, ever appear in the feed. Sellers cannot publish on their own.

## Settings (`.env`)

```
MARKETPLACE_API_KEY=<long random string>        # the marketplace sends this when it reads products
MARKETPLACE_WEBHOOK_SECRET=<long random string>  # the marketplace signs orders with this
COMMISSION_PERCENT=10                            # taken from each sale
PAYOUT_HOLD_DAYS=7                               # days before a sale can be withdrawn
APP_URL=https://verola-seller-site.example       # public address of this app (used in image links)
```

Generate secrets with `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"`.
Use different values for testing (ddlxapparel.com) and production (verola.ng).

## 1. Read products

```
GET /api/marketplace/products?updated_since=2026-10-24T10:00:00Z&limit=100
Authorization: Bearer <MARKETPLACE_API_KEY>
```

- Without `updated_since` you get every product that is live now.
- With `updated_since` you get everything that changed since then, including products that went offline
  (`"live": false`), so the marketplace can hide them. Run it every minute or two, or after a stock alert.
- `has_more` is true when there are more results; call again with `updated_since = next_since`.
  Records at the boundary can repeat, so update by `id` rather than inserting.

Each product:

```json
{
  "id": "uuid (use this as the product reference when reporting orders)",
  "live": true,
  "updated_at": "2026-10-24T10:00:00.000Z",
  "seller": { "id": "uuid", "store_name": "Ada Wears", "store_slug": "ada-wears-1a2b3c", "email": "...", "city": "Lagos", "state": "Lagos" },
  "name": "Ankara shirt", "slug": "ankara-shirt-1a2b3c", "sku": "ANK-1",
  "type": "simple | variable",
  "brand": "", "category": "Men's clothing", "tags": ["ankara"],
  "short_description": "", "description": "", "currency": "NGN",
  "regular_price": 12000, "sale_price": 9000,
  "sale_starts_at": "2026-11-01T00:00:00Z", "sale_ends_at": "2026-11-30T23:59:00Z",
  "manage_stock": true, "stock": 14, "allow_backorders": false,
  "weight_kg": 0.4, "dimensions_cm": { "length": 30, "width": 20, "height": 3 },
  "attributes": [{ "name": "Size", "values": ["S", "M", "L"] }],
  "featured_image": "https://.../api/media?p=...", "gallery": ["https://..."],
  "variations": [
    { "id": "uuid", "sku": "ANK-1-S", "options": { "Size": "S" }, "regular_price": 12000, "sale_price": null,
      "sale_starts_at": null, "sale_ends_at": null, "stock": 5, "image": null }
  ]
}
```

Create the product under the seller named in `seller` (one marketplace vendor per `seller.id`).
Sale prices apply only between `sale_starts_at` and `sale_ends_at` (either may be empty).
Image links are public and only work while the product is live.

## 2. Report a sale or refund

Send a JSON body and sign it. Signature = hex HMAC-SHA256 of `"<timestamp>.<raw body>"` with
`MARKETPLACE_WEBHOOK_SECRET`; the timestamp is the current Unix time in seconds and must be within 5 minutes.

```
POST /api/marketplace/orders
Content-Type: application/json
X-Verola-Timestamp: 1761300000
X-Verola-Signature: sha256=<hex>

{
  "event": "order.paid",
  "order": {
    "id": "1042",                       // the marketplace's own order id (unique)
    "number": "1042",
    "currency": "NGN",
    "placed_at": "2026-10-24T10:05:00Z",
    "customer_name": "Ife O.", "ship_city": "Ibadan", "ship_state": "Oyo",
    "items": [
      { "product_id": "<id from the feed>", "variation_id": "<variation id or omit>", "quantity": 2, "unit_price": 9000 }
    ]
  }
}
```

- `unit_price` is what the customer paid per unit (after any sale price or discount).
- Sending the same order id again is safe: nothing is counted twice (`{"ok":true,"duplicate":true}`).
- Verola takes the commission, credits the seller's wallet (withdrawable after the hold period), lowers stock,
  and shows the sale on the seller's screen immediately.
- A refund reverses every line of the order: `{"event":"order.refunded","order":{"id":"1042","restock":true, ...same fields}}`.
  `restock: true` puts the units back into stock.

Responses: `200` ok, `400` bad payload, `401` bad key or signature, `404` unknown order (refund), `422` unknown product,
`503` secrets not configured.

Example signing in PHP (WooCommerce / WordPress):

```php
$body = wp_json_encode($payload);
$ts = time();
$sig = hash_hmac('sha256', $ts . '.' . $body, VEROLA_WEBHOOK_SECRET);
wp_remote_post(VEROLA_URL . '/api/marketplace/orders', [
  'headers' => ['Content-Type' => 'application/json', 'X-Verola-Timestamp' => $ts, 'X-Verola-Signature' => 'sha256=' . $sig],
  'body' => $body,
]);
```
