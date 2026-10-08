import { Link, useNavigate } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { supabase } from "@/integrations/supabase/client";
import { Button } from "@/components/ui/button";

export function SiteHeader() {
  const [email, setEmail] = useState<string | null>(null);
  const [isAdmin, setIsAdmin] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    const load = async () => {
      const { data } = await supabase.auth.getUser();
      setEmail(data.user?.email ?? null);
      if (data.user) {
        const { data: roles } = await supabase.from("user_roles").select("role").eq("user_id", data.user.id);
        setIsAdmin(!!roles?.some((r) => r.role === "admin"));
      } else setIsAdmin(false);
    };
    load();
    const { data } = supabase.auth.onAuthStateChange(() => load());
    return () => data.subscription.unsubscribe();
  }, []);

  return (
    <header className="border-b bg-background/90 backdrop-blur sticky top-0 z-30">
      <div className="mx-auto flex h-20 max-w-7xl items-center justify-between gap-3 px-6 sm:px-10">
        <Link to="/" className="flex items-center gap-2">
          <span className="font-display text-2xl font-bold">DDLX<span className="text-accent">.</span></span>
          <span className="ml-3 border-l pl-4 text-[10px] font-semibold uppercase text-muted-foreground sm:text-xs">Vendor</span>
        </Link>
        <nav className="flex items-center gap-2">
          {isAdmin && (
            <Button asChild variant="ghost" size="sm">
              <Link to="/admin">Review applications</Link>
            </Button>
          )}
          {email ? (
            <>
              <Button asChild variant="ghost" size="sm">
                <Link to="/apply">My application</Link>
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={async () => {
                  await supabase.auth.signOut();
                  navigate({ to: "/" });
                }}
              >
                Sign out
              </Button>
            </>
          ) : (
            <Button asChild size="sm">
              <Link to="/auth">Sign in</Link>
            </Button>
          )}
        </nav>
      </div>
    </header>
  );
}
