# Marketplace integration

Vendor Hub (vendor.ddlx.shop) is the seller platform. The marketplace (**ddlx.shop** in production, **ddlxapparel.com** while testing)
shows the products sellers create here and reports the sales back. Two things connect them:

| Direction | What | Endpoint |
| --- | --- | --- |
| Marketplace pulls from Vendor Hub | Approved products, grouped under their seller, with live stock | `GET /api/marketplace/products` |
| Marketplace pushes to Vendor Hub | A paid order, or a refund | `POST /api/marketplace/orders` |

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
Use different values for testing (ddlxapparel.com) and production (ddlx.shop).

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
  "brand": "", "category": "T-shirts & Polos", "category_path": ["Fashion", "Men", "Clothing", "T-shirts & Polos"], "tags": ["ankara"],
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
- Vendor Hub takes the commission, credits the seller's wallet (withdrawable after the hold period), lowers stock,
  and shows the sale on the seller's screen immediately.
- A refund reverses every line of the order: `{"event":"order.refunded","order":{"id":"1042","restock":true, ...same fields}}`.
  `restock: true` puts the units back into stock.

### Order status (both directions)

One order, one lifecycle, shared by both systems:

| Status | Who moves it | How the other side hears |
|---|---|---|
| New order (paid) | marketplace | `order.paid` |
| Processing | seller, in Vendor Hub | Vendor Hub calls `POST {marketplace}/wp-json/vendor-hub/v1/order-status` |
| Ready for shipping | seller, in Vendor Hub | same call |
| Shipped, Delivered, Completed | admin, in the marketplace | `order.status` |
| Refunded / Cancelled | admin, in the marketplace | `order.refunded` |

Vendor Hub to marketplace (Bearer API key): `{"order_id":"1042","seller_id":"<uuid>","status":"processing"|"ready_for_shipping","product_ids":["<uuid>"]}`.
If the marketplace cannot be reached the seller sees an error and nothing changes, so the two never disagree. When every seller on an
order has packed their items the plugin moves the WooCommerce order to **Ready for shipping**.

Marketplace to Vendor Hub (signed like orders):
`{"event":"order.status","order":{"id":"1042","status":"shipped","carrier":"DHL","tracking_number":"123","note":""}}`
with `status` one of `ready_for_shipping`, `shipped`, `delivered`, `completed`. Statuses only move forward; a late or repeated
message is ignored (carrier and tracking are still updated). `404` means the order is unknown, so send `order.paid` first.

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

## WooCommerce plugin

`wordpress-plugin/verola-connector` is a ready-made WooCommerce plugin for this contract.

1. Zip the `verola-connector` folder (PowerShell: `Compress-Archive wordpress-plugin\verola-connector verola-connector.zip`).
2. In WordPress: Plugins > Add New > Upload Plugin, install and activate it. WooCommerce must be active.
3. Open WooCommerce > Vendor Hub. Enter the Vendor Hub address, the API key and the webhook secret, then press
   "Sync products now". (Or put `VEROLA_URL`, `VEROLA_API_KEY` and `VEROLA_WEBHOOK_SECRET` in `wp-config.php`.)

What it does:

- Every minute it pulls approved products and creates or updates them in WooCommerce: simple and variable products,
  variations, prices, scheduled sale dates, stock, photos, category (the full `category_path` is created as nested WooCommerce categories), tags and brand. Taken-down products become drafts.
- Each Vendor Hub seller becomes a WordPress user with Dokan's `seller` role and owns their products, so the products
  appear in their Dokan store (`/store/<store-slug>`) with the store name from their application.
- When an order is paid it reports the Vendor Hub items; when an order is marked refunded or cancelled it reports the refund.
- Adds the order statuses **Ready for shipping**, **Shipped** and **Delivered**. The admin sets Shipped, Delivered and Completed in WooCommerce
  (with the carrier and tracking number fields on the order screen) and Vendor Hub follows. Sellers set Processing and Ready for shipping in Vendor Hub.
  Sends happen in the background and are retried for a few hours if Vendor Hub is unreachable.
- Vendor Hub is the source of truth for stock: stock is overwritten from Vendor Hub on each sync.

WordPress's schedule only runs when the site gets visits. For a steady one-minute rhythm add
`define('DISABLE_WP_CRON', true);` to `wp-config.php` and have the host run `wp-cron.php` every minute.
Partial refunds are not reported yet. Orders are only sent while the order has Vendor Hub items.

### Dokan settings to change

Vendor Hub is where sellers manage products and money, so switch off the overlapping parts of Dokan
(names vary a little between Dokan versions):

- **Commission:** set Dokan's commission to 0%. Vendor Hub takes its own commission (`COMMISSION_PERCENT`) from each sale;
  charging both would take it twice.
- **Withdrawals:** turn off Dokan's withdraw feature. Sellers withdraw in Vendor Hub.
- **Product editing:** stop vendors from adding or editing products in Dokan. Products are created in Vendor Hub, and anything
  edited in Dokan is overwritten at the next sync.
- **Orders:** Dokan splits an order that contains several sellers' items into one sub-order per seller. The plugin reports only
  the main order, which already holds every item, so nothing is counted twice.

### SKUs

SKUs must be unique across the whole store. Vendor Hub fills in the next free SKU for each new product (for example
`DEMO-0007`, from the seller's store name) and checks every SKU before saving. Set `MARKETPLACE_URL` in Vendor Hub's `.env`
and the plugin answers two questions over WordPress REST, using the same API key:

- `GET  /wp-json/vendor-hub/v1/sku/next?prefix=DEMO` returns `{"sku":"DEMO-0007"}`, the next number after the highest in the store.
- `POST /wp-json/vendor-hub/v1/sku/check` with `{"items":[{"sku","product_id","variation_id"}]}` returns `{"conflicts":[...]}`,
  the SKUs already used by something other than that product.

If the marketplace cannot be reached, Vendor Hub still checks against its own sellers and carries on.

## Store profile in the feed

Each product's `seller` object now carries `profile`: `tagline`, `description`, `phone`, `support_email`,
`return_policy`, `shipping_policy`, `logo`, `banner` (public image URLs), `vacation`, `vacation_message`.
While a seller is on vacation their products come back with `live: false`, so the marketplace hides them
and shows them again when vacation ends. The connector maps the profile onto the Dokan store page.
