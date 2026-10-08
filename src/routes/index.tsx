import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowUpRight, ArrowRight, Check, MoveUpRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { SiteHeader } from "@/components/SiteHeader";
import { VendorScene } from "@/components/VendorScene";
import { STEPS } from "@/lib/application";

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
        <div className="hero-watermark" aria-hidden="true">DDLX</div>
        <div className="relative z-10 mx-auto max-w-7xl px-6 pt-14 sm:px-10 lg:pt-20">
          <p className="eyebrow flex items-center gap-2 text-muted-foreground"><span className="h-2 w-2 rounded-full bg-accent" /> THE DDLX VENDOR COMMUNITY</p>
          <h1 className="mt-7 max-w-xl text-5xl font-semibold leading-[1.12] sm:text-6xl lg:text-7xl">Become a<br />DDLX Seller<span className="text-accent">.</span></h1>
          <p className="mt-6 max-w-sm text-base leading-7 text-muted-foreground">Your brand. The next chapter.<br />Bring your clothing, footwear and accessories to DDLX Apparel.</p>
          <div className="mt-8 flex flex-wrap items-center gap-4">
            <Button asChild size="lg" className="h-12 gap-6 rounded-md px-6"><Link to="/apply">Start application <ArrowUpRight className="size-4" /></Link></Button>
            <Button asChild variant="ghost" className="gap-2"><Link to="/auth">Continue application <ArrowRight className="size-4" /></Link></Button>
          </div>
          <div className="mt-7 flex items-center gap-2 text-xs text-muted-foreground"><Check className="size-3 text-accent" /> Clothing <span className="mx-2">/</span> Footwear <span className="mx-2">/</span> Accessories</div>
        </div>
        <div className="hero-caption absolute bottom-9 right-10 z-10 hidden items-center gap-4 text-xs text-muted-foreground md:flex"><span className="h-px w-12 bg-border" /> MADE FOR YOUR NEXT CHAPTER <MoveUpRight className="size-4" /></div>
      </section>
      <section className="border-b bg-secondary/40">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-5 px-6 py-7 sm:px-10">
          <p className="eyebrow text-muted-foreground">INDEPENDENT BRANDS. ONE DESTINATION.</p>
          <div className="flex flex-wrap gap-7 text-sm font-medium"><span>Fashion</span><span className="text-accent">✳</span><span>Culture</span><span className="text-accent">✳</span><span>Commerce</span></div>
        </div>
      </section>
      <section className="mx-auto max-w-7xl px-6 py-16 sm:px-10">
        <div className="flex flex-wrap items-end justify-between gap-4"><div><p className="eyebrow text-accent">YOUR APPLICATION</p><h2 className="mt-3 text-3xl font-semibold">A new beginning, in five steps.</h2></div><span className="text-sm text-muted-foreground">From introduction to review.</span></div>
        <ol className="mt-10 grid gap-6 sm:grid-cols-3 lg:grid-cols-5">{STEPS.map((s) => <li key={s.n} className="border-t pt-5"><span className="font-display text-sm text-accent">0{s.n}</span><h3 className="mt-4 text-sm font-semibold">{s.title}</h3></li>)}</ol>
      </section>
    </main>
    <footer className="border-t"><div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-7 text-xs text-muted-foreground sm:px-10"><span className="font-display font-semibold text-foreground">DDLX APPAREL</span><a href="https://ddlxapparel.com" className="flex items-center gap-2">Visit the marketplace <ArrowUpRight className="size-3" /></a></div></footer>
  </div>;
}
