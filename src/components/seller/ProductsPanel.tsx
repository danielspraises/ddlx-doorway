import { useState } from "react";
import { Link } from "@tanstack/react-router";
import { useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { PackagePlus, Pencil, Search, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { ProductImage } from "@/components/seller/ProductImage";
import { StockBadge } from "@/components/seller/StockBadge";
import { priceLabel, productsQuery, visibility } from "@/lib/products";
import { deleteProduct } from "@/lib/products.functions";
import type { Product } from "@/lib/types";

type Filter = "all" | "live" | "review" | "attention" | "draft";
const FILTERS: { key: Filter; label: string }[] = [
  { key: "all", label: "All" },
  { key: "live", label: "Live" },
  { key: "review", label: "In review" },
  { key: "attention", label: "Needs changes" },
  { key: "draft", label: "Drafts" },
];

function matches(p: Product, filter: Filter) {
  if (filter === "all") return true;
  if (filter === "live") return p.live;
  if (filter === "review") return p.status === "pending_review";
  if (filter === "attention") return p.status === "changes_requested" || p.status === "rejected";
  return !p.live && (p.status === "draft" || p.status === "approved");
}

export function ProductsPanel() {
  const { data: products } = useSuspenseQuery(productsQuery);
  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState<Filter>("all");
  const [deleting, setDeleting] = useState<Product | null>(null);
  const [busy, setBusy] = useState(false);

  const visible = products.filter((p) => matches(p, filter) && `${p.name} ${p.sku} ${p.category} ${p.brand}`.toLowerCase().includes(search.trim().toLowerCase()));

  const remove = async () => {
    if (!deleting || busy) return;
    setBusy(true);
    try {
      const result = await deleteProduct({ data: { id: deleting.id } });
      if (!result.ok) { toast.error(result.error); return; }
      await queryClient.invalidateQueries({ queryKey: ["products"] });
      toast.success(`${deleting.name} was deleted.`);
      setDeleting(null);
    } catch { toast.error("Could not delete the product. Please try again."); }
    finally { setBusy(false); }
  };

  return (
    <div className="mt-8">
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-52 flex-1"><Search className="absolute left-3 top-3 size-4 text-muted-foreground" /><Input aria-label="Search products" className="pl-10" placeholder="Search name, SKU, brand or category" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
        <Button asChild><Link to="/product-editor"><PackagePlus className="size-4" /> Add product</Link></Button>
      </div>
      <div className="mt-4 flex flex-wrap gap-2" role="group" aria-label="Filter products">
        {FILTERS.map((item) => <Button key={item.key} size="sm" variant={filter === item.key ? "default" : "outline"} aria-pressed={filter === item.key} onClick={() => setFilter(item.key)}>{item.label}</Button>)}
      </div>

      <p className="mt-6 text-sm text-muted-foreground">{visible.length} product{visible.length === 1 ? "" : "s"}</p>
      <ul className="mt-3 divide-y border-y">
        {visible.map((p) => {
          const state = visibility(p);
          return (
            <li key={p.id} className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-4 py-4">
              <ProductImage path={p.featured_image ?? undefined} alt={p.name} className="size-16 rounded-md border" />
              <div className="min-w-0">
                <h3 className="truncate font-semibold">{p.name}</h3>
                <p className="mt-0.5 truncate text-sm text-muted-foreground">{p.sku} · {p.category || "No category"} · {priceLabel(p)}{p.product_type === "variable" ? ` · ${p.variations.length} variations` : ""}</p>
                <div className="mt-2 flex flex-wrap gap-2"><Badge variant={state.tone === "live" ? "default" : "outline"}>{state.label}</Badge><StockBadge product={p} /></div>
              </div>
              <div className="flex gap-2">
                <Button asChild variant="outline" size="sm"><Link to="/product-editor" search={{ id: p.id }}><Pencil className="size-4" /><span className="sr-only sm:not-sr-only">Edit</span></Link></Button>
                {!p.live && p.status !== "pending_review" && <Button variant="outline" size="icon" className="size-9" aria-label={`Delete ${p.name}`} onClick={() => setDeleting(p)}><Trash2 className="size-4" /></Button>}
              </div>
            </li>
          );
        })}
      </ul>
      {!visible.length && (
        <div className="py-14 text-center">
          <p className="text-muted-foreground">{products.length ? "No products match." : "You have not added any products yet."}</p>
          {!products.length && <Button asChild className="mt-5"><Link to="/product-editor"><PackagePlus className="size-4" /> Add your first product</Link></Button>}
        </div>
      )}

      <AlertDialog open={deleting !== null} onOpenChange={(open) => { if (!open) setDeleting(null); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete this product?</AlertDialogTitle>
            <AlertDialogDescription>{deleting?.name}, its variations and its photos will be removed permanently. Past sales stay in your records.</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={busy}>Keep product</AlertDialogCancel>
            <AlertDialogAction disabled={busy} onClick={(event) => { event.preventDefault(); void remove(); }}>{busy ? "Deleting…" : "Delete"}</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
