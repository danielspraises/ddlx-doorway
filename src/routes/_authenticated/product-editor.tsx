import { createFileRoute, redirect } from "@tanstack/react-router";
import { useSuspenseQuery } from "@tanstack/react-query";
import { SiteHeader } from "@/components/SiteHeader";
import { ProductEditor } from "@/components/seller/ProductEditor";
import { productsQuery } from "@/lib/products";
import { homePath } from "@/lib/stage";

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export const Route = createFileRoute("/_authenticated/product-editor")({
  head: () => ({ meta: [{ title: "Product — Verola" }, { name: "description", content: "Create or edit a product for the marketplace." }] }),
  validateSearch: (search: Record<string, unknown>): { id?: string } => {
    const id = search["id"];
    return typeof id === "string" && UUID.test(id) ? { id } : {};
  },
  beforeLoad: ({ context }) => {
    if (context.user.stage !== "verified") throw redirect({ to: homePath(context.user) });
  },
  loader: ({ context }) => context.queryClient.ensureQueryData(productsQuery),
  component: Editor,
});

function Editor() {
  const { id } = Route.useSearch();
  const { data: products } = useSuspenseQuery(productsQuery);
  const product = id ? products.find((p) => p.id === id) ?? null : null;
  return (
    <div className="min-h-screen"><SiteHeader /><main className="mx-auto max-w-6xl px-4 py-12 sm:px-8">
      <p className="eyebrow text-accent">SELLER CENTER</p>
      <h1 className="mt-3 text-3xl font-semibold sm:text-5xl">{id ? (product ? "Edit product" : "Product not found") : "Add product"}</h1>
      {id && !product ? <p className="mt-8 text-muted-foreground">This product does not exist or is not yours.</p> : <ProductEditor key={product?.id ?? "new"} initial={product} />}
    </main></div>
  );
}
