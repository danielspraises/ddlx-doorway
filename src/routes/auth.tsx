import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { z } from "zod";
import { getCurrentUser, requestPasswordReset, resetPassword, signIn, signUp } from "@/lib/auth.functions";
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

const emailSchema = z.object({ email: z.string().trim().email("Enter a valid email").max(255) });
const newPasswordSchema = z.object({ password: z.string().min(8, "Password must be at least 8 characters").max(72) });
const schema = z.object({
  email: z.string().trim().email("Enter a valid email").max(255),
  password: z.string().min(8, "Password must be at least 8 characters").max(72),
});

function AuthPage() {
  const [mode, setMode] = useState<"signin" | "signup" | "forgot" | "recover">("signup");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const resetToken = useRef<string | null>(null);

  useEffect(() => {
    resetToken.current = new URLSearchParams(window.location.search).get("token");
    if (resetToken.current) {
      setMode("recover");
      return;
    }
    getCurrentUser()
      .then((user) => { if (user) navigate({ to: "/apply" }); })
      .catch(() => undefined);
  }, [navigate]);

  const enter = () => {
    queryClient.clear();
    navigate({ to: "/apply" });
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (busy) return;
    if (mode === "forgot") {
      const parsed = emailSchema.safeParse({ email });
      if (!parsed.success) { toast.error(parsed.error.issues[0]?.message ?? "Enter a valid email"); return; }
      setBusy(true);
      try {
        await requestPasswordReset({ data: { email: parsed.data.email } });
        toast.success("If that email has an account, a reset link has been created.");
      } catch { toast.error("Could not start the reset. Please try again."); }
      setBusy(false);
      return;
    }
    if (mode === "recover") {
      const parsed = newPasswordSchema.safeParse({ password });
      if (!parsed.success) { toast.error(parsed.error.issues[0]?.message ?? "Invalid password"); return; }
      setBusy(true);
      try {
        const result = await resetPassword({ data: { token: resetToken.current ?? "", password: parsed.data.password } });
        if (!result.ok) { toast.error(result.error); setBusy(false); return; }
        toast.success("Password updated.");
        enter();
      } catch { toast.error("Could not update the password. Please try again."); setBusy(false); }
      return;
    }
    const p = schema.safeParse({ email, password });
    if (!p.success) { toast.error(p.error.issues[0]?.message ?? "Invalid input"); return; }
    setBusy(true);
    try {
      const result = mode === "signup" ? await signUp({ data: p.data }) : await signIn({ data: p.data });
      if (!result.ok) { toast.error(result.error); setBusy(false); return; }
      enter();
    } catch { toast.error("Something went wrong. Please try again."); setBusy(false); }
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
        <p className="eyebrow mb-4 text-accent">{mode === "signup" ? "JOIN THE COMMUNITY" : mode === "forgot" || mode === "recover" ? "ACCOUNT RECOVERY" : "YOUR VENDOR ACCOUNT"}</p>
        <h1 className="text-3xl font-semibold sm:text-4xl">{mode === "signup" ? "Create your account" : mode === "forgot" ? "Reset your password" : mode === "recover" ? "Choose a new password" : "Welcome back"}<span className="text-accent">.</span></h1>
        <p className="mt-2 text-muted-foreground">
          {mode === "signup" ? "Your journey with DDLX starts here." : mode === "forgot" ? "Enter your email and we will send you a reset link." : mode === "recover" ? "Enter a new password for your account." : "Good to see you again."}
        </p>
        <div className="mt-8">
          <form onSubmit={submit} className="space-y-4">
            {mode !== "recover" && <div className="space-y-1.5">
              <Label htmlFor="email">Email</Label>
              <Input id="email" type="email" placeholder="you@yourbrand.com" className="h-12 bg-card" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" />
            </div>}
            {mode !== "forgot" && <div className="space-y-1.5">
              <Label htmlFor="password">{mode === "recover" ? "New password" : "Password"}</Label>
              <Input id="password" type="password" placeholder="At least 8 characters" className="h-12 bg-card" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete={mode === "signup" || mode === "recover" ? "new-password" : "current-password"} />
              {mode === "signin" && <button type="button" className="text-sm text-accent underline-offset-4 hover:underline" onClick={() => setMode("forgot")}>Forgot password?</button>}
            </div>}
            <Button type="submit" className="h-12 w-full justify-between px-5" disabled={busy}>
              {busy ? "Please wait…" : mode === "signup" ? "Create account" : mode === "forgot" ? "Send reset link" : mode === "recover" ? "Update password" : "Sign in"}<ArrowUpRight className="size-4" />
            </Button>
          </form>
        </div>
        <Button variant="link" className="mt-5 h-auto w-full whitespace-normal text-sm" onClick={() => setMode(mode === "signup" ? "signin" : mode === "signin" ? "signup" : "signin")}>
          {mode === "signup" ? "Already have an account? Sign in" : mode === "signin" ? "New here? Create an account" : "Back to sign in"}<ArrowRight className="size-3" />
        </Button>
        <p className="mt-10 flex items-center justify-center gap-2 border-t pt-6 text-xs text-muted-foreground"><ShieldCheck className="size-4" /> DDLX Apparel · Vendor community</p>
        </section>
      </main>
    </div>
  );
}
