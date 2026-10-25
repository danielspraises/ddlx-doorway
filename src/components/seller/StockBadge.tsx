import { Badge } from "@/components/ui/badge";
import { stockState, unitsInStock } from "@/lib/products";
import type { Product } from "@/lib/types";

export function StockBadge({ product }: { product: Product }) {
  const state = stockState(product);
  if (state === "untracked") return <Badge variant="secondary">Stock not tracked</Badge>;
  if (state === "out") return <Badge variant="destructive">Out of stock</Badge>;
  const units = unitsInStock(product);
  if (state === "low") return <Badge variant="outline" className="border-accent text-accent">Low · {units} left</Badge>;
  return <Badge variant="secondary">{units} in stock</Badge>;
}

export function LevelBadge({ stock, threshold }: { stock: number; threshold: number }) {
  if (stock === 0) return <Badge variant="destructive">Out of stock</Badge>;
  if (stock <= threshold) return <Badge variant="outline" className="border-accent text-accent">Low · {stock} left</Badge>;
  return <Badge variant="secondary">{stock} in stock</Badge>;
}
