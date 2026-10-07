import { createFileRoute } from "@tanstack/react-router";
import { SiteHeader } from "@/components/SiteHeader";

export const Route = createFileRoute("/_authenticated/apply")({
  head: () => ({ meta: [{ title: "My application — DDLX Seller Center" }, { name: "description", content: "My application on DDLX Seller Center." }, { property: "og:title", content: "My application — DDLX" }, { property: "og:description", content: "My application on DDLX Seller Center." }] }),
  component: () => (
    <div className="min-h-screen">
      <SiteHeader />
      <main className="mx-auto max-w-3xl px-4 py-16">
        <h1 className="text-3xl font-bold">My application</h1>
        <p className="mt-2 text-muted-foreground">This page is being built and will be ready soon.</p>
      </main>
    </div>
  ),
});
