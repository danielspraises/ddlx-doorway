import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowUpRight, ArrowRight, Check, Package, Store, BarChart3, WalletCards } from "lucide-react";
import { Button } from "@/components/ui/button";
import { SiteHeader } from "@/components/SiteHeader";
import { VendorScene } from "@/components/VendorScene";
import { ApplicationSteps } from "@/components/ApplicationSteps";

export const Route = createFileRoute("/")({
  head: () => ({ meta: [
    { title: "Become a DDLX Seller — DDLX Vendor" },
    { name: "description", content: "Bring your clothing, footwear and accessories to DDLX Apparel. Start your seller application." },
    { property: "og:title", content: "Become a DDLX Seller" },
    { property: "og:description", content: "Your brand. The next chapter. Apply to join DDLX Apparel." },
    { property: "og:type", content: "website" },
    { name: "twitter:card", content: "summary_large_image" },
  ] }),
  component: Index,
});

function Index() {
  return <div className="min-h-screen">
    <SiteHeader />
    <main>
      <section className="vendor-hero relative overflow-hidden border-b">
        <VendorScene />
        <div className="hero-grid" aria-hidden="true" />
        <div className="relative z-10 mx-auto max-w-[1440px] px-4 pt-12 sm:px-8 sm:pt-20 lg:px-12 lg:pt-24">
          <p className="eyebrow flex items-center gap-2 text-muted-foreground"><span className="h-2 w-2 rounded-full bg-accent" /> DDLX SELLER PLATFORM</p>
          <h1 className="mt-6 max-w-3xl text-5xl font-semibold leading-[0.98] sm:text-7xl lg:text-[88px]">Build your brand.<br />Run your store<span className="text-accent">.</span></h1>
          <p className="mt-6 max-w-md text-sm leading-6 text-muted-foreground sm:text-base sm:leading-7">Apply to sell, then manage products, inventory, orders and growth from one focused workspace.</p>
          <div className="mt-8 flex flex-col items-stretch gap-3 min-[420px]:flex-row min-[420px]:items-center">
            <Button asChild size="lg" className="h-12 justify-between gap-8 rounded-md px-5"><Link to="/apply">Start application <ArrowUpRight className="size-4" /></Link></Button>
            <Button asChild variant="ghost" className="h-12 justify-between gap-3 min-[420px]:justify-center"><Link to="/auth">Continue application <ArrowRight className="size-4" /></Link></Button>
          </div>
          <div className="mt-7 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-muted-foreground"><Check className="size-3 text-accent" /> Clothing <span>/</span> Footwear <span>/</span> Accessories</div>
        </div>
        <div className="hero-caption absolute bottom-9 right-12 z-10 hidden items-center gap-4 text-xs font-semibold text-muted-foreground lg:flex"><span className="h-px w-12 bg-border" /> MADE FOR INDEPENDENT COMMERCE</div>
      </section>
      <section className="border-b bg-primary text-primary-foreground">
        <div className="mx-auto grid max-w-[1440px] gap-6 px-4 py-7 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-8 lg:px-12">
          <p className="eyebrow">FROM APPLICATION TO EVERYDAY OPERATIONS</p>
          <div className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm font-medium sm:flex sm:gap-7"><span className="flex items-center gap-2"><Store className="size-4 text-accent" /> Store</span><span className="flex items-center gap-2"><Package className="size-4 text-accent" /> Inventory</span><span className="flex items-center gap-2"><BarChart3 className="size-4 text-accent" /> Insights</span><span className="flex items-center gap-2"><WalletCards className="size-4 text-accent" /> Payouts</span></div>
        </div>
      </section>
      <section className="mx-auto max-w-[1440px] px-4 py-16 sm:px-8 sm:py-24 lg:px-12">
        <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"><div><p className="eyebrow text-accent">YOUR APPLICATION</p><h2 className="mt-4 max-w-2xl text-3xl font-semibold sm:text-5xl">Five clear steps to your DDLX store.</h2></div><span className="text-sm text-muted-foreground">Progress saves as you go.</span></div>
        <ApplicationSteps />
      </section>
    </main>
    <footer className="border-t"><div className="mx-auto grid max-w-[1440px] grid-cols-[minmax(0,1fr)_auto] items-center gap-4 px-4 py-7 text-xs text-muted-foreground sm:px-8 lg:px-12"><span className="truncate font-display font-semibold text-foreground">DDLX APPAREL</span><a href="https://ddlxapparel.com" className="flex shrink-0 items-center gap-2">Marketplace <ArrowUpRight className="size-3" /></a></div></footer>
  </div>;
}
