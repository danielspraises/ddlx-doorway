import { queryOptions } from "@tanstack/react-query";
import { listMyProducts } from "@/lib/products.functions";
import type { Product } from "@/lib/types";

export const CURRENCIES = ["NGN", "USD", "GBP", "EUR"] as const;
export const PRODUCT_STATUSES = ["draft", "ready", "archived"] as const;
export const PRODUCT_STATUS_LABEL: Record<Product["status"], string> = { draft: "Draft", ready: "Ready to sell", archived: "Archived" };
export const MAX_PHOTOS = 6;

export const productsQuery = queryOptions({
  queryKey: ["products"],
  queryFn: () => listMyProducts(),
});

export type StockState = "out" | "low" | "ok";
export function stockState(product: Pick<Product, "stock" | "low_stock_threshold">): StockState {
  if (product.stock === 0) return "out";
  if (product.stock <= product.low_stock_threshold) return "low";
  return "ok";
}

export function formatPrice(price: number, currency: string) {
  try {
    return new Intl.NumberFormat("en", { style: "currency", currency }).format(price);
  } catch {
    return `${currency} ${price}`;
  }
}
