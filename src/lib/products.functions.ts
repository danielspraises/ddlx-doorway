import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import { readinessProblems, slugify, validateProduct } from "@/lib/product-rules";
import type { Failure, Product } from "@/lib/types";

const money = z.number().min(0, "Prices cannot be negative").max(1_000_000_000).nullable();
const when = z.string().datetime({ offset: true }).nullable();
const measure = z.number().min(0).max(100_000).nullable();
const word = z.string().trim().max(60);

const variationInput = z.object({
  id: z.string().uuid().nullable(),
  sku: z.string().trim().max(100),
  options: z.record(z.string().max(60), z.string().max(60)),
  regular_price: money,
  sale_price: money,
  sale_starts_at: when,
  sale_ends_at: when,
  stock: z.number().int("Stock must be a whole number").min(0).max(1_000_000),
  enabled: z.boolean(),
});

const productInput = z.object({
  id: z.string().uuid().nullable(),
  name: z.string().trim().max(200),
  sku: z.string().trim().max(100),
  product_type: z.enum(["simple", "variable"]),
  brand: z.string().trim().max(100),
  category_id: z.string().uuid().nullable(),
  tags: z.array(word.min(1)).max(20),
  short_description: z.string().trim().max(500),
  description: z.string().trim().max(10_000),
  currency: z.enum(["NGN", "USD", "GBP", "EUR"]),
  regular_price: money,
  sale_price: money,
  sale_starts_at: when,
  sale_ends_at: when,
  manage_stock: z.boolean(),
  stock: z.number().int("Stock must be a whole number").min(0).max(1_000_000),
  low_stock_threshold: z.number().int().min(0).max(1_000_000),
  allow_backorders: z.boolean(),
  weight_kg: measure,
  length_cm: measure,
  width_cm: measure,
  height_cm: measure,
  attributes: z.array(z.object({ name: word, values: z.array(word).max(20) })).max(3),
  variations: z.array(variationInput).max(100),
});

const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp"];
const MAX_GALLERY = 8;
type One = { ok: true; product: Product } | Failure;
const fail = (error: string): Failure => ({ ok: false, error });
const cents = (value: number | null) => (value === null ? null : Math.round(value * 100) / 100);

export const listMyProducts = createServerFn({ method: "GET" }).handler(async (): Promise<Product[]> => {
  const { requireVerifiedSeller } = await import("@/server/session.server");
  const { getSql, plain } = await import("@/server/db.server");
  const { productCols } = await import("@/server/products.server");
  const user = await requireVerifiedSeller();
  const rows = await getSql()<Product[]>`SELECT ${productCols()} FROM products p WHERE p.seller_id = ${user.id} ORDER BY p.updated_at DESC`;
  return rows.map((row) => plain(row));
});

// Creates or updates a product and all of its variations in one step.
// Any content change to an approved product makes it a draft again; the marketplace keeps showing
// the last approved version until the new one is approved.
export const saveProduct = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => productInput.parse(input))
  .handler(async ({ data }): Promise<One> => {
    const { randomUUID } = await import("node:crypto");
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, isUniqueViolation } = await import("@/server/db.server");
    const { loadProduct, removeUnreferenced, skuProblem } = await import("@/server/products.server");
    const user = await requireVerifiedSeller();
    const problem = validateProduct(data);
    if (problem) return fail(problem);
    if (data.id && !(await owned(data.id, user.id))) return fail("Product not found.");
    const skuIssue = await skuProblem(data.id, data);
    if (skuIssue) return fail(skuIssue);
    const sql = getSql();
    // The chosen category must exist and be switched on (a product may keep one that was hidden later).
    let categoryName = "";
    if (data.category_id) {
      const found = await sql<{ name: string; active: boolean; current: boolean }[]>`
        SELECT c.name, c.active, (p.id IS NOT NULL) AS current
        FROM categories c LEFT JOIN products p ON p.id = ${data.id}::uuid AND p.category_id = c.id
        WHERE c.id = ${data.category_id}`;
      const row = found[0];
      if (!row || (!row.active && !row.current)) return fail("Choose a category from the list.");
      categoryName = row.name;
    }
    const variable = data.product_type === "variable";
    const tags = [...new Set(data.tags.map((t) => t.toLowerCase()))];
    const lowImages: string[] = [];

    try {
      const outcome = await sql.begin(async (tx): Promise<{ error: string } | { id: string }> => {
        let id = data.id;
        if (id) {
          const current = await tx<{ status: string }[]>`SELECT status FROM products WHERE id = ${id} AND seller_id = ${user.id} FOR UPDATE`;
          const row = current[0];
          if (!row) return { error: "Product not found." };
          if (row.status === "pending_review") return { error: "This product is in review. Withdraw it from review to edit it." };
          await tx`
            UPDATE products SET name = ${data.name}, sku = ${data.sku}, product_type = ${data.product_type}, brand = ${data.brand},
              category = ${categoryName}, category_id = ${data.category_id}, tags = ${tx.array(tags)}, short_description = ${data.short_description},
              description = ${data.description}, currency = ${data.currency},
              regular_price = ${cents(data.regular_price)}, sale_price = ${cents(data.sale_price)},
              sale_starts_at = ${data.sale_starts_at}, sale_ends_at = ${data.sale_ends_at},
              manage_stock = ${data.manage_stock}, stock = ${data.stock}, low_stock_threshold = ${data.low_stock_threshold},
              allow_backorders = ${data.allow_backorders}, weight_kg = ${data.weight_kg}, length_cm = ${data.length_cm},
              width_cm = ${data.width_cm}, height_cm = ${data.height_cm}, attributes = ${tx.json(data.attributes as never)},
              status = CASE WHEN status = 'approved' THEN 'draft' ELSE status END,
              updated_at = date_trunc('milliseconds', now())
            WHERE id = ${id}`;
        } else {
          id = randomUUID();
          const slug = `${slugify(data.name)}-${id.slice(0, 6)}`;
          await tx`
            INSERT INTO products (id, seller_id, name, slug, sku, product_type, brand, category, category_id, tags, short_description, description,
              currency, regular_price, sale_price, sale_starts_at, sale_ends_at, manage_stock, stock, low_stock_threshold,
              allow_backorders, weight_kg, length_cm, width_cm, height_cm, attributes)
            VALUES (${id}, ${user.id}, ${data.name}, ${slug}, ${data.sku}, ${data.product_type}, ${data.brand}, ${categoryName}, ${data.category_id},
              ${tx.array(tags)}, ${data.short_description}, ${data.description}, ${data.currency},
              ${cents(data.regular_price)}, ${cents(data.sale_price)}, ${data.sale_starts_at}, ${data.sale_ends_at},
              ${data.manage_stock}, ${data.stock}, ${data.low_stock_threshold}, ${data.allow_backorders},
              ${data.weight_kg}, ${data.length_cm}, ${data.width_cm}, ${data.height_cm}, ${tx.json(data.attributes as never)})`;
        }

        const existing = await tx<{ id: string; image: string | null }[]>`SELECT id, image FROM product_variations WHERE product_id = ${id}`;
        const incoming = variable ? data.variations : [];
        const known = new Set(existing.map((v) => v.id));
        if (incoming.some((v) => v.id && !known.has(v.id))) return { error: "A variation could not be found. Refresh and try again." };
        const keep = new Set(incoming.map((v) => v.id).filter((value): value is string => !!value));
        for (const gone of existing.filter((v) => !keep.has(v.id))) {
          await tx`DELETE FROM product_variations WHERE id = ${gone.id}`;
          if (gone.image) lowImages.push(gone.image);
        }
        // SKUs move between variations safely: park them, then set the final values.
        for (const [index, v] of incoming.entries()) {
          if (v.id) {
            await tx`UPDATE product_variations SET sku = ${`${v.id}`}, position = ${index} WHERE id = ${v.id}`;
          }
        }
        for (const [index, v] of incoming.entries()) {
          if (v.id) {
            await tx`
              UPDATE product_variations SET sku = ${v.sku}, options = ${tx.json(v.options as never)},
                regular_price = ${cents(v.regular_price)}, sale_price = ${cents(v.sale_price)},
                sale_starts_at = ${v.sale_starts_at}, sale_ends_at = ${v.sale_ends_at}, stock = ${v.stock},
                enabled = ${v.enabled}, position = ${index}
              WHERE id = ${v.id} AND product_id = ${id}`;
          } else {
            await tx`
              INSERT INTO product_variations (id, product_id, sku, options, regular_price, sale_price, sale_starts_at, sale_ends_at, stock, enabled, position)
              VALUES (${randomUUID()}, ${id}, ${v.sku}, ${tx.json(v.options as never)}, ${cents(v.regular_price)}, ${cents(v.sale_price)},
                ${v.sale_starts_at}, ${v.sale_ends_at}, ${v.stock}, ${v.enabled}, ${index})`;
          }
        }
        return { id };
      });
      if ("error" in outcome) return fail(outcome.error);
      await removeUnreferenced(outcome.id, lowImages);
      const product = await loadProduct(outcome.id);
      if (!product) return fail("Product not found.");
      const { plain } = await import("@/server/db.server");
      return { ok: true, product: plain(product) };
    } catch (error) {
      if (isUniqueViolation(error)) return fail("That SKU is already taken. Press Generate to get a free one.");
      throw error;
    }
  });

async function owned(id: string, sellerId: string) {
  const { loadProduct } = await import("@/server/products.server");
  const product = await loadProduct(id);
  return product && product.seller_id === sellerId ? product : null;
}

// A free SKU in the style "DEMO-0001". The marketplace picks it when connected, so it cannot clash with its own products.
export const suggestSku = createServerFn({ method: "POST" }).handler(async (): Promise<{ ok: true; sku: string } | Failure> => {
  const { requireVerifiedSeller } = await import("@/server/session.server");
  const { skuPrefix, localNextSku, skuProblem } = await import("@/server/products.server");
  const { marketplaceNextSku } = await import("@/server/marketplace-sku.server");
  const user = await requireVerifiedSeller();
  const prefix = await skuPrefix(user.id);
  for (let attempt = 0; attempt < 5; attempt++) {
    const sku = (await marketplaceNextSku(prefix)) ?? (await localNextSku(prefix));
    if (!(await skuProblem(null, { sku, product_type: "simple", variations: [] }))) return { ok: true, sku };
  }
  return fail("Could not find a free SKU. Type one in instead.");
});

export const submitProduct = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid() }).parse(input))
  .handler(async ({ data }): Promise<One> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, plain } = await import("@/server/db.server");
    const { loadProduct } = await import("@/server/products.server");
    const { announce } = await import("@/server/events.server");
    const user = await requireVerifiedSeller();
    const product = await owned(data.id, user.id);
    if (!product) return fail("Product not found.");
    if (!["draft", "changes_requested", "rejected"].includes(product.status)) return fail("This product cannot be submitted right now.");
    const problems = readinessProblems(product);
    if (problems.length) return fail(problems[0] ?? "Complete the product first.");
    const sub = await getSql()<{ n: number }[]>`SELECT count(*)::int AS n FROM categories WHERE parent_id = ${product.category_id}`;
    if ((sub[0]?.n ?? 0) > 0) return fail("Choose the most specific category for this product.");
    const rows = await getSql()<{ id: string }[]>`
      UPDATE products SET status = 'pending_review', submitted_at = now(), updated_at = date_trunc('milliseconds', now())
      WHERE id = ${data.id} AND seller_id = ${user.id} AND status IN ('draft', 'changes_requested', 'rejected') RETURNING id`;
    if (!rows[0]) return fail("This product cannot be submitted right now.");
    await announce({ audience: "reviewers", type: "review_queue", message: `${product.name} is waiting for review` });
    const fresh = await loadProduct(data.id);
    return fresh ? { ok: true, product: plain(fresh) } : fail("Product not found.");
  });

export const withdrawSubmission = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid() }).parse(input))
  .handler(async ({ data }): Promise<One> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, plain } = await import("@/server/db.server");
    const { loadProduct } = await import("@/server/products.server");
    const { announce } = await import("@/server/events.server");
    const user = await requireVerifiedSeller();
    const rows = await getSql()<{ id: string }[]>`
      UPDATE products SET status = 'draft', updated_at = date_trunc('milliseconds', now())
      WHERE id = ${data.id} AND seller_id = ${user.id} AND status = 'pending_review' RETURNING id`;
    if (!rows[0]) return fail("This product is not in review.");
    await announce({ audience: "reviewers", type: "review_queue" });
    const fresh = await loadProduct(data.id);
    return fresh ? { ok: true, product: plain(fresh) } : fail("Product not found.");
  });

// Take an approved product off the marketplace, or put the approved version back.
export const setProductLive = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid(), live: z.boolean() }).parse(input))
  .handler(async ({ data }): Promise<One> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, plain } = await import("@/server/db.server");
    const { loadProduct } = await import("@/server/products.server");
    const user = await requireVerifiedSeller();
    const sql = getSql();
    const rows = data.live
      ? await sql<{ id: string }[]>`
          UPDATE products SET live = true, updated_at = date_trunc('milliseconds', now())
          WHERE id = ${data.id} AND seller_id = ${user.id} AND status = 'approved' AND published_snapshot IS NOT NULL AND NOT live RETURNING id`
      : await sql<{ id: string }[]>`
          UPDATE products SET live = false, updated_at = date_trunc('milliseconds', now())
          WHERE id = ${data.id} AND seller_id = ${user.id} AND live RETURNING id`;
    if (!rows[0]) return fail(data.live ? "Only an approved product can go back on the marketplace." : "This product is not live.");
    const fresh = await loadProduct(data.id);
    return fresh ? { ok: true, product: plain(fresh) } : fail("Product not found.");
  });

export const deleteProduct = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid() }).parse(input))
  .handler(async ({ data }): Promise<{ ok: true } | Failure> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const { imagePaths } = await import("@/server/products.server");
    const storage = await import("@/server/storage.server");
    const user = await requireVerifiedSeller();
    const product = await owned(data.id, user.id);
    if (!product) return fail("Product not found.");
    if (product.live) return fail("Take this product off the marketplace before deleting it.");
    if (product.status === "pending_review") return fail("Withdraw this product from review before deleting it.");
    const snapshot = await getSql()<{ paths: string[] }[]>`
      SELECT ARRAY(SELECT jsonb_array_elements_text(COALESCE(published_snapshot->'image_paths', '[]'::jsonb))) AS paths FROM products WHERE id = ${data.id}`;
    const rows = await getSql()<{ id: string }[]>`DELETE FROM products WHERE id = ${data.id} AND seller_id = ${user.id} AND NOT live RETURNING id`;
    if (!rows[0]) return fail("Product not found.");
    const files = [...imagePaths(product), ...(snapshot[0]?.paths ?? [])];
    await Promise.all([...new Set(files)].filter((path) => storage.PRODUCT_PATH.test(path)).map((path) => storage.removeProductFile(path).catch(() => undefined)));
    return { ok: true };
  });

// "delta" adds or removes units (never below zero); "set" replaces the count.
// Stock is not part of the reviewed content, so it changes straight away, even on a live product.
export const adjustStock = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) =>
    z.object({
      id: z.string().uuid(),
      variation_id: z.string().uuid().nullable(),
      mode: z.enum(["delta", "set"]),
      value: z.number().int().min(-1_000_000).max(1_000_000),
    }).parse(input),
  )
  .handler(async ({ data }): Promise<One> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, plain } = await import("@/server/db.server");
    const { loadProduct } = await import("@/server/products.server");
    const user = await requireVerifiedSeller();
    if (data.mode === "set" && data.value < 0) return fail("Stock cannot be negative.");
    const sql = getSql();
    const changed = await sql.begin(async (tx) => {
      const mine = await tx<{ id: string }[]>`SELECT id FROM products WHERE id = ${data.id} AND seller_id = ${user.id} FOR UPDATE`;
      if (!mine[0]) return false;
      if (data.variation_id) {
        const rows = data.mode === "set"
          ? await tx<{ id: string }[]>`UPDATE product_variations SET stock = ${data.value} WHERE id = ${data.variation_id} AND product_id = ${data.id} RETURNING id`
          : await tx<{ id: string }[]>`UPDATE product_variations SET stock = greatest(0, least(1000000, stock + ${data.value})) WHERE id = ${data.variation_id} AND product_id = ${data.id} RETURNING id`;
        if (!rows[0]) return false;
      } else {
        if (data.mode === "set") await tx`UPDATE products SET stock = ${data.value} WHERE id = ${data.id}`;
        else await tx`UPDATE products SET stock = greatest(0, least(1000000, stock + ${data.value})) WHERE id = ${data.id}`;
      }
      await tx`UPDATE products SET updated_at = date_trunc('milliseconds', now()) WHERE id = ${data.id}`;
      return true;
    });
    if (!changed) return fail("Product not found.");
    const fresh = await loadProduct(data.id);
    return fresh ? { ok: true, product: plain(fresh) } : fail("Product not found.");
  });

const slotInput = z.object({
  product_id: z.string().uuid(),
  slot: z.enum(["featured", "gallery", "variation"]),
  variation_id: z.string().uuid().nullable(),
  content_type: z.string().max(100),
  data_base64: z.string().min(1).max(7_500_000),
});

export const uploadProductImage = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => slotInput.parse(input))
  .handler(async ({ data }): Promise<One> => {
    const { randomUUID } = await import("node:crypto");
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, plain } = await import("@/server/db.server");
    const { loadProduct, removeUnreferenced } = await import("@/server/products.server");
    const storage = await import("@/server/storage.server");
    const user = await requireVerifiedSeller();
    const bytes = Buffer.from(data.data_base64, "base64");
    if (!IMAGE_TYPES.includes(data.content_type) || bytes.length === 0 || bytes.length > MAX_IMAGE_BYTES || !storage.matchesDeclaredType(data.content_type, bytes)) {
      return fail("Choose a JPG, PNG or WebP photo up to 5 MB.");
    }
    if (data.slot === "variation" && !data.variation_id) return fail("Choose a variation first.");
    const product = await owned(data.product_id, user.id);
    if (!product) return fail("Product not found.");
    if (product.status === "pending_review") return fail("This product is in review. Withdraw it from review to change photos.");
    if (data.slot === "gallery" && product.gallery.length >= MAX_GALLERY) return fail(`The gallery can hold up to ${MAX_GALLERY} photos.`);

    const path = `${user.id}/${data.product_id}/${randomUUID()}.${storage.EXTENSION[data.content_type] ?? "jpg"}`;
    await storage.saveProductFile(path, bytes);
    const sql = getSql();
    const touch = sql`status = CASE WHEN status = 'approved' THEN 'draft' ELSE status END, updated_at = date_trunc('milliseconds', now())`;
    let replaced: string | null = null;
    let done = false;
    if (data.slot === "featured") {
      replaced = product.featured_image;
      done = (await sql`UPDATE products SET featured_image = ${path}, ${touch} WHERE id = ${data.product_id} AND seller_id = ${user.id} AND status <> 'pending_review' RETURNING id`).length > 0;
    } else if (data.slot === "gallery") {
      done = (await sql`UPDATE products SET gallery = array_append(gallery, ${path}), ${touch} WHERE id = ${data.product_id} AND seller_id = ${user.id} AND status <> 'pending_review' AND cardinality(gallery) < ${MAX_GALLERY} RETURNING id`).length > 0;
    } else {
      replaced = product.variations.find((v) => v.id === data.variation_id)?.image ?? null;
      const found = await sql`UPDATE product_variations SET image = ${path} WHERE id = ${data.variation_id} AND product_id = ${data.product_id} RETURNING id`;
      if (found.length > 0) {
        await sql`UPDATE products SET ${touch} WHERE id = ${data.product_id}`;
        done = true;
      }
    }
    if (!done) {
      await storage.removeProductFile(path).catch(() => undefined);
      return fail("The photo could not be added. Refresh and try again.");
    }
    if (replaced) await removeUnreferenced(data.product_id, [replaced]);
    const fresh = await loadProduct(data.product_id);
    return fresh ? { ok: true, product: plain(fresh) } : fail("Product not found.");
  });

export const removeProductImage = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ product_id: z.string().uuid(), path: z.string().max(300) }).parse(input))
  .handler(async ({ data }): Promise<One> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, plain } = await import("@/server/db.server");
    const { loadProduct, removeUnreferenced } = await import("@/server/products.server");
    const user = await requireVerifiedSeller();
    const product = await owned(data.product_id, user.id);
    if (!product) return fail("Product not found.");
    if (product.status === "pending_review") return fail("This product is in review. Withdraw it from review to change photos.");
    const sql = getSql();
    await sql.begin(async (tx) => {
      await tx`UPDATE product_variations SET image = NULL WHERE product_id = ${data.product_id} AND image = ${data.path}`;
      await tx`
        UPDATE products SET featured_image = CASE WHEN featured_image = ${data.path} THEN NULL ELSE featured_image END,
          gallery = array_remove(gallery, ${data.path}),
          status = CASE WHEN status = 'approved' THEN 'draft' ELSE status END,
          updated_at = date_trunc('milliseconds', now())
        WHERE id = ${data.product_id} AND seller_id = ${user.id}`;
    });
    await removeUnreferenced(data.product_id, [data.path]);
    const fresh = await loadProduct(data.product_id);
    return fresh ? { ok: true, product: plain(fresh) } : fail("Product not found.");
  });

// Sellers see their own photos; reviewers and admins can see any seller's photos.
export const getProductImage = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ path: z.string().max(300) }).parse(input))
  .handler(async ({ data }): Promise<{ ok: true; content_type: string; data_base64: string } | Failure> => {
    const { requireUser } = await import("@/server/session.server");
    const storage = await import("@/server/storage.server");
    const user = await requireUser();
    const match = storage.PRODUCT_PATH.exec(data.path);
    if (!match) return fail("Invalid photo.");
    if (match[1] !== user.id && !user.isAdmin && !user.canReviewProducts) return fail("You do not have access to this photo.");
    try {
      const bytes = await storage.readProductFile(data.path);
      return { ok: true, content_type: storage.CONTENT_TYPE[match[3] ?? ""] ?? "application/octet-stream", data_base64: bytes.toString("base64") };
    } catch {
      return fail("The photo could not be found.");
    }
  });
