import { useState } from "react";
import { useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { Minus, Plus } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ProductImage } from "@/components/seller/ProductImage";
import { StockBadge } from "@/components/seller/StockBadge";
import { PRODUCT_STATUS_LABEL, productsQuery, stockState } from "@/lib/products";
import { adjustStock } from "@/lib/products.functions";
import type { Product } from "@/lib/types";

type Filter = "all" | "low" | "out";
const FILTERS: { key: Filter; label: string }[] = [
  { key: "all", label: "All" },
  { key: "low", label: "Low stock" },
  { key: "out", label: "Out of stock" },
];

function StockControls({ product, busy, onChange }: { product: Product; busy: boolean; onChange: (mode: "delta" | "set", value: number) => void }) {
  const [draft, setDraft] = useState<string | null>(null);
  const commit = () => {
    if (draft === null) return;
    const next = Number(draft);
    setDraft(null);
    if (draft.trim() !== "" && Number.isInteger(next) && next >= 0 && next !== product.stock) onChange("set", next);
  };
  return (
    <div className="flex items-center gap-1">
      <Button variant="outline" size="icon" className="size-8" disabled={busy || product.stock === 0} aria-label={`Remove one ${product.name}`} onClick={() => onChange("delta", -1)}><Minus className="size-4" /></Button>
      <Input
        aria-label={`Stock for ${product.name}`}
        className="h-8 w-20 text-center"
        type="number"
        min={0}
        disabled={busy}
        value={draft ?? String(product.stock)}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => { if (e.key === "Enter") e.currentTarget.blur(); if (e.key === "Escape") setDraft(null); }}
      />
      <Button variant="outline" size="icon" className="size-8" disabled={busy} aria-label={`Add one ${product.name}`} onClick={() => onChange("delta", 1)}><Plus className="size-4" /></Button>
    </div>
  );
}

export function InventoryPanel() {
  const { data: products } = useSuspenseQuery(productsQuery);
  const queryClient = useQueryClient();
  const [filter, setFilter] = useState<Filter>("all");
  const [busyId, setBusyId] = useState<string | null>(null);

  const active = products.filter((p) => p.status !== "archived");
  const visible = active.filter((p) => filter === "all" || (filter === "low" ? stockState(p) === "low" : stockState(p) === "out"));

  const change = async (product: Product, mode: "delta" | "set", value: number) => {
    if (busyId) return;
    setBusyId(product.id);
    try {
      const result = await adjustStock({ data: { id: product.id, mode, value } });
      if (!result.ok) { toast.error(result.error); return; }
      queryClient.setQueryData<Product[]>(productsQuery.queryKey, (old) => old?.map((p) => (p.id === result.product.id ? result.product : p)));
    } catch { toast.error("Could not update the stock. Please try again."); }
    finally { setBusyId(null); }
  };

  return (
    <div className="mt-8">
      <div className="flex flex-wrap gap-2" role="group" aria-label="Filter stock">
        {FILTERS.map((item) => (
          <Button key={item.key} size="sm" variant={filter === item.key ? "default" : "outline"} aria-pressed={filter === item.key} onClick={() => setFilter(item.key)}>{item.label}</Button>
        ))}
      </div>
      <p className="mt-5 text-sm text-muted-foreground">Change a number and press Enter, or use − and + for single units. Archived products are hidden.</p>
      <div className="mt-4 overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow><TableHead>Product</TableHead><TableHead>Status</TableHead><TableHead>Stock</TableHead><TableHead>Level</TableHead></TableRow>
          </TableHeader>
          <TableBody>
            {visible.map((p) => (
              <TableRow key={p.id}>
                <TableCell>
                  <div className="flex items-center gap-3">
                    <ProductImage path={p.images[0]} alt={p.name} className="size-10 shrink-0 rounded border" />
                    <div className="min-w-0"><p className="truncate font-medium">{p.name}</p><p className="truncate text-xs text-muted-foreground">{p.sku}</p></div>
                  </div>
                </TableCell>
                <TableCell className="whitespace-nowrap text-sm">{PRODUCT_STATUS_LABEL[p.status]}</TableCell>
                <TableCell><StockControls product={p} busy={busyId === p.id} onChange={(mode, value) => void change(p, mode, value)} /></TableCell>
                <TableCell className="whitespace-nowrap"><StockBadge product={p} /><span className="ml-2 text-xs text-muted-foreground">warn at {p.low_stock_threshold}</span></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {!visible.length && <p className="py-12 text-center text-muted-foreground">{active.length ? "Nothing matches this filter." : "Add products first, then manage their stock here."}</p>}
      </div>
    </div>
  );
}
