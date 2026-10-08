import { createFileRoute } from "@tanstack/react-router";
import { SiteHeader } from "@/components/SiteHeader";

export const Route = createFileRoute("/_authenticated/admin")({
  head: () => ({ meta: [{ title: "Review applications — DDLX Seller Center" }, { name: "description", content: "Review applications on DDLX Seller Center." }, { property: "og:title", content: "Review applications — DDLX" }, { property: "og:description", content: "Review applications on DDLX Seller Center." }, { property: "og:type", content: "website" }, { name: "twitter:card", content: "summary" }] }),
  component: () => (
    <div className="min-h-screen">
      <SiteHeader />
      <main className="mx-auto max-w-3xl px-4 py-16">
        <h1 className="text-3xl font-bold">Review applications</h1>
        <p className="mt-2 text-muted-foreground">This page is being built and will be ready soon.</p>
      </main>
    </div>
  ),
});
