import { getSql } from "./db.server";
import type { Product } from "../lib/types";

// The columns sent to the browser. numeric(12,2) would otherwise arrive as a string,
// so prices are cast to float8. Variations travel inside the product as JSON.
export const productCols = () => getSql()`
  p.id, p.seller_id, p.name, p.slug, p.product_type, p.sku, p.brand, p.category, p.category_id, category_path(p.category_id) AS category_path, p.tags,
  p.short_description, p.description, p.currency,
  p.regular_price::float8 AS regular_price, p.sale_price::float8 AS sale_price, p.sale_starts_at, p.sale_ends_at,
  p.manage_stock, p.stock, p.low_stock_threshold, p.allow_backorders,
  p.weight_kg::float8 AS weight_kg, p.length_cm::float8 AS length_cm, p.width_cm::float8 AS width_cm, p.height_cm::float8 AS height_cm,
  p.attributes, p.featured_image, p.gallery, p.status, p.live, p.admin_comment,
  p.submitted_at, p.reviewed_at, p.published_at, p.created_at, p.updated_at,
  COALESCE((SELECT json_agg(to_jsonb(v) - 'product_id' ORDER BY v.position, v.sku)
            FROM product_variations v WHERE v.product_id = p.id), '[]'::json) AS variations`;

export async function loadProduct(id: string): Promise<Product | null> {
  const sql = getSql();
  const rows = await sql<Product[]>`SELECT ${productCols()} FROM products p WHERE p.id = ${id}`;
  return rows[0] ?? null;
}

// Every file a product points at right now (featured image, gallery, variation images).
export function imagePaths(p: Pick<Product, "featured_image" | "gallery" | "variations">): string[] {
  const all = [p.featured_image, ...p.gallery, ...p.variations.map((v) => v.image)];
  return [...new Set(all.filter((path): path is string => !!path))];
}

// The approved version that the marketplace shows. Stock is deliberately left out:
// it is always read live, so a sale or a stock edit shows up without a new approval.
export function buildSnapshot(p: Product) {
  const variations = p.variations.filter((v) => v.enabled);
  const shown = { ...p, variations };
  return {
    id: p.id,
    slug: p.slug,
    name: p.name,
    sku: p.sku,
    type: p.product_type,
    brand: p.brand,
    category: p.category,
    category_path: p.category_path,
    tags: p.tags,
    short_description: p.short_description,
    description: p.description,
    currency: p.currency,
    regular_price: p.regular_price,
    sale_price: p.sale_price,
    sale_starts_at: p.sale_starts_at,
    sale_ends_at: p.sale_ends_at,
    manage_stock: p.manage_stock,
    allow_backorders: p.allow_backorders,
    low_stock_threshold: p.low_stock_threshold,
    weight_kg: p.weight_kg,
    length_cm: p.length_cm,
    width_cm: p.width_cm,
    height_cm: p.height_cm,
    attributes: p.attributes,
    featured_image: p.featured_image,
    gallery: p.gallery,
    variations: variations.map((v) => ({
      id: v.id, sku: v.sku, options: v.options, regular_price: v.regular_price, sale_price: v.sale_price,
      sale_starts_at: v.sale_starts_at, sale_ends_at: v.sale_ends_at, image: v.image,
    })),
    image_paths: imagePaths(shown),
    approved_at: new Date().toISOString(),
  };
}

export type Snapshot = ReturnType<typeof buildSnapshot>;

// Delete image files that nothing points at any more. A file stays while the current product
// or the live (approved) version still uses it.
export async function removeUnreferenced(productId: string, candidates: string[]): Promise<void> {
  const unique = [...new Set(candidates)];
  if (unique.length === 0) return;
  const sql = getSql();
  const { removeProductFile, PRODUCT_PATH } = await import("./storage.server");
  const rows = await sql<{ keep: string[] }[]>`
    SELECT COALESCE(array_remove(ARRAY[p.featured_image], NULL), '{}') || p.gallery
      || COALESCE((SELECT array_agg(v.image) FROM product_variations v WHERE v.product_id = p.id AND v.image IS NOT NULL), '{}')
      || ARRAY(SELECT jsonb_array_elements_text(COALESCE(p.published_snapshot->'image_paths', '[]'::jsonb))) AS keep
    FROM products p WHERE p.id = ${productId}`;
  const keep = new Set(rows[0]?.keep ?? []);
  await Promise.all(
    unique.filter((path) => !keep.has(path) && PRODUCT_PATH.test(path)).map((path) => removeProductFile(path).catch(() => undefined)),
  );
}

// A short code for the seller's store, used at the start of generated SKUs ("Demo Store" -> "DEMO-0001").
export async function skuPrefix(sellerId: string): Promise<string> {
  const rows = await getSql()<{ n: string | null }[]>`SELECT brand->>'store_name' AS n FROM seller_applications WHERE user_id = ${sellerId}`;
  const word = (rows[0]?.n ?? "").toUpperCase().replace(/[^A-Z0-9 ]+/g, "").trim().split(/\s+/)[0] ?? "";
  return word.length >= 2 ? word.slice(0, 4) : "DDLX";
}

// Next number in line among the SKUs Vendor Hub already knows about.
export async function localNextSku(prefix: string): Promise<string> {
  const rows = await getSql()<{ sku: string }[]>`
    SELECT sku FROM products WHERE sku ILIKE ${`${prefix}-%`}
    UNION ALL SELECT sku FROM product_variations WHERE sku ILIKE ${`${prefix}-%`}`;
  const pattern = new RegExp(`^${prefix}-(\\d+)$`, "i");
  const highest = rows.reduce((max, row) => Math.max(max, Number(pattern.exec(row.sku)?.[1] ?? 0)), 0);
  return `${prefix}-${String(highest + 1).padStart(4, "0")}`;
}

type SkuInput = { sku: string; product_type: "simple" | "variable"; variations: { id: string | null; sku: string }[] };

// Is every SKU free? Checks the other sellers in Vendor Hub, then the marketplace store itself
// (only for SKUs that are new or changed, so editing other fields never fails on an old SKU).
export async function skuProblem(productId: string | null, data: SkuInput): Promise<string | null> {
  const { marketplaceSkuConflicts } = await import("./marketplace-sku.server");
  const sql = getSql();
  const items = [
    { sku: data.sku.trim(), product_id: productId, variation_id: null as string | null },
    ...(data.product_type === "variable" ? data.variations.map((v) => ({ sku: v.sku.trim(), product_id: productId, variation_id: v.id })) : []),
  ];
  const lowers = items.map((i) => i.sku.toLowerCase());
  const taken = await sql<{ sku: string }[]>`
    SELECT sku FROM products WHERE lower(sku) = ANY(${sql.array(lowers)}) AND id IS DISTINCT FROM ${productId}::uuid
    UNION ALL
    SELECT sku FROM product_variations WHERE lower(sku) = ANY(${sql.array(lowers)}) AND product_id IS DISTINCT FROM ${productId}::uuid`;
  if (taken[0]) return `The SKU ${taken[0].sku} is already used by another product. Press Generate to get a free one.`;

  const known = new Set<string>();
  if (productId) {
    const own = await sql<{ sku: string }[]>`
      SELECT sku FROM products WHERE id = ${productId} UNION ALL SELECT sku FROM product_variations WHERE product_id = ${productId}`;
    own.forEach((row) => known.add(row.sku.toLowerCase()));
  }
  const changed = items.filter((i) => !known.has(i.sku.toLowerCase()));
  const conflicts = await marketplaceSkuConflicts(changed);
  if (conflicts && conflicts.length > 0) return `The SKU ${conflicts[0]} is already used on the marketplace. Press Generate to get a free one.`;
  return null;
}
