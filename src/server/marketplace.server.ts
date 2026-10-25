import { createHmac, timingSafeEqual } from "node:crypto";
import { z } from "zod";
import { loadEnv } from "./env.server";
import { getSql } from "./db.server";
import { announce } from "./events.server";
import { slugify } from "../lib/product-rules";
import type { Snapshot } from "./products.server";

// The marketplace (verola.ng in production, ddlxapparel.com while testing) talks to Verola in two ways:
//   - it PULLS the catalogue of approved products:   GET  /api/marketplace/products
//   - it PUSHES sales and refunds (signed):           POST /api/marketplace/orders
// See docs/marketplace-api.md.

export const commissionPercent = () => {
  loadEnv();
  const value = Number(process.env["COMMISSION_PERCENT"] ?? "10");
  return Number.isFinite(value) && value >= 0 && value <= 100 ? value : 10;
};
export const holdDays = () => {
  loadEnv();
  const value = Number(process.env["PAYOUT_HOLD_DAYS"] ?? "7");
  return Number.isFinite(value) && value >= 0 && value <= 90 ? Math.floor(value) : 7;
};

const same = (a: string, b: string) => {
  const x = Buffer.from(a);
  const y = Buffer.from(b);
  return x.length === y.length && timingSafeEqual(x, y);
};

export function checkApiKey(request: Request): Response | null {
  loadEnv();
  const key = process.env["MARKETPLACE_API_KEY"];
  if (!key) return json({ error: "MARKETPLACE_API_KEY is not configured on Verola." }, 503);
  const header = request.headers.get("authorization") ?? "";
  if (!header.startsWith("Bearer ") || !same(header.slice(7), key)) return json({ error: "Invalid API key." }, 401);
  return null;
}

// Orders are signed: HMAC-SHA256 over "<unix timestamp>.<raw body>" with the shared secret.
export function checkSignature(request: Request, raw: string): Response | null {
  loadEnv();
  const secret = process.env["MARKETPLACE_WEBHOOK_SECRET"];
  if (!secret) return json({ error: "MARKETPLACE_WEBHOOK_SECRET is not configured on Verola." }, 503);
  const timestamp = request.headers.get("x-verola-timestamp") ?? "";
  const signature = (request.headers.get("x-verola-signature") ?? "").replace(/^sha256=/, "");
  const age = Math.abs(Date.now() / 1000 - Number(timestamp));
  if (!/^\d+$/.test(timestamp) || age > 300) return json({ error: "Missing or expired timestamp." }, 401);
  const expected = createHmac("sha256", secret).update(`${timestamp}.${raw}`).digest("hex");
  if (!same(signature, expected)) return json({ error: "Invalid signature." }, 401);
  return null;
}

export function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json", "cache-control": "no-store" } });
}

export const baseUrl = (request: Request) => {
  loadEnv();
  return (process.env["APP_URL"] || new URL(request.url).origin).replace(/\/$/, "");
};

// ---------------------------------------------------------------- catalogue feed

type FeedRow = {
  id: string;
  seller_id: string;
  live: boolean;
  snapshot: Snapshot;
  stock: number;
  variation_stock: Record<string, number>;
  updated_at: Date;
  published_at: Date | null;
  store_name: string | null;
  email: string | null;
  city: string | null;
  state: string | null;
};

// Approved products grouped under their seller. Stock is live; everything else is the approved version.
// Without updated_since only live products are returned. With it, every product changed since then is returned,
// including ones that went offline (live = false) so the marketplace can hide them.
export async function catalogue(request: Request, since: string | null, limit: number) {
  const sql = getSql();
  const base = baseUrl(request);
  const cols = sql`p.id, p.seller_id, p.live, p.published_snapshot AS snapshot, p.stock, p.updated_at, p.published_at,
      COALESCE((SELECT jsonb_object_agg(v.id::text, v.stock) FROM product_variations v WHERE v.product_id = p.id), '{}'::jsonb) AS variation_stock,
      a.brand->>'store_name' AS store_name, a.applicant_email AS email, a.business->>'city' AS city, a.business->>'state' AS state`;
  const from = sql`FROM products p LEFT JOIN seller_applications a ON a.user_id = p.seller_id`;
  const rows = since
    ? await sql<FeedRow[]>`SELECT ${cols} ${from} WHERE p.published_snapshot IS NOT NULL AND p.updated_at >= ${since} ORDER BY p.updated_at, p.id LIMIT ${limit}`
    : await sql<FeedRow[]>`SELECT ${cols} ${from} WHERE p.live ORDER BY p.updated_at, p.id LIMIT ${limit}`;
  const url = (path: string | null) => (path ? `${base}/api/media?p=${encodeURIComponent(path)}` : null);
  const products = rows.map((row) => {
    const s = row.snapshot;
    const storeName = row.store_name || "Verola seller";
    return {
      id: row.id,
      live: row.live,
      updated_at: row.updated_at.toISOString(),
      published_at: row.published_at?.toISOString() ?? null,
      seller: { id: row.seller_id, store_name: storeName, store_slug: `${slugify(storeName)}-${row.seller_id.slice(0, 6)}`, email: row.email, city: row.city, state: row.state },
      name: s.name, slug: s.slug, sku: s.sku, type: s.type, brand: s.brand, category: s.category, tags: s.tags,
      short_description: s.short_description, description: s.description, currency: s.currency,
      regular_price: s.regular_price, sale_price: s.sale_price, sale_starts_at: s.sale_starts_at, sale_ends_at: s.sale_ends_at,
      manage_stock: s.manage_stock, stock: row.stock, allow_backorders: s.allow_backorders,
      weight_kg: s.weight_kg, dimensions_cm: { length: s.length_cm, width: s.width_cm, height: s.height_cm },
      attributes: s.attributes,
      featured_image: url(s.featured_image),
      gallery: s.gallery.map((path) => url(path)),
      variations: s.variations.map((v) => ({
        id: v.id, sku: v.sku, options: v.options, regular_price: v.regular_price, sale_price: v.sale_price,
        sale_starts_at: v.sale_starts_at, sale_ends_at: v.sale_ends_at, stock: row.variation_stock[v.id] ?? 0, image: url(v.image),
      })),
    };
  });
  const last = rows[rows.length - 1];
  return { products, has_more: rows.length === limit, next_since: last ? last.updated_at.toISOString() : since };
}

// ---------------------------------------------------------------- sales

const orderSchema = z.object({
  event: z.enum(["order.paid", "order.refunded"]),
  order: z.object({
    id: z.string().min(1).max(100),
    number: z.string().max(100).optional(),
    currency: z.enum(["NGN", "USD", "GBP", "EUR"]),
    placed_at: z.string().datetime({ offset: true }),
    customer_name: z.string().max(200).optional(),
    ship_city: z.string().max(100).optional(),
    ship_state: z.string().max(100).optional(),
    restock: z.boolean().optional(),
    items: z.array(z.object({
      product_id: z.string().uuid(),
      variation_id: z.string().uuid().nullish(),
      quantity: z.number().int().min(1).max(10_000),
      unit_price: z.number().min(0).max(1_000_000_000),
    })).max(200),
  }),
});
export type OrderEvent = z.infer<typeof orderSchema>;
export const parseOrderEvent = (raw: string) => orderSchema.safeParse(JSON.parse(raw));

const money = (value: number) => Math.round(value * 100) / 100;

class Rejected extends Error {}

// Records a paid order: one line per product with the seller's commission, the wallet credit,
// and the stock taken off. Safe to repeat: an order id that was already recorded changes nothing.
export async function recordPaidOrder(event: OrderEvent): Promise<{ duplicate: boolean }> {
  const sql = getSql();
  const rate = commissionPercent();
  const hold = holdDays();
  const { order } = event;
  if (order.items.length === 0) throw new Rejected("The order has no items.");
  const sellers = new Map<string, { net: number; currency: string }>();
  const result = await sql.begin(async (tx) => {
    const inserted = await tx<{ id: string }[]>`
      INSERT INTO orders (marketplace_order_id, number, currency, customer_name, ship_city, ship_state, placed_at)
      VALUES (${order.id}, ${order.number ?? ""}, ${order.currency}, ${order.customer_name ?? ""}, ${order.ship_city ?? ""}, ${order.ship_state ?? ""}, ${order.placed_at})
      ON CONFLICT (marketplace_order_id) DO NOTHING RETURNING id`;
    const created = inserted[0];
    if (!created) return { duplicate: true };
    for (const item of order.items) {
      const found = await tx<{ seller_id: string; name: string; sku: string; manage_stock: boolean; product_type: string }[]>`
        SELECT seller_id, name, sku, manage_stock, product_type FROM products WHERE id = ${item.product_id} FOR UPDATE`;
      const product = found[0];
      if (!product) throw new Rejected(`Unknown product ${item.product_id}.`);
      let sku = product.sku;
      let options: Record<string, string> = {};
      if (item.variation_id) {
        const variation = await tx<{ sku: string; options: Record<string, string> }[]>`
          SELECT sku, options FROM product_variations WHERE id = ${item.variation_id} AND product_id = ${item.product_id}`;
        const v = variation[0];
        if (!v) throw new Rejected(`Unknown variation ${item.variation_id}.`);
        sku = v.sku;
        options = v.options;
      } else if (product.product_type === "variable") {
        throw new Rejected(`Product ${item.product_id} needs a variation_id.`);
      }
      const lineTotal = money(item.unit_price * item.quantity);
      const commission = money((lineTotal * rate) / 100);
      const net = money(lineTotal - commission);
      const line = await tx<{ id: string }[]>`
        INSERT INTO order_items (order_id, seller_id, product_id, variation_id, name, sku, options, quantity, unit_price, line_total, commission_rate, commission, net)
        VALUES (${created.id}, ${product.seller_id}, ${item.product_id}, ${item.variation_id ?? null}, ${product.name}, ${sku}, ${tx.json(options as never)},
          ${item.quantity}, ${money(item.unit_price)}, ${lineTotal}, ${rate}, ${commission}, ${net}) RETURNING id`;
      await tx`
        INSERT INTO wallet_entries (seller_id, kind, amount, currency, order_item_id, note, available_at)
        VALUES (${product.seller_id}, 'sale', ${net}, ${order.currency}, ${line[0]?.id ?? null}, ${`Order ${order.number || order.id}`},
          ${order.placed_at}::timestamptz + make_interval(days => ${hold}))`;
      if (item.variation_id) {
        await tx`UPDATE product_variations SET stock = greatest(0, stock - ${item.quantity}) WHERE id = ${item.variation_id}`;
      } else if (product.manage_stock) {
        await tx`UPDATE products SET stock = greatest(0, stock - ${item.quantity}) WHERE id = ${item.product_id}`;
      }
      await tx`UPDATE products SET updated_at = date_trunc('milliseconds', now()) WHERE id = ${item.product_id}`;
      const total = sellers.get(product.seller_id);
      sellers.set(product.seller_id, { net: money((total?.net ?? 0) + net), currency: order.currency });
    }
    return { duplicate: false };
  });
  if (!result.duplicate) {
    for (const [sellerId, total] of sellers) {
      await announce({ audience: "seller", seller_id: sellerId, type: "sale", message: `New sale: ${total.currency} ${total.net.toLocaleString("en")} earned` });
    }
  }
  return result;
}

// Reverses every line of an order: the wallet credit is taken back (on the same availability date as the sale)
// and, if the marketplace says so, the units go back into stock.
export async function recordRefund(event: OrderEvent): Promise<{ found: boolean }> {
  const sql = getSql();
  const sellers = new Set<string>();
  const found = await sql.begin(async (tx) => {
    const orders = await tx<{ id: string; currency: string }[]>`SELECT id, currency FROM orders WHERE marketplace_order_id = ${event.order.id} FOR UPDATE`;
    const order = orders[0];
    if (!order) return false;
    const lines = await tx<{ id: string; seller_id: string; product_id: string | null; variation_id: string | null; quantity: number; net: number }[]>`
      SELECT id, seller_id, product_id, variation_id, quantity, net::float8 AS net FROM order_items WHERE order_id = ${order.id} AND status = 'paid' FOR UPDATE`;
    for (const line of lines) {
      await tx`UPDATE order_items SET status = 'refunded' WHERE id = ${line.id}`;
      await tx`
        INSERT INTO wallet_entries (seller_id, kind, amount, currency, order_item_id, note, available_at)
        SELECT ${line.seller_id}, 'refund', ${-line.net}, ${order.currency}, ${line.id}, ${`Refund ${event.order.number || event.order.id}`}, w.available_at
        FROM wallet_entries w WHERE w.order_item_id = ${line.id} AND w.kind = 'sale'
        ON CONFLICT DO NOTHING`;
      if (event.order.restock) {
        if (line.variation_id) await tx`UPDATE product_variations SET stock = least(1000000, stock + ${line.quantity}) WHERE id = ${line.variation_id}`;
        else if (line.product_id) await tx`UPDATE products SET stock = least(1000000, stock + ${line.quantity}) WHERE id = ${line.product_id} AND manage_stock`;
        if (line.product_id) await tx`UPDATE products SET updated_at = date_trunc('milliseconds', now()) WHERE id = ${line.product_id}`;
      }
      sellers.add(line.seller_id);
    }
    return true;
  });
  for (const sellerId of sellers) await announce({ audience: "seller", seller_id: sellerId, type: "refund", message: `Order ${event.order.number || event.order.id} was refunded` });
  return { found };
}

export { Rejected };
