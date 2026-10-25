import { createFileRoute, Link, redirect } from "@tanstack/react-router";
import { useSuspenseQuery } from "@tanstack/react-query";
import { AlertTriangle, Boxes, PackageCheck, PackagePlus, Warehouse } from "lucide-react";
import { SiteHeader } from "@/components/SiteHeader";
import { Button } from "@/components/ui/button";
import { InventoryPanel } from "@/components/seller/InventoryPanel";
import { ProductsPanel } from "@/components/seller/ProductsPanel";
import { Money, SalesPanel } from "@/components/seller/SalesPanel";
import { StockBadge } from "@/components/seller/StockBadge";
import { productsQuery, stockState, visibility } from "@/lib/products";
import { salesOverviewQuery, salesQuery } from "@/lib/sales";
import { homePath } from "@/lib/stage";

type Tab = "overview" | "products" | "inventory" | "sales";
const TABS: { key: Tab; label: string }[] = [
  { key: "overview", label: "Overview" },
  { key: "products", label: "Products" },
  { key: "inventory", label: "Inventory" },
  { key: "sales", label: "Sales" },
];

export const Route = createFileRoute("/_authenticated/seller")({
  head: () => ({ meta: [{ title: "Seller Center — Verola" }, { name: "description", content: "Manage your products and stock on Verola." }, { property: "og:title", content: "Seller Center — Verola" }, { property: "og:type", content: "website" }, { name: "twitter:card", content: "summary" }] }),
  validateSearch: (search: Record<string, unknown>): { tab: Tab } => {
    const tab = search["tab"];
    return { tab: tab === "products" ? "products" : tab === "inventory" ? "inventory" : tab === "sales" ? "sales" : "overview" };
  },
  // Only verified sellers belong here; everyone else is sent to the step that fits them.
  beforeLoad: ({ context }) => {
    if (context.user.stage !== "verified") throw redirect({ to: homePath(context.user) });
  },
  loader: ({ context }) => Promise.all([context.queryClient.ensureQueryData(productsQuery), context.queryClient.ensureQueryData(salesOverviewQuery), context.queryClient.ensureQueryData(salesQuery)]),
  component: Seller,
});

function Stat({ icon, label, value }: { icon: React.ReactNode; label: string; value: number }) {
  return <div className="rounded-md border p-5"><div className="flex items-center gap-2 text-sm text-muted-foreground">{icon}{label}</div><p className="mt-3 text-3xl font-semibold">{value}</p></div>;
}

function Overview() {
  const { data: products } = useSuspenseQuery(productsQuery);
  const navigate = Route.useNavigate();
  const live = products.filter((p) => p.live).length;
  const review = products.filter((p) => p.status === "pending_review").length;
  const changes = products.filter((p) => p.status === "changes_requested" || p.status === "rejected");
  const low = products.filter((p) => stockState(p) === "low");
  const out = products.filter((p) => stockState(p) === "out");
  const attention = [...changes, ...out, ...low].filter((p, i, all) => all.indexOf(p) === i).slice(0, 6);
  return (
    <div className="mt-8">
      <Money />
      <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Stat icon={<Boxes className="size-4" />} label="Products" value={products.length} />
        <Stat icon={<PackageCheck className="size-4" />} label="Live on marketplace" value={live} />
        <Stat icon={<AlertTriangle className="size-4" />} label="In review" value={review} />
        <Stat icon={<Warehouse className="size-4" />} label="Low or out of stock" value={low.length + out.length} />
      </div>
      <div className="mt-8 flex flex-wrap gap-3">
        <Button asChild><Link to="/product-editor"><PackagePlus className="size-4" /> {products.length ? "Add product" : "Add your first product"}</Link></Button>
        <Button variant="outline" onClick={() => void navigate({ search: { tab: "inventory" } })}><Warehouse className="size-4" /> Manage inventory</Button>
      </div>
      {attention.length > 0 && (
        <section className="mt-10 border-t pt-6">
          <h2 className="text-xl font-semibold">Needs your attention</h2>
          <ul className="mt-4 divide-y border-y">
            {attention.map((p) => <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 py-3"><Link to="/product-editor" search={{ id: p.id }} className="font-medium underline-offset-2 hover:underline">{p.name} <span className="text-sm font-normal text-muted-foreground">{p.sku}</span></Link><span className="flex items-center gap-2"><span className="text-sm text-muted-foreground">{visibility(p).label}</span><StockBadge product={p} /></span></li>)}
          </ul>
        </section>
      )}
      <p className="mt-10 text-sm text-muted-foreground">Withdrawals and store settings are coming next.</p>
    </div>
  );
}

function Seller() {
  const { tab } = Route.useSearch();
  const navigate = Route.useNavigate();
  return (
    <div className="min-h-screen"><SiteHeader /><main className="mx-auto max-w-5xl px-4 py-12 sm:px-8">
      <p className="eyebrow text-accent">SELLER CENTER</p>
      <h1 className="mt-3 text-3xl font-semibold sm:text-5xl">{TABS.find((t) => t.key === tab)?.label ?? "Overview"}</h1>
      <div className="mt-6 flex gap-2 border-b" role="tablist">
        {TABS.map((t) => <button key={t.key} role="tab" aria-selected={tab === t.key} onClick={() => void navigate({ search: { tab: t.key } })} className={`-mb-px border-b-2 px-4 py-3 text-sm font-semibold ${tab === t.key ? "border-accent text-foreground" : "border-transparent text-muted-foreground"}`}>{t.label}</button>)}
      </div>
      {tab === "products" ? <ProductsPanel /> : tab === "inventory" ? <InventoryPanel /> : tab === "sales" ? <SalesPanel /> : <Overview />}
    </main></div>
  );
}
