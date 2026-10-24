import { Badge } from "@/components/ui/badge";
import { stockState } from "@/lib/products";
import type { Product } from "@/lib/types";

export function StockBadge({ product }: { product: Pick<Product, "stock" | "low_stock_threshold"> }) {
  const state = stockState(product);
  if (state === "out") return <Badge variant="destructive">Out of stock</Badge>;
  if (state === "low") return <Badge variant="outline" className="border-accent text-accent">Low · {product.stock} left</Badge>;
  return <Badge variant="secondary">{product.stock} in stock</Badge>;
}
