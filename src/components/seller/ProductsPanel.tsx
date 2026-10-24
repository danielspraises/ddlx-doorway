import { useState } from "react";
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
import { ProductDialog } from "@/components/seller/ProductDialog";
import { ProductImage } from "@/components/seller/ProductImage";
import { StockBadge } from "@/components/seller/StockBadge";
import { PRODUCT_STATUS_LABEL, formatPrice, productsQuery } from "@/lib/products";
import { deleteProduct } from "@/lib/products.functions";
import type { Product } from "@/lib/types";

export function ProductsPanel() {
  const { data: products } = useSuspenseQuery(productsQuery);
  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [editing, setEditing] = useState<Product | null>(null);
  const [creating, setCreating] = useState(0);
  const [deleting, setDeleting] = useState<Product | null>(null);
  const [busy, setBusy] = useState(false);

  const visible = products.filter((p) => `${p.name} ${p.sku} ${p.category}`.toLowerCase().includes(search.trim().toLowerCase()));
  const dialogOpen = creating > 0 || editing !== null;
  const close = () => { setEditing(null); setCreating(0); };

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
        <div className="relative min-w-52 flex-1"><Search className="absolute left-3 top-3 size-4 text-muted-foreground" /><Input aria-label="Search products" className="pl-10" placeholder="Search name, SKU or category" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
        <Button onClick={() => setCreating((n) => n + 1)}><PackagePlus className="size-4" /> Add product</Button>
      </div>

      <p className="mt-6 text-sm text-muted-foreground">{visible.length} product{visible.length === 1 ? "" : "s"}</p>
      <ul className="mt-3 divide-y border-y">
        {visible.map((p) => (
          <li key={p.id} className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-4 py-4">
            <ProductImage path={p.images[0]} alt={p.name} className="size-16 rounded-md border" />
            <div className="min-w-0">
              <h3 className="truncate font-semibold">{p.name}</h3>
              <p className="mt-0.5 truncate text-sm text-muted-foreground">{p.sku} · {p.category} · {formatPrice(p.price, p.currency)}</p>
              <div className="mt-2 flex flex-wrap gap-2"><StockBadge product={p} /><Badge variant={p.status === "ready" ? "default" : "outline"}>{PRODUCT_STATUS_LABEL[p.status]}</Badge></div>
            </div>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" onClick={() => setEditing(p)}><Pencil className="size-4" /><span className="sr-only sm:not-sr-only">Edit</span></Button>
              <Button variant="outline" size="icon" className="size-9" aria-label={`Delete ${p.name}`} onClick={() => setDeleting(p)}><Trash2 className="size-4" /></Button>
            </div>
          </li>
        ))}
      </ul>
      {!visible.length && (
        <div className="py-14 text-center">
          <p className="text-muted-foreground">{products.length ? "No products match your search." : "You have not added any products yet."}</p>
          {!products.length && <Button className="mt-5" onClick={() => setCreating((n) => n + 1)}><PackagePlus className="size-4" /> Add your first product</Button>}
        </div>
      )}

      {dialogOpen && <ProductDialog key={editing?.id ?? `new-${creating}`} open product={editing} onOpenChange={(open) => { if (!open) close(); }} />}

      <AlertDialog open={deleting !== null} onOpenChange={(open) => { if (!open) setDeleting(null); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete this product?</AlertDialogTitle>
            <AlertDialogDescription>{deleting?.name} and its photos will be removed permanently. This cannot be undone.</AlertDialogDescription>
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
