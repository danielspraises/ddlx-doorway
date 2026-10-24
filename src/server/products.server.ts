import { getSql } from "./db.server";

type Sql = ReturnType<typeof getSql>;

// The columns sent to the browser. numeric(12,2) would otherwise arrive as a string.
export const productCols = (sql: Sql) => sql`id, user_id, name, description, sku, category,
  price::float8 AS price, currency, stock, low_stock_threshold, status, images, created_at, updated_at`;
