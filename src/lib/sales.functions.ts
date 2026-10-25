import { createServerFn } from "@tanstack/react-start";
import type { SaleItem, SalesOverview } from "@/lib/types";

export const getSalesOverview = createServerFn({ method: "GET" }).handler(async (): Promise<SalesOverview> => {
  const { requireVerifiedSeller } = await import("@/server/session.server");
  const { getSql } = await import("@/server/db.server");
  const { commissionPercent, holdDays } = await import("@/server/marketplace.server");
  const user = await requireVerifiedSeller();
  const sql = getSql();
  // Money in and out of the wallet. A sale is "pending" until its payout hold has passed.
  const balances = await sql<SalesOverview["balances"]>`
    SELECT currency,
      COALESCE(sum(amount) FILTER (WHERE available_at <= now()), 0)::float8 AS available,
      COALESCE(sum(amount) FILTER (WHERE available_at > now()), 0)::float8 AS pending,
      COALESCE(sum(amount), 0)::float8 AS total
    FROM wallet_entries WHERE seller_id = ${user.id} GROUP BY currency ORDER BY currency`;
  const sales = await sql<SalesOverview["sales_30d"]>`
    SELECT o.currency, COALESCE(sum(i.line_total), 0)::float8 AS gross, COALESCE(sum(i.net), 0)::float8 AS net,
      COALESCE(sum(i.quantity), 0)::int AS units
    FROM order_items i JOIN orders o ON o.id = i.order_id
    WHERE i.seller_id = ${user.id} AND i.status = 'paid' AND o.placed_at > now() - interval '30 days'
    GROUP BY o.currency ORDER BY o.currency`;
  return { balances: [...balances], sales_30d: [...sales], commission_percent: commissionPercent(), hold_days: holdDays() };
});

export const listMySales = createServerFn({ method: "GET" }).handler(async (): Promise<SaleItem[]> => {
  const { requireVerifiedSeller } = await import("@/server/session.server");
  const { getSql, plain } = await import("@/server/db.server");
  const user = await requireVerifiedSeller();
  const rows = await getSql()<SaleItem[]>`
    SELECT i.id, o.number AS order_number, o.placed_at, i.name, i.sku, i.options, i.quantity,
      i.unit_price::float8 AS unit_price, i.line_total::float8 AS line_total, i.commission::float8 AS commission, i.net::float8 AS net,
      o.currency, i.status, o.customer_name, o.ship_city, o.ship_state
    FROM order_items i JOIN orders o ON o.id = i.order_id
    WHERE i.seller_id = ${user.id}
    ORDER BY o.placed_at DESC, i.created_at DESC LIMIT 200`;
  return rows.map((row) => plain(row));
});
