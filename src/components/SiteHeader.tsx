import { Link, useNavigate } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowUpRight, LogOut, Menu, UserRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { meQuery } from "@/lib/auth-data";
import { useLiveEvents } from "@/lib/live";
import { signOut } from "@/lib/auth.functions";
import { STAGE_LABEL, applicationApproved } from "@/lib/stage";

type Me = NonNullable<Awaited<ReturnType<typeof import("@/lib/auth.functions").getCurrentUser>>>;
type NavItem = { key: string; label: string; to: "/apply" | "/verification" | "/seller" | "/admin"; tab?: string; badge?: number };

// What each person sees depends on their role, and for sellers on how far they have got.
function navItems(user: Me): NavItem[] {
  if (user.isAdmin) {
    return [
      { key: "applications", label: "Applications", to: "/admin", tab: "applications", badge: user.pending.applications },
      { key: "verifications", label: "Verifications", to: "/admin", tab: "verifications", badge: user.pending.verifications },
      { key: "products-review", label: "Products", to: "/admin", tab: "products", badge: user.pending.products },
      { key: "categories", label: "Categories", to: "/admin", tab: "categories" },
      { key: "staff", label: "Staff", to: "/admin", tab: "staff" },
    ];
  }
  if (user.canReviewProducts) {
    return [{ key: "products-review", label: "Product review", to: "/admin", tab: "products", badge: user.pending.products }];
  }
  const items: NavItem[] = [{ key: "apply", label: "My application", to: "/apply" }];
  if (applicationApproved(user.stage)) items.push({ key: "verify", label: "Verification", to: "/verification" });
  if (user.stage === "verified") {
    items.push(
      { key: "seller", label: "Seller Center", to: "/seller", tab: "overview" },
      { key: "products", label: "Products", to: "/seller", tab: "products" },
      { key: "inventory", label: "Inventory", to: "/seller", tab: "inventory" },
      { key: "sales", label: "Sales", to: "/seller", tab: "sales" },
    );
  }
  return items;
}

function Badge({ count }: { count: number | undefined }) {
  if (!count) return null;
  return <span className="ml-2 inline-flex min-w-5 items-center justify-center rounded-full bg-accent px-1.5 text-[11px] font-semibold leading-5 text-accent-foreground">{count}</span>;
}

function NavLink({ item }: { item: NavItem }) {
  return (
    <Link
      to={item.to}
      search={(item.tab ? { tab: item.tab } : undefined) as never}
      activeOptions={{ includeSearch: true }}
      activeProps={{ className: "bg-secondary text-foreground" }}
      className="inline-flex h-9 items-center rounded-md px-3 text-sm font-medium text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
    >
      {item.label}
      <Badge count={item.badge} />
    </Link>
  );
}

export function SiteHeader() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { data: user } = useQuery(meQuery);
  const items = user ? navItems(user) : [];
  useLiveEvents(Boolean(user));

  const logout = async () => {
    await signOut();
    queryClient.clear();
    navigate({ to: "/" });
  };

  return (
    <header className="sticky top-0 z-30 border-b bg-background/90 backdrop-blur-xl">
      <div className="mx-auto flex h-16 max-w-[1440px] items-center gap-3 px-4 sm:h-20 sm:px-8 lg:px-12">
        <Link to="/" className="flex min-w-0 items-center gap-3" aria-label="Vendor Hub home">
          <span className="shrink-0 font-display text-xl font-bold sm:text-2xl">Vendor Hub<span className="text-accent">/</span></span>
          <span className="truncate border-l pl-3 text-[10px] font-semibold uppercase text-muted-foreground sm:pl-4 sm:text-xs">for DDLX</span>
        </Link>

        <nav aria-label="Main" className="ml-4 hidden items-center gap-1 md:flex">
          {items.map((item) => <NavLink key={item.key} item={item} />)}
        </nav>

        <div className="ml-auto flex shrink-0 items-center gap-2">
          <a href={import.meta.env["VITE_MARKETPLACE_URL"] || "https://ddlxapparel.com"} className="hidden items-center gap-2 px-2 text-xs font-semibold lg:flex">Marketplace <ArrowUpRight className="size-3.5" /></a>

          {user ? (
            <>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline" size="sm" className="gap-2" aria-label="Account menu">
                    <UserRound className="size-4" />
                    <span className="hidden max-w-40 truncate sm:inline">{user.email}</span>
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-64">
                  <DropdownMenuLabel className="space-y-1">
                    <span className="block truncate font-medium">{user.email}</span>
                    <span className="block text-xs font-normal text-muted-foreground">{user.isAdmin ? "Admin" : user.canReviewProducts ? "Staff · Product review" : STAGE_LABEL[user.stage]}</span>
                  </DropdownMenuLabel>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem onSelect={logout} className="cursor-pointer"><LogOut className="size-4" /> Sign out</DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>

              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline" size="icon" className="md:hidden" aria-label="Open menu"><Menu className="size-4" /></Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-60">
                  {items.map((item) => (
                    <DropdownMenuItem key={item.key} asChild>
                      <Link to={item.to} search={(item.tab ? { tab: item.tab } : undefined) as never} className="flex cursor-pointer items-center justify-between">
                        {item.label}<Badge count={item.badge} />
                      </Link>
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
            </>
          ) : (
            <Button asChild size="sm">
              <Link to="/auth">Sign in <ArrowUpRight className="size-3.5" /></Link>
            </Button>
          )}
        </div>
      </div>
    </header>
  );
}
