import { useState } from "react";
import { useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { Minus, Plus } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ProductImage } from "@/components/seller/ProductImage";
import { LevelBadge, StockBadge } from "@/components/seller/StockBadge";
import { productsQuery, stockState } from "@/lib/products";
import { adjustStock } from "@/lib/products.functions";
import type { Product } from "@/lib/types";

type Filter = "all" | "low" | "out";
const FILTERS: { key: Filter; label: string }[] = [
  { key: "all", label: "All" },
  { key: "low", label: "Low stock" },
  { key: "out", label: "Out of stock" },
];

function StockControls({ label, stock, busy, onChange }: { label: string; stock: number; busy: boolean; onChange: (mode: "delta" | "set", value: number) => void }) {
  const [draft, setDraft] = useState<string | null>(null);
  const commit = () => {
    if (draft === null) return;
    const next = Number(draft);
    setDraft(null);
    if (draft.trim() !== "" && Number.isInteger(next) && next >= 0 && next !== stock) onChange("set", next);
  };
  return (
    <div className="flex items-center gap-1">
      <Button variant="outline" size="icon" className="size-8" disabled={busy || stock === 0} aria-label={`Remove one ${label}`} onClick={() => onChange("delta", -1)}><Minus className="size-4" /></Button>
      <Input
        aria-label={`Stock for ${label}`}
        className="h-8 w-20 text-center"
        type="number"
        min={0}
        disabled={busy}
        value={draft ?? String(stock)}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => { if (e.key === "Enter") e.currentTarget.blur(); if (e.key === "Escape") setDraft(null); }}
      />
      <Button variant="outline" size="icon" className="size-8" disabled={busy} aria-label={`Add one ${label}`} onClick={() => onChange("delta", 1)}><Plus className="size-4" /></Button>
    </div>
  );
}

export function InventoryPanel() {
  const { data: products } = useSuspenseQuery(productsQuery);
  const queryClient = useQueryClient();
  const [filter, setFilter] = useState<Filter>("all");
  const [busyKey, setBusyKey] = useState<string | null>(null);

  const visible = products.filter((p) => filter === "all" || (filter === "low" ? stockState(p) === "low" : stockState(p) === "out"));

  const change = async (product: Product, variationId: string | null, mode: "delta" | "set", value: number) => {
    if (busyKey) return;
    setBusyKey(`${product.id}:${variationId ?? ""}`);
    try {
      const result = await adjustStock({ data: { id: product.id, variation_id: variationId, mode, value } });
      if (!result.ok) { toast.error(result.error); return; }
      queryClient.setQueryData<Product[]>(productsQuery.queryKey, (old) => old?.map((p) => (p.id === result.product.id ? result.product : p)));
    } catch { toast.error("Could not update the stock. Please try again."); }
    finally { setBusyKey(null); }
  };

  return (
    <div className="mt-8">
      <div className="flex flex-wrap gap-2" role="group" aria-label="Filter stock">
        {FILTERS.map((item) => (
          <Button key={item.key} size="sm" variant={filter === item.key ? "default" : "outline"} aria-pressed={filter === item.key} onClick={() => setFilter(item.key)}>{item.label}</Button>
        ))}
      </div>
      <p className="mt-5 text-sm text-muted-foreground">Change a number and press Enter, or use − and + for single units. Stock changes reach the marketplace straight away, with no review.</p>
      <div className="mt-4 overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow><TableHead>Product</TableHead><TableHead>Stock</TableHead><TableHead>Level</TableHead></TableRow>
          </TableHeader>
          <TableBody>
            {visible.flatMap((p) => {
              const head = (
                <TableRow key={p.id}>
                  <TableCell>
                    <div className="flex items-center gap-3">
                      <ProductImage path={p.featured_image ?? undefined} alt={p.name} className="size-10 shrink-0 rounded border" />
                      <div className="min-w-0"><p className="truncate font-medium">{p.name}</p><p className="truncate text-xs text-muted-foreground">{p.sku}{p.product_type === "variable" ? ` · ${p.variations.length} variations` : ""}</p></div>
                    </div>
                  </TableCell>
                  <TableCell>
                    {p.product_type === "variable" ? <span className="text-sm text-muted-foreground">Set per variation</span>
                      : p.manage_stock ? <StockControls label={p.name} stock={p.stock} busy={busyKey === `${p.id}:`} onChange={(mode, value) => void change(p, null, mode, value)} />
                      : <span className="text-sm text-muted-foreground">Not tracked</span>}
                  </TableCell>
                  <TableCell className="whitespace-nowrap"><StockBadge product={p} /><span className="ml-2 text-xs text-muted-foreground">warn at {p.low_stock_threshold}</span></TableCell>
                </TableRow>
              );
              const rows = p.product_type === "variable"
                ? p.variations.map((v) => (
                    <TableRow key={v.id} className="bg-muted/30">
                      <TableCell className="pl-16 text-sm">{Object.values(v.options).join(" / ")} <span className="text-xs text-muted-foreground">{v.sku}{v.enabled ? "" : " · not available"}</span></TableCell>
                      <TableCell><StockControls label={`${p.name} ${Object.values(v.options).join(" ")}`} stock={v.stock} busy={busyKey === `${p.id}:${v.id}`} onChange={(mode, value) => void change(p, v.id, mode, value)} /></TableCell>
                      <TableCell className="whitespace-nowrap"><LevelBadge stock={v.stock} threshold={p.low_stock_threshold} /></TableCell>
                    </TableRow>
                  ))
                : [];
              return [head, ...rows];
            })}
          </TableBody>
        </Table>
        {!visible.length && <p className="py-12 text-center text-muted-foreground">{products.length ? "Nothing matches this filter." : "Add products first, then manage their stock here."}</p>}
      </div>
    </div>
  );
}
