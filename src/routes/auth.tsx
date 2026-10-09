import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { z } from "zod";
import { supabase } from "@/integrations/supabase/client";
import { lovable } from "@/integrations/lovable/index";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SiteHeader } from "@/components/SiteHeader";
import { VendorScene } from "@/components/VendorScene";
import { ArrowUpRight, ArrowRight, ShieldCheck } from "lucide-react";

export const Route = createFileRoute("/auth")({
  head: () => ({
    meta: [
      { title: "Sign in — DDLX Seller Center" },
      { name: "description", content: "Sign in or create an account to start your DDLX seller application." },
      { property: "og:title", content: "Sign in — DDLX Seller Center" },
      { property: "og:description", content: "Sign in to continue your DDLX seller application." },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary" },
    ],
  }),
  component: AuthPage,
});

const schema = z.object({
  email: z.string().trim().email("Enter a valid email").max(255),
  password: z.string().min(8, "Password must be at least 8 characters").max(72),
});

function AuthPage() {
  const [mode, setMode] = useState<"signin" | "signup">("signup");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    supabase.auth.getSession().then(({ data }) => {
      if (data.session) navigate({ to: "/apply" });
    });
    const { data } = supabase.auth.onAuthStateChange((_e, session) => {
      if (session) navigate({ to: "/apply" });
    });
    return () => data.subscription.unsubscribe();
  }, [navigate]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const p = schema.safeParse({ email, password });
    if (!p.success) { toast.error(p.error.issues[0]?.message ?? "Invalid input"); return; }
    setBusy(true);
    if (mode === "signup") {
      const { data, error } = await supabase.auth.signUp({
        email: p.data.email,
        password: p.data.password,
        options: { emailRedirectTo: `${window.location.origin}/apply` },
      });
      if (error) toast.error(error.message);
      else if (!data.session) toast.success("Check your email to confirm your account, then sign in.");
    } else {
      const { error } = await supabase.auth.signInWithPassword(p.data);
      if (error) toast.error(error.message);
    }
    setBusy(false);
  };

  const google = async () => {
    const r = await lovable.auth.signInWithOAuth("google", { redirect_uri: window.location.origin + "/auth" });
    if (r.error) toast.error("Google sign-in failed");
  };

  return (
    <div className="min-h-screen">
      <SiteHeader />
      <main className="auth-layout mx-auto grid max-w-[1440px] gap-10 px-4 py-8 sm:px-8 lg:grid-cols-[minmax(0,1.15fr)_minmax(360px,.85fr)] lg:gap-20 lg:px-12 lg:py-14">
        <section className="auth-art relative overflow-hidden border-b lg:border-b-0 lg:border-r">
          <p className="eyebrow text-accent">DDLX SELLER PLATFORM</p>
          <h2 className="relative z-10 mt-5 max-w-xl text-4xl font-semibold leading-[1.02] sm:text-6xl">Everything your store needs, after approval.</h2>
          <VendorScene subdued />
          <p className="absolute bottom-5 left-0 text-xs font-semibold text-muted-foreground">BRAND · PRODUCTS · ORDERS · GROWTH</p>
        </section>
        <section className="mx-auto w-full max-w-md lg:pt-5">
        <p className="eyebrow mb-4 text-accent">{mode === "signup" ? "JOIN THE COMMUNITY" : "YOUR VENDOR ACCOUNT"}</p>
        <h1 className="text-3xl font-semibold sm:text-4xl">{mode === "signup" ? "Create your account" : "Welcome back"}<span className="text-accent">.</span></h1>
        <p className="mt-2 text-muted-foreground">
          {mode === "signup" ? "Your journey with DDLX starts here." : "Good to see you again."}
        </p>
        <div className="mt-8">
          <Button variant="outline" className="h-12 w-full gap-3 bg-card" onClick={google} type="button">
            <span className="font-bold text-lg" aria-hidden="true">G</span> Continue with Google
          </Button>
          <div className="my-5 flex items-center gap-3 text-xs text-muted-foreground">
            <div className="h-px flex-1 bg-border" /> or <div className="h-px flex-1 bg-border" />
          </div>
          <form onSubmit={submit} className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="email">Email</Label>
              <Input id="email" type="email" placeholder="you@yourbrand.com" className="h-12 bg-card" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="password">Password</Label>
              <Input id="password" type="password" placeholder="At least 8 characters" className="h-12 bg-card" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete={mode === "signup" ? "new-password" : "current-password"} />
            </div>
            <Button type="submit" className="h-12 w-full justify-between px-5" disabled={busy}>
              {busy ? "Please wait…" : mode === "signup" ? "Create account" : "Sign in"}<ArrowUpRight className="size-4" />
            </Button>
          </form>
        </div>
        <Button variant="link" className="mt-5 h-auto w-full whitespace-normal text-sm" onClick={() => setMode(mode === "signup" ? "signin" : "signup")}>
          {mode === "signup" ? "Already have an account? Sign in" : "New here? Create an account"}<ArrowRight className="size-3" />
        </Button>
        <p className="mt-10 flex items-center justify-center gap-2 border-t pt-6 text-xs text-muted-foreground"><ShieldCheck className="size-4" /> DDLX Apparel · Vendor community</p>
        </section>
      </main>
    </div>
  );
}
