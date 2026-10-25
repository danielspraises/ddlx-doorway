import { createFileRoute, redirect } from "@tanstack/react-router";
import { SiteHeader } from "@/components/SiteHeader";
import { useState } from "react";
import { useSuspenseQuery, useQueryClient } from "@tanstack/react-query";
import { Check, ArrowLeft, Search, X, MessageSquare } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { SelectField } from "@/components/FormFields";
import { ApplicationSummary } from "@/components/ApplicationSummary";
import { reviewsQuery, section } from "@/lib/application-data";
import { STATUS_LABEL } from "@/lib/application";
import { reviewApplication } from "@/lib/applications.functions";
import { VerificationReview } from "@/components/VerificationReview";
import { homePath } from "@/lib/stage";
import { verificationReviewsQuery } from "@/lib/verification";
import { ProductReview } from "@/components/ProductReview";
import { StaffPanel } from "@/components/StaffPanel";
import { productReviewsQuery, staffQuery } from "@/lib/product-reviews";

type Tab = "applications" | "verifications" | "products" | "staff";

export const Route = createFileRoute("/_authenticated/admin")({
  head: () => ({ meta: [{ title: "Review applications — Vendor Hub" }, { name: "description", content: "Review applications on Vendor Hub." }, { property: "og:title", content: "Review applications — DDLX" }, { property: "og:description", content: "Review applications on Vendor Hub." }, { property: "og:type", content: "website" }, { name: "twitter:card", content: "summary" }] }),
  validateSearch: (search: Record<string, unknown>): { tab?: Tab } => {
    const tab = search["tab"];
    return tab === "applications" || tab === "verifications" || tab === "products" || tab === "staff" ? { tab } : {};
  },
  beforeLoad: ({ context }) => {
    if (!context.user.isAdmin && !context.user.canReviewProducts) throw redirect({ to: homePath(context.user) });
  },
  loader: ({ context }) => {
    const { user, queryClient } = context;
    const jobs: Promise<unknown>[] = [];
    if (user.isAdmin) jobs.push(queryClient.ensureQueryData(reviewsQuery), queryClient.ensureQueryData(verificationReviewsQuery), queryClient.ensureQueryData(staffQuery));
    jobs.push(queryClient.ensureQueryData(productReviewsQuery));
    return Promise.all(jobs);
  },
  component: Admin,
});

function ApplicationsPanel() {
  const { data: applications } = useSuspenseQuery(reviewsQuery);
  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState("Under review");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [comment, setComment] = useState("");
  const [saving, setSaving] = useState(false);
  const selected = applications.find(a => a.id === selectedId);
  const visible = applications.filter(a => (filter === "All" || STATUS_LABEL[a.status] === filter) && `${a.applicant_email} ${section(a.personal)["first_name"]} ${section(a.personal)["last_name"]} ${section(a.brand)["store_name"]}`.toLowerCase().includes(search.toLowerCase()));
  const review = async (status: "approved" | "rejected" | "needs_corrections") => {
    if (!selected || saving) return;
    if (status !== "approved" && !comment.trim()) { toast.error("Add feedback for the applicant first."); return; }
    if (!window.confirm(`${STATUS_LABEL[status]} — confirm this decision?`)) return;
    setSaving(true);
    try {
      const result = await reviewApplication({ data: { id: selected.id, status, comment: comment.trim(), expected_updated_at: selected.updated_at } });
      if (!result.ok) { toast.error(result.error); return; }
      await queryClient.invalidateQueries({ queryKey: reviewsQuery.queryKey });
      await queryClient.invalidateQueries({ queryKey: ["application"] });
      await queryClient.invalidateQueries({ queryKey: ["me"] });
      toast.success(status === "approved" ? "Approved. The seller can now start verification." : status === "needs_corrections" ? "Sent back to the applicant for corrections." : "Application rejected.");
      setSelectedId(null);
    } catch { toast.error("Could not save the decision. Refresh and try again."); }
    finally { setSaving(false); }
  };
  return <div>
    {selected ? <>
      <Button variant="ghost" className="mt-5" onClick={() => setSelectedId(null)}><ArrowLeft className="size-4" /> All applications</Button>
      <h2 className="mt-4 text-2xl font-semibold">{section(selected.brand)["store_name"] || "Application details"}</h2>
      <div className="mt-6 flex flex-wrap gap-x-6 gap-y-2 border-y py-5 text-sm"><span className="font-semibold">{STATUS_LABEL[selected.status]}</span><span className="text-muted-foreground">{selected.applicant_email}</span><span className="text-muted-foreground">Submitted {selected.submitted_at ? new Date(selected.submitted_at).toLocaleDateString() : "—"}</span></div>
      <div className="mt-8"><ApplicationSummary sections={{ personal: section(selected.personal), business: section(selected.business), brand: section(selected.brand), registration: section(selected.registration) }} /></div>
      <section className="border-t pt-6"><h2 className="text-xl font-semibold">Applicant declarations</h2><p className="mt-3 text-sm text-muted-foreground">{[0, 1, 2, 3].every(i => section(selected.declaration)[String(i)] === "yes") ? "All four declarations confirmed." : "Declarations incomplete."}</p></section>
      {selected.status !== "submitted" ? <section className="mt-8 border-t pt-6"><h3 className="font-semibold">Decision</h3><p className="mt-2 text-sm text-muted-foreground">{STATUS_LABEL[selected.status]}{selected.reviewed_at ? ` on ${new Date(selected.reviewed_at).toLocaleDateString()}` : ""}. This item is no longer waiting for review.</p>{selected.admin_comment && <p className="mt-3 whitespace-pre-wrap">{selected.admin_comment}</p>}</section> : <section className="mt-8 border-t pt-6"><Label htmlFor="review-comment">Feedback to applicant</Label><Textarea id="review-comment" className="mt-3" rows={4} value={comment} onChange={e => setComment(e.target.value)} /><div className="mt-5 flex flex-wrap gap-3"><Button disabled={saving} onClick={() => review("approved")}><Check className="size-4" /> Approve</Button><Button variant="outline" disabled={saving} onClick={() => review("needs_corrections")}><MessageSquare className="size-4" /> Request corrections</Button><Button variant="destructive" disabled={saving} onClick={() => review("rejected")}><X className="size-4" /> Reject</Button></div></section>}
    </> : <>
      <div className="mt-8 grid gap-4 sm:grid-cols-[minmax(0,1fr)_240px]"><div className="relative self-end"><Search className="absolute left-3 top-3 size-4 text-muted-foreground" /><Input aria-label="Search applications" className="pl-10" placeholder="Search store, name or email" value={search} onChange={e => setSearch(e.target.value)} /></div><SelectField name="status-filter" label="Status" required value={filter} onChange={setFilter} options={["All", "Under review", "Needs corrections", "Approved", "Rejected"]} /></div>
      <p className="mt-6 text-sm text-muted-foreground">{visible.length} application{visible.length === 1 ? "" : "s"}</p>
      <ul className="mt-3 divide-y border-y">{visible.map(a => <li key={a.id} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-4 py-5"><div className="min-w-0"><h2 className="truncate text-lg font-semibold">{section(a.brand)["store_name"] || "Unnamed store"}</h2><p className="mt-1 truncate text-sm text-muted-foreground">{a.applicant_email}</p><p className="mt-2 text-xs font-medium text-accent">{STATUS_LABEL[a.status]}</p></div><Button variant="outline" onClick={() => { setSelectedId(a.id); setComment(a.admin_comment ?? ""); }}>Review</Button></li>)}</ul>
      {!visible.length && <p className="py-12 text-center text-muted-foreground">{search || filter !== "All" ? "No applications match your search." : "No submitted applications yet."}</p>}
    </>}
  </div>;
}

function Admin() {
  const { user } = Route.useRouteContext();
  const { tab } = Route.useSearch();
  const navigate = Route.useNavigate();
  const tabs: { key: Tab; label: string; badge: number }[] = [
    ...(user.isAdmin ? [{ key: "applications" as const, label: "Applications", badge: user.pending.applications }, { key: "verifications" as const, label: "Verifications", badge: user.pending.verifications }] : []),
    { key: "products", label: "Products", badge: user.pending.products },
    ...(user.isAdmin ? [{ key: "staff" as const, label: "Staff", badge: 0 }] : []),
  ];
  const active: Tab = tab && tabs.some((t) => t.key === tab) ? tab : (tabs[0]?.key ?? "products");
  return <div className="min-h-screen"><SiteHeader /><main className="mx-auto max-w-5xl px-4 py-12 sm:px-8">
    <p className="eyebrow text-accent">{user.isAdmin ? "STAFF REVIEW QUEUE" : "PRODUCT REVIEW"}</p><h1 className="mt-3 text-3xl font-semibold sm:text-5xl">{tabs.find((t) => t.key === active)?.label ?? "Review queue"}</h1>
    <div className="mt-6 flex flex-wrap gap-2 border-b" role="tablist">{tabs.map((t) => <button key={t.key} role="tab" aria-selected={active === t.key} onClick={() => void navigate({ search: { tab: t.key } })} className={`-mb-px border-b-2 px-4 py-3 text-sm font-semibold ${active === t.key ? "border-accent text-foreground" : "border-transparent text-muted-foreground"}`}>{t.label}{t.badge > 0 && <span className="ml-2 inline-flex min-w-5 items-center justify-center rounded-full bg-accent px-1.5 text-[11px] leading-5 text-accent-foreground">{t.badge}</span>}</button>)}</div>
    {active === "applications" ? <ApplicationsPanel /> : active === "verifications" ? <VerificationReview /> : active === "staff" ? <StaffPanel /> : <ProductReview />}
  </main></div>;
}
