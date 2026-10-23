import { Link, useNavigate } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { getCurrentUser, signOut } from "@/lib/auth.functions";
import { Button } from "@/components/ui/button";
import { ArrowUpRight, LogOut } from "lucide-react";

export function SiteHeader() {
  const [email, setEmail] = useState<string | null>(null);
  const [isAdmin, setIsAdmin] = useState(false);
  const navigate = useNavigate();

  const queryClient = useQueryClient();

  useEffect(() => {
    getCurrentUser()
      .then((user) => {
        setEmail(user?.email ?? null);
        setIsAdmin(!!user?.isAdmin);
      })
      .catch(() => {
        setEmail(null);
        setIsAdmin(false);
      });
  }, []);

  return (
    <header className="sticky top-0 z-30 border-b bg-background/90 backdrop-blur-xl">
      <div className="mx-auto grid h-16 max-w-[1440px] grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-4 sm:flex sm:h-20 sm:px-8 lg:px-12">
        <Link to="/" className="flex min-w-0 items-center gap-3" aria-label="DDLX Vendor home">
          <span className="shrink-0 font-display text-xl font-bold sm:text-2xl">DDLX<span className="text-accent">/</span></span>
          <span className="truncate border-l pl-3 text-[10px] font-semibold uppercase text-muted-foreground sm:pl-4 sm:text-xs">Vendor</span>
        </Link>
        <a href="https://ddlxapparel.com" className="ml-auto hidden items-center gap-2 text-xs font-semibold sm:flex">Marketplace <ArrowUpRight className="size-3.5" /></a>
        <nav className="flex shrink-0 items-center gap-1 sm:gap-2">
          {isAdmin && (
            <Button asChild variant="ghost" size="sm">
              <Link to="/admin"><span className="hidden md:inline">Review applications</span><span className="md:hidden">Review</span></Link>
            </Button>
          )}
          {email ? (
            <>
              <Button asChild variant="ghost" size="sm">
                <Link to="/apply"><span className="hidden sm:inline">My application</span><span className="sm:hidden">Application</span></Link>
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={async () => {
                  await signOut();
                  queryClient.clear();
                  setEmail(null);
                  setIsAdmin(false);
                  navigate({ to: "/" });
                }}
              >
                <LogOut className="size-4" /><span className="sr-only">Sign out</span>
              </Button>
            </>
          ) : (
            <Button asChild size="sm">
              <Link to="/auth">Sign in <ArrowUpRight className="size-3.5" /></Link>
            </Button>
          )}
        </nav>
      </div>
    </header>
  );
}
