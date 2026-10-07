import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowRight, CheckCircle2, ClipboardList, Store, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { SiteHeader } from "@/components/SiteHeader";
import { STEPS } from "@/lib/application";

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "Become a DDLX Seller — DDLX Seller Center" },
      { name: "description", content: "Apply to sell your clothing, footwear and accessories on DDLX Apparel. Save as you go and submit in minutes." },
      { property: "og:title", content: "Become a DDLX Seller" },
      { property: "og:description", content: "Apply to sell on DDLX Apparel. Save as you go and submit in minutes." },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary_large_image" },
    ],
  }),
  component: Index,
});

function Index() {
  return (
    <div className="min-h-screen">
      <SiteHeader />
      <main className="mx-auto max-w-6xl px-4 sm:px-6">
        <section className="grid gap-12 py-20 md:grid-cols-[1.2fr_1fr] md:py-28">
          <div>
            <p className="mb-4 inline-flex items-center gap-2 rounded-full border bg-card px-3 py-1 text-xs font-medium text-muted-foreground">
              <span className="h-1.5 w-1.5 rounded-full bg-accent" /> Seller applications are open
            </p>
            <h1 className="text-4xl font-bold leading-[1.05] sm:text-6xl">
              Sell your brand
              <br />
              on <span className="text-accent">DDLX</span>.
            </h1>
            <p className="mt-6 max-w-lg text-lg text-muted-foreground">
              Join a curated marketplace for clothing, footwear and accessories. Your progress is saved at every step — come back anytime.
            </p>
            <div className="mt-8 flex flex-wrap gap-3">
              <Button asChild size="lg">
                <Link to="/apply">
                  Start application <ArrowRight className="ml-1 h-4 w-4" />
                </Link>
              </Button>
              <Button asChild size="lg" variant="outline">
                <Link to="/auth">I already started</Link>
              </Button>
            </div>
          </div>
          <div className="rounded-2xl border bg-card p-6 shadow-sm">
            <p className="mb-4 text-sm font-semibold">What you'll need</p>
            <ol className="space-y-3">
              {STEPS.map((s) => (
                <li key={s.n} className="flex items-center gap-3 rounded-lg bg-secondary/60 px-4 py-3 text-sm">
                  <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">
                    {s.n}
                  </span>
                  {s.title}
                </li>
              ))}
            </ol>
          </div>
        </section>
        <section className="grid gap-4 pb-24 sm:grid-cols-3">
          {[
            { icon: ClipboardList, t: "Save as you go", d: "Every step is saved. Pick up where you left off." },
            { icon: ShieldCheck, t: "Reviewed by our team", d: "We check each application and reply with clear feedback." },
            { icon: Store, t: "Your own storefront", d: "Once approved, you get access to the DDLX Seller Center." },
          ].map(({ icon: I, t, d }) => (
            <div key={t} className="rounded-xl border bg-card p-6">
              <I className="h-5 w-5 text-accent" />
              <p className="mt-4 font-semibold">{t}</p>
              <p className="mt-1 text-sm text-muted-foreground">{d}</p>
            </div>
          ))}
        </section>
      </main>
      <footer className="border-t py-8 text-center text-xs text-muted-foreground">
        <CheckCircle2 className="mr-1 inline h-3 w-3" /> DDLX Apparel
      </footer>
    </div>
  );
}
