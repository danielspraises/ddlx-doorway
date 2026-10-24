import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import type { Failure, Product } from "@/lib/types";

const fields = z.object({
  name: z.string().trim().min(1, "Product name is required").max(200),
  description: z.string().trim().max(5000),
  sku: z.string().trim().min(1, "SKU is required").max(100),
  category: z.string().trim().min(1).max(60),
  price: z.number().min(0, "Price cannot be negative").max(1_000_000_000),
  currency: z.enum(["NGN", "USD", "GBP", "EUR"]),
  stock: z.number().int("Stock must be a whole number").min(0).max(1_000_000),
  low_stock_threshold: z.number().int().min(0).max(1_000_000),
  status: z.enum(["draft", "ready", "archived"]),
});

const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp"];
type One = { ok: true; product: Product } | Failure;

export const listMyProducts = createServerFn({ method: "GET" }).handler(async (): Promise<Product[]> => {
  const { requireVerifiedSeller } = await import("@/server/session.server");
  const { getSql, plain } = await import("@/server/db.server");
  const { productCols } = await import("@/server/products.server");
  const user = await requireVerifiedSeller();
  const sql = getSql();
  const rows = await sql<Product[]>`SELECT ${productCols(sql)} FROM seller_products WHERE user_id = ${user.id} ORDER BY updated_at DESC`;
  return rows.map((row) => plain(row));
});

export const saveProduct = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => fields.extend({ id: z.string().uuid().nullable() }).parse(input))
  .handler(async ({ data }): Promise<One> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, plain, isUniqueViolation } = await import("@/server/db.server");
    const { productCols } = await import("@/server/products.server");
    const user = await requireVerifiedSeller();
    const sql = getSql();
    const { id, ...f } = data;
    const price = Math.round(f.price * 100) / 100;
    try {
      const rows = id
        ? await sql<Product[]>`
            UPDATE seller_products SET name = ${f.name}, description = ${f.description}, sku = ${f.sku},
              category = ${f.category}, price = ${price}, currency = ${f.currency}, stock = ${f.stock},
              low_stock_threshold = ${f.low_stock_threshold}, status = ${f.status}, updated_at = now()
            WHERE id = ${id} AND user_id = ${user.id} RETURNING ${productCols(sql)}`
        : await sql<Product[]>`
            INSERT INTO seller_products (user_id, name, description, sku, category, price, currency, stock, low_stock_threshold, status)
            VALUES (${user.id}, ${f.name}, ${f.description}, ${f.sku}, ${f.category}, ${price}, ${f.currency}, ${f.stock}, ${f.low_stock_threshold}, ${f.status})
            RETURNING ${productCols(sql)}`;
      const row = rows[0];
      return row ? { ok: true, product: plain(row) } : { ok: false, error: "Product not found." };
    } catch (error) {
      if (isUniqueViolation(error)) return { ok: false, error: "You already have a product with this SKU." };
      throw error;
    }
  });

export const deleteProduct = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid() }).parse(input))
  .handler(async ({ data }): Promise<{ ok: true } | Failure> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const storage = await import("@/server/storage.server");
    const user = await requireVerifiedSeller();
    const rows = await getSql()<{ images: string[] }[]>`
      DELETE FROM seller_products WHERE id = ${data.id} AND user_id = ${user.id} RETURNING images`;
    const row = rows[0];
    if (!row) return { ok: false, error: "Product not found." };
    await Promise.all(row.images.filter((path) => storage.PRODUCT_PATH.test(path)).map((path) => storage.removeProductFile(path).catch(() => undefined)));
    return { ok: true };
  });

// "delta" adds or removes units (never below zero); "set" replaces the count.
export const adjustStock = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) =>
    z.object({ id: z.string().uuid(), mode: z.enum(["delta", "set"]), value: z.number().int().min(-1_000_000).max(1_000_000) }).parse(input),
  )
  .handler(async ({ data }): Promise<One> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, plain } = await import("@/server/db.server");
    const { productCols } = await import("@/server/products.server");
    const user = await requireVerifiedSeller();
    if (data.mode === "set" && data.value < 0) return { ok: false, error: "Stock cannot be negative." };
    const sql = getSql();
    const rows = data.mode === "set"
      ? await sql<Product[]>`UPDATE seller_products SET stock = ${data.value}, updated_at = now()
          WHERE id = ${data.id} AND user_id = ${user.id} RETURNING ${productCols(sql)}`
      : await sql<Product[]>`UPDATE seller_products SET stock = greatest(0, least(1000000, stock + ${data.value})), updated_at = now()
          WHERE id = ${data.id} AND user_id = ${user.id} RETURNING ${productCols(sql)}`;
    const row = rows[0];
    return row ? { ok: true, product: plain(row) } : { ok: false, error: "Product not found." };
  });

export const uploadProductImage = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) =>
    z.object({ product_id: z.string().uuid(), content_type: z.string().max(100), data_base64: z.string().min(1).max(7_500_000) }).parse(input),
  )
  .handler(async ({ data }): Promise<One> => {
    const { randomUUID } = await import("node:crypto");
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, plain } = await import("@/server/db.server");
    const { productCols } = await import("@/server/products.server");
    const storage = await import("@/server/storage.server");
    const user = await requireVerifiedSeller();
    const bytes = Buffer.from(data.data_base64, "base64");
    if (!IMAGE_TYPES.includes(data.content_type) || bytes.length === 0 || bytes.length > MAX_IMAGE_BYTES || !storage.matchesDeclaredType(data.content_type, bytes)) {
      return { ok: false, error: "Choose a JPG, PNG or WebP photo up to 5 MB." };
    }
    const sql = getSql();
    const owned = await sql<{ n: number }[]>`SELECT cardinality(images) AS n FROM seller_products WHERE id = ${data.product_id} AND user_id = ${user.id}`;
    if (!owned[0]) return { ok: false, error: "Product not found." };
    if (owned[0].n >= 6) return { ok: false, error: "A product can have up to six photos." };
    const path = `${user.id}/${data.product_id}/${randomUUID()}.${storage.EXTENSION[data.content_type] ?? "jpg"}`;
    await storage.saveProductFile(path, bytes);
    const rows = await sql<Product[]>`
      UPDATE seller_products SET images = array_append(images, ${path}), updated_at = now()
      WHERE id = ${data.product_id} AND user_id = ${user.id} AND cardinality(images) < 6 RETURNING ${productCols(sql)}`;
    const row = rows[0];
    if (!row) {
      await storage.removeProductFile(path).catch(() => undefined);
      return { ok: false, error: "A product can have up to six photos." };
    }
    return { ok: true, product: plain(row) };
  });

export const removeProductImage = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ product_id: z.string().uuid(), path: z.string().max(300) }).parse(input))
  .handler(async ({ data }): Promise<One> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, plain } = await import("@/server/db.server");
    const { productCols } = await import("@/server/products.server");
    const storage = await import("@/server/storage.server");
    const user = await requireVerifiedSeller();
    const sql = getSql();
    const rows = await sql<Product[]>`
      UPDATE seller_products SET images = array_remove(images, ${data.path}), updated_at = now()
      WHERE id = ${data.product_id} AND user_id = ${user.id} AND ${data.path} = ANY(images) RETURNING ${productCols(sql)}`;
    const row = rows[0];
    if (!row) return { ok: false, error: "Photo not found." };
    if (storage.PRODUCT_PATH.test(data.path)) await storage.removeProductFile(data.path).catch(() => undefined);
    return { ok: true, product: plain(row) };
  });

export const getProductImage = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ path: z.string().max(300) }).parse(input))
  .handler(async ({ data }): Promise<{ ok: true; content_type: string; data_base64: string } | Failure> => {
    const { requireUser } = await import("@/server/session.server");
    const storage = await import("@/server/storage.server");
    const user = await requireUser();
    const match = storage.PRODUCT_PATH.exec(data.path);
    if (!match) return { ok: false, error: "Invalid photo." };
    if (match[1] !== user.id && !user.isAdmin) return { ok: false, error: "You do not have access to this photo." };
    try {
      const bytes = await storage.readProductFile(data.path);
      return { ok: true, content_type: storage.CONTENT_TYPE[match[3] ?? ""] ?? "application/octet-stream", data_base64: bytes.toString("base64") };
    } catch {
      return { ok: false, error: "The photo could not be found." };
    }
  });
