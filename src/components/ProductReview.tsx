import { useState } from "react";
import { useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { ArrowLeft, Check, MessageSquare, Search, X, EyeOff } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ProductImage } from "@/components/seller/ProductImage";
import { effectivePrice, saleActive } from "@/lib/pricing";
import { categoryLabel, formatPrice, priceLabel, readinessProblems, visibility } from "@/lib/products";
import { productReviewsQuery } from "@/lib/product-reviews";
import { reviewProduct } from "@/lib/product-review.functions";
import type { ProductReviewItem } from "@/lib/types";

type Filter = "waiting" | "live" | "attention" | "all";
const FILTERS: { key: Filter; label: string }[] = [
  { key: "waiting", label: "Waiting for review" },
  { key: "live", label: "Live" },
  { key: "attention", label: "Sent back" },
  { key: "all", label: "All" },
];
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" }) : "—");

function matches(p: ProductReviewItem, filter: Filter) {
  if (filter === "waiting") return p.status === "pending_review";
  if (filter === "live") return p.live;
  if (filter === "attention") return p.status === "changes_requested" || p.status === "rejected";
  return true;
}

function Detail({ product, onBack }: { product: ProductReviewItem; onBack: () => void }) {
  const queryClient = useQueryClient();
  const [comment, setComment] = useState("");
  const [saving, setSaving] = useState(false);
  const problems = readinessProblems(product);
  const waiting = product.status === "pending_review";
  const sale = saleActive(product);

  const decide = async (decision: "approve" | "request_changes" | "reject" | "take_down") => {
    if (saving) return;
    if (decision !== "approve" && !comment.trim()) { toast.error("Add a note so the seller knows why."); return; }
    setSaving(true);
    try {
      const result = await reviewProduct({ data: { id: product.id, decision, comment: comment.trim(), expected_updated_at: product.updated_at } });
      if (!result.ok) { toast.error(result.error); return; }
      await queryClient.invalidateQueries({ queryKey: productReviewsQuery.queryKey });
      await queryClient.invalidateQueries({ queryKey: ["me"] });
      toast.success(decision === "approve" ? "Approved. It is now live on the marketplace." : decision === "take_down" ? "Taken off the marketplace." : decision === "reject" ? "Not approved. The seller has been told." : "Sent back to the seller for changes.");
      onBack();
    } catch { toast.error("Could not save the decision. Refresh and try again."); }
    finally { setSaving(false); }
  };

  return (
    <div>
      <Button variant="ghost" className="mt-5" onClick={onBack}><ArrowLeft className="size-4" /> All products</Button>
      <h2 className="mt-4 text-2xl font-semibold">{product.name}</h2>
      <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 border-y py-4 text-sm">
        <span className="font-semibold">{visibility(product).label}</span>
        <span className="text-muted-foreground">{product.store_name} · {product.seller_email}</span>
        <span className="text-muted-foreground">Submitted {when(product.submitted_at)}</span>
      </div>

      <div className="mt-6 grid gap-8 md:grid-cols-[280px_minmax(0,1fr)]">
        <div>
          <ProductImage path={product.featured_image ?? undefined} alt={`${product.name} featured`} className="aspect-square w-full rounded-md border" />
          {product.gallery.length > 0 && <ul className="mt-3 grid grid-cols-4 gap-2">{product.gallery.map((path, i) => <li key={path}><ProductImage path={path} alt={`Gallery ${i + 1}`} className="aspect-square w-full rounded border" /></li>)}</ul>}
        </div>
        <dl className="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
          <div><dt className="text-muted-foreground">Type</dt><dd className="mt-1 font-medium">{product.product_type === "variable" ? "Variable product" : "Simple product"}</dd></div>
          <div><dt className="text-muted-foreground">SKU</dt><dd className="mt-1 font-medium">{product.sku}</dd></div>
          <div><dt className="text-muted-foreground">Category</dt><dd className="mt-1 font-medium">{categoryLabel(product)}</dd></div>
          <div><dt className="text-muted-foreground">Brand</dt><dd className="mt-1 font-medium">{product.brand || "—"}</dd></div>
          <div><dt className="text-muted-foreground">Price</dt><dd className="mt-1 font-medium">{priceLabel(product)}</dd></div>
          {product.product_type === "simple" && product.sale_price !== null && <div><dt className="text-muted-foreground">Sale</dt><dd className="mt-1 font-medium">{formatPrice(product.sale_price, product.currency)} {sale ? "(running now)" : "(scheduled)"}<span className="block text-xs font-normal text-muted-foreground">{when(product.sale_starts_at)} to {product.sale_ends_at ? when(product.sale_ends_at) : "no end date"}</span></dd></div>}
          <div><dt className="text-muted-foreground">Stock</dt><dd className="mt-1 font-medium">{product.product_type === "variable" ? `${product.variations.reduce((n, v) => n + v.stock, 0)} across ${product.variations.length} variations` : product.manage_stock ? product.stock : "Not tracked"}</dd></div>
          <div><dt className="text-muted-foreground">Tags</dt><dd className="mt-1 font-medium">{product.tags.join(", ") || "—"}</dd></div>
          {(product.weight_kg !== null || product.length_cm !== null) && <div className="sm:col-span-2"><dt className="text-muted-foreground">Shipping</dt><dd className="mt-1 font-medium">{[product.weight_kg !== null ? `${product.weight_kg} kg` : null, product.length_cm !== null || product.width_cm !== null || product.height_cm !== null ? `${product.length_cm ?? "?"} × ${product.width_cm ?? "?"} × ${product.height_cm ?? "?"} cm` : null].filter(Boolean).join(" · ")}</dd></div>}
          {product.short_description && <div className="sm:col-span-2"><dt className="text-muted-foreground">Short description</dt><dd className="mt-1 whitespace-pre-wrap">{product.short_description}</dd></div>}
          <div className="sm:col-span-2"><dt className="text-muted-foreground">Description</dt><dd className="mt-1 whitespace-pre-wrap">{product.description || "—"}</dd></div>
        </dl>
      </div>

      {product.product_type === "variable" && (
        <div className="mt-8 overflow-x-auto rounded-md border">
          <Table>
            <TableHeader><TableRow><TableHead>Variation</TableHead><TableHead>SKU</TableHead><TableHead className="text-right">Price</TableHead><TableHead className="text-right">Stock</TableHead><TableHead>Available</TableHead></TableRow></TableHeader>
            <TableBody>{product.variations.map((v) => { const price = effectivePrice(v); return (
              <TableRow key={v.id}><TableCell>{Object.entries(v.options).map(([k, val]) => `${k}: ${val}`).join(" · ")}</TableCell><TableCell>{v.sku}</TableCell><TableCell className="whitespace-nowrap text-right">{price === null ? "—" : formatPrice(price, product.currency)}{saleActive(v) && <span className="ml-1 text-xs text-muted-foreground line-through">{formatPrice(v.regular_price ?? 0, product.currency)}</span>}</TableCell><TableCell className="text-right">{v.stock}</TableCell><TableCell>{v.enabled ? "Yes" : "No"}</TableCell></TableRow>
            ); })}</TableBody>
          </Table>
        </div>
      )}

      {waiting && problems.length > 0 && <div className="mt-6 border-l-2 border-destructive bg-secondary p-4 text-sm"><p className="font-semibold">This product is incomplete</p><ul className="mt-1 list-disc pl-5">{problems.map((p) => <li key={p}>{p}</li>)}</ul></div>}

      <section className="mt-8 border-t pt-6">
        <Label htmlFor="product-review-comment">{waiting ? "Note to the seller" : "Note"}</Label>
        <Textarea id="product-review-comment" className="mt-3" rows={4} value={comment} onChange={(e) => setComment(e.target.value)} placeholder={waiting ? "Required when you ask for changes or decline. Optional when you approve." : "Required to take a product down."} />
        <div className="mt-5 flex flex-wrap gap-3">
          {waiting && <Button disabled={saving || problems.length > 0} onClick={() => void decide("approve")}><Check className="size-4" /> Approve and publish</Button>}
          {waiting && <Button variant="outline" disabled={saving} onClick={() => void decide("request_changes")}><MessageSquare className="size-4" /> Request changes</Button>}
          {waiting && <Button variant="destructive" disabled={saving} onClick={() => void decide("reject")}><X className="size-4" /> Reject</Button>}
          {product.live && <Button variant="outline" disabled={saving} onClick={() => void decide("take_down")}><EyeOff className="size-4" /> Take off marketplace</Button>}
        </div>
      </section>
    </div>
  );
}

export function ProductReview() {
  const { data: items } = useSuspenseQuery(productReviewsQuery);
  const [filter, setFilter] = useState<Filter>("waiting");
  const [search, setSearch] = useState("");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const selected = items.find((p) => p.id === selectedId);
  const visible = items.filter((p) => matches(p, filter) && `${p.name} ${p.sku} ${p.store_name} ${p.seller_email}`.toLowerCase().includes(search.trim().toLowerCase()));
  const waitingCount = items.filter((p) => p.status === "pending_review").length;

  if (selected) return <Detail key={`${selected.id}-${selected.updated_at}`} product={selected} onBack={() => setSelectedId(null)} />;
  return (
    <div>
      <div className="mt-8 flex flex-wrap items-center gap-3">
        <div className="relative min-w-52 flex-1"><Search className="absolute left-3 top-3 size-4 text-muted-foreground" /><Input aria-label="Search products" className="pl-10" placeholder="Search product, SKU, store or email" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
      </div>
      <div className="mt-4 flex flex-wrap gap-2" role="group" aria-label="Filter products">
        {FILTERS.map((f) => <Button key={f.key} size="sm" variant={filter === f.key ? "default" : "outline"} aria-pressed={filter === f.key} onClick={() => setFilter(f.key)}>{f.label}{f.key === "waiting" && waitingCount > 0 ? ` (${waitingCount})` : ""}</Button>)}
      </div>
      <p className="mt-6 text-sm text-muted-foreground">{visible.length} product{visible.length === 1 ? "" : "s"}</p>
      <ul className="mt-3 divide-y border-y">
        {visible.map((p) => (
          <li key={p.id} className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-4 py-4">
            <ProductImage path={p.featured_image ?? undefined} alt={p.name} className="size-16 rounded-md border" />
            <div className="min-w-0"><h3 className="truncate font-semibold">{p.name}</h3><p className="mt-0.5 truncate text-sm text-muted-foreground">{p.store_name} · {categoryLabel(p) || "No category"} · {priceLabel(p)}</p><div className="mt-2"><Badge variant={p.status === "pending_review" ? "default" : "outline"}>{visibility(p).label}</Badge></div></div>
            <Button variant="outline" onClick={() => setSelectedId(p.id)}>{p.status === "pending_review" ? "Review" : "Open"}</Button>
          </li>
        ))}
      </ul>
      {!visible.length && <p className="py-12 text-center text-muted-foreground">{filter === "waiting" ? "Nothing is waiting for review." : "No products match."}</p>}
    </div>
  );
}
