import { queryOptions } from "@tanstack/react-query";
import { listMyProducts } from "@/lib/products.functions";
import { effectivePrice } from "@/lib/pricing";
import type { Product, ProductStatus } from "@/lib/types";

export const CURRENCIES = ["NGN", "USD", "GBP", "EUR"] as const;
export const MAX_GALLERY = 8;
export const MAX_VARIATIONS = 100;
// "Fashion › Men › Footwear › Sneakers"
export const categoryLabel = (product: Pick<Product, "category" | "category_path">) =>
  product.category_path.length ? product.category_path.join(" › ") : product.category || "No category";

export const productsQuery = queryOptions({
  queryKey: ["products"],
  queryFn: () => listMyProducts(),
});

export const STATUS_LABEL: Record<ProductStatus, string> = {
  draft: "Draft",
  pending_review: "In review",
  changes_requested: "Changes requested",
  rejected: "Not approved",
  approved: "Approved",
};

// What the seller sees: whether customers can buy it now, and what is happening to the latest edit.
export function visibility(product: Pick<Product, "status" | "live">): { label: string; tone: "live" | "wait" | "bad" | "idle" } {
  const { status, live } = product;
  if (live) {
    if (status === "approved") return { label: "Live on marketplace", tone: "live" };
    if (status === "pending_review") return { label: "Live · changes in review", tone: "live" };
    if (status === "draft") return { label: "Live · edits not submitted", tone: "live" };
    return { label: `Live · ${STATUS_LABEL[status].toLowerCase()}`, tone: "bad" };
  }
  if (status === "approved") return { label: "Taken down", tone: "idle" };
  if (status === "pending_review") return { label: "In review", tone: "wait" };
  if (status === "changes_requested" || status === "rejected") return { label: STATUS_LABEL[status], tone: "bad" };
  return { label: "Draft", tone: "idle" };
}

// Stock lives on the product, or on each variation for variable products.
export function unitsInStock(product: Product): number {
  return product.product_type === "variable" ? product.variations.filter((v) => v.enabled).reduce((n, v) => n + v.stock, 0) : product.stock;
}

export type StockState = "out" | "low" | "ok" | "untracked";
export function stockState(product: Product): StockState {
  if (product.product_type === "simple" && !product.manage_stock) return "untracked";
  const units = unitsInStock(product);
  if (units === 0) return "out";
  const low = product.low_stock_threshold;
  if (product.product_type === "variable") {
    const enabled = product.variations.filter((v) => v.enabled);
    if (enabled.some((v) => v.stock === 0) || enabled.some((v) => v.stock <= low)) return "low";
    return "ok";
  }
  return units <= low ? "low" : "ok";
}

export function formatPrice(price: number, currency: string) {
  try {
    return new Intl.NumberFormat("en", { style: "currency", currency }).format(price);
  } catch {
    return `${currency} ${price}`;
  }
}

// "₦5,000" or "₦4,000–₦6,500" for variable products.
export function priceLabel(product: Product): string {
  const prices = (product.product_type === "variable" ? product.variations.filter((v) => v.enabled) : [product])
    .map((item) => effectivePrice(item))
    .filter((value): value is number => value !== null);
  if (prices.length === 0) return "No price";
  const lo = Math.min(...prices);
  const hi = Math.max(...prices);
  return lo === hi ? formatPrice(lo, product.currency) : `${formatPrice(lo, product.currency)}–${formatPrice(hi, product.currency)}`;
}

export { combinations, optionKey, readinessProblems, slugify, validateProduct } from "@/lib/product-rules";
