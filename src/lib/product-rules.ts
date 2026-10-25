import type { Attribute, Product, Variation } from "@/lib/types";

// Rules shared by the editor (instant feedback) and the server (the real check).

export const slugify = (text: string) =>
  text.toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 60) || "product";

// All combinations of the attribute values, e.g. Size x Colour.
export function combinations(attributes: Attribute[]): Record<string, string>[] {
  const usable = attributes.filter((a) => a.name.trim() && a.values.length > 0);
  if (usable.length === 0) return [];
  return usable.reduce<Record<string, string>[]>(
    (acc, attr) => acc.flatMap((combo) => attr.values.map((value) => ({ ...combo, [attr.name]: value }))),
    [{}],
  );
}

export const optionKey = (options: Record<string, string>) =>
  Object.keys(options).sort().map((k) => `${k}=${options[k]}`).join("|");

type PriceFields = { regular_price: number | null; sale_price: number | null; sale_starts_at: string | null; sale_ends_at: string | null };

function priceProblem(label: string, item: PriceFields): string | null {
  if (item.sale_price !== null) {
    if (item.regular_price === null) return `${label}: set a regular price before adding a sale price.`;
    if (item.sale_price >= item.regular_price) return `${label}: the sale price must be lower than the regular price.`;
  }
  if (item.sale_starts_at && item.sale_ends_at && new Date(item.sale_ends_at) <= new Date(item.sale_starts_at)) {
    return `${label}: the sale must end after it starts.`;
  }
  return null;
}

type VariationInput = PriceFields & Pick<Variation, "sku" | "options">;
export type ProductInput = PriceFields & Pick<Product, "name" | "sku" | "product_type" | "attributes"> & { variations: VariationInput[] };

// Returns the first problem found, or null when the product can be saved (as a draft).
export function validateProduct(p: ProductInput): string | null {
  if (!p.name.trim()) return "Product name is required.";
  if (!p.sku.trim()) return "SKU is required.";
  const own = priceProblem("Product", p);
  if (own) return own;
  if (p.product_type === "simple") return null;

  const attributes = p.attributes.filter((a) => a.name.trim() || a.values.length);
  const names = attributes.map((a) => a.name.trim().toLowerCase());
  if (attributes.some((a) => !a.name.trim())) return "Every attribute needs a name.";
  if (new Set(names).size !== names.length) return "Attribute names must be different from each other.";
  for (const a of attributes) {
    const values = a.values.map((v) => v.trim().toLowerCase());
    if (values.some((v) => !v)) return `${a.name}: values cannot be empty.`;
    if (new Set(values).size !== values.length) return `${a.name}: values must be different from each other.`;
  }
  const seenSku = new Set<string>();
  const seenOptions = new Set<string>();
  for (const v of p.variations) {
    const label = `Variation ${v.sku || "(no SKU)"}`;
    if (!v.sku.trim()) return "Every variation needs a SKU.";
    if (seenSku.has(v.sku.trim().toLowerCase()) || v.sku.trim().toLowerCase() === p.sku.trim().toLowerCase()) return `Variation SKU ${v.sku} is used twice.`;
    seenSku.add(v.sku.trim().toLowerCase());
    const keys = Object.keys(v.options);
    if (keys.length !== attributes.length || attributes.some((a) => !a.values.includes(v.options[a.name] ?? ""))) {
      return `${label}: its options no longer match the attributes. Rebuild the variations.`;
    }
    const key = optionKey(v.options);
    if (seenOptions.has(key)) return `${label}: another variation has the same options.`;
    seenOptions.add(key);
    const problem = priceProblem(label, v);
    if (problem) return problem;
  }
  return null;
}

type VariationDraft = Pick<Variation, "regular_price" | "enabled">;
type Draftable = Pick<Product, "name" | "product_type" | "category" | "description" | "short_description" | "regular_price" | "featured_image" | "attributes" | "weight_kg" | "length_cm" | "width_cm" | "height_cm"> & { variations: VariationDraft[] };

// What must be filled in before a product can go to review.
export function readinessProblems(product: Draftable): string[] {
  const problems: string[] = [];
  if (!product.name.trim()) problems.push("Add a product name.");
  if (!product.category.trim()) problems.push("Choose a category.");
  if (!product.description.trim() && !product.short_description.trim()) problems.push("Add a description.");
  if (!product.featured_image) problems.push("Add a featured image.");
  // The marketplace needs these to price delivery.
  if (!product.weight_kg || product.weight_kg <= 0) problems.push("Add the product weight in the Shipping tab.");
  if (!product.length_cm || !product.width_cm || !product.height_cm) problems.push("Add the length, width and height in the Shipping tab.");
  if (product.product_type === "simple") {
    if (!product.regular_price || product.regular_price <= 0) problems.push("Set a regular price above zero.");
  } else {
    const enabled = product.variations.filter((v) => v.enabled);
    if (product.attributes.filter((a) => a.name.trim() && a.values.length).length === 0) problems.push("Add at least one attribute, such as Size.");
    if (enabled.length === 0) problems.push("Add at least one variation.");
    else if (enabled.some((v) => !v.regular_price || v.regular_price <= 0)) problems.push("Every variation needs a regular price above zero.");
  }
  return problems;
}
