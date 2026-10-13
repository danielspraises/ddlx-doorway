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
import { supabase } from "@/integrations/supabase/client";
import { VerificationReview } from "@/components/VerificationReview";
import { verificationReviewsQuery } from "@/lib/verification";

export const Route = createFileRoute("/_authenticated/admin")({
  head: () => ({ meta: [{ title: "Review applications — DDLX Seller Center" }, { name: "description", content: "Review applications on DDLX Seller Center." }, { property: "og:title", content: "Review applications — DDLX" }, { property: "og:description", content: "Review applications on DDLX Seller Center." }, { property: "og:type", content: "website" }, { name: "twitter:card", content: "summary" }] }),
  beforeLoad: async ({ context }) => {
    const { data, error } = await supabase.rpc("has_role", { _user_id: context.user.id, _role: "admin" });
    if (error || !data) throw redirect({ to: "/apply" });
  },
  loader: ({ context }) => Promise.all([context.queryClient.ensureQueryData(reviewsQuery), context.queryClient.ensureQueryData(verificationReviewsQuery)]),
  component: Admin,
});

function Admin() {
  const { user } = Route.useRouteContext();
  const { data: applications } = useSuspenseQuery(reviewsQuery);
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<"applications" | "verifications">("applications");
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState("All");
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
      const { error } = await supabase.from("seller_applications").update({ status, admin_comment: comment.trim() || null, reviewed_by: user.id, reviewed_at: new Date().toISOString() }).eq("id", selected.id).eq("updated_at", selected.updated_at).select().single();
      if (error) throw error;
      await queryClient.invalidateQueries({ queryKey: reviewsQuery.queryKey });
      await queryClient.invalidateQueries({ queryKey: ["application"] });
      toast.success("Review decision saved.");
    } catch { toast.error("Could not save the decision. Refresh and try again."); }
    finally { setSaving(false); }
  };
  return <div className="min-h-screen"><SiteHeader /><main className="mx-auto max-w-5xl px-4 py-12 sm:px-8">
    <p className="eyebrow text-accent">STAFF REVIEW QUEUE</p><h1 className="mt-3 text-3xl font-semibold sm:text-5xl">{tab === "applications" && selected ? section(selected.brand)["store_name"] || "Application details" : "Review queue"}</h1>
    <div className="mt-6 flex gap-2 border-b" role="tablist">{(["applications", "verifications"] as const).map(t => <button key={t} role="tab" aria-selected={tab === t} onClick={() => setTab(t)} className={`-mb-px border-b-2 px-4 py-3 text-sm font-semibold capitalize ${tab === t ? "border-accent text-foreground" : "border-transparent text-muted-foreground"}`}>{t}</button>)}</div>
    {tab === "verifications" ? <VerificationReview reviewerId={user.id} /> : selected ? <>
      <Button variant="ghost" className="mt-5" onClick={() => setSelectedId(null)}><ArrowLeft className="size-4" /> All applications</Button>
      <div className="mt-6 flex flex-wrap gap-x-6 gap-y-2 border-y py-5 text-sm"><span className="font-semibold">{STATUS_LABEL[selected.status]}</span><span className="text-muted-foreground">{selected.applicant_email}</span><span className="text-muted-foreground">Submitted {selected.submitted_at ? new Date(selected.submitted_at).toLocaleDateString() : "—"}</span></div>
      <div className="mt-8"><ApplicationSummary sections={{ personal: section(selected.personal), business: section(selected.business), brand: section(selected.brand), registration: section(selected.registration) }} /></div>
      <section className="border-t pt-6"><h2 className="text-xl font-semibold">Applicant declarations</h2><p className="mt-3 text-sm text-muted-foreground">{[0, 1, 2, 3].every(i => section(selected.declaration)[String(i)] === "yes") ? "All four declarations confirmed." : "Declarations incomplete."}</p></section>
      <section className="mt-8 border-t pt-6"><Label htmlFor="review-comment">Feedback to applicant</Label><Textarea id="review-comment" className="mt-3" rows={4} value={comment} onChange={e => setComment(e.target.value)} /><div className="mt-5 flex flex-wrap gap-3"><Button disabled={saving} onClick={() => review("approved")}><Check className="size-4" /> Approve</Button><Button variant="outline" disabled={saving} onClick={() => review("needs_corrections")}><MessageSquare className="size-4" /> Request corrections</Button><Button variant="destructive" disabled={saving} onClick={() => review("rejected")}><X className="size-4" /> Reject</Button></div></section>
    </> : <>
      <div className="mt-8 grid gap-4 sm:grid-cols-[minmax(0,1fr)_240px]"><div className="relative self-end"><Search className="absolute left-3 top-3 size-4 text-muted-foreground" /><Input aria-label="Search applications" className="pl-10" placeholder="Search store, name or email" value={search} onChange={e => setSearch(e.target.value)} /></div><SelectField name="status-filter" label="Status" required value={filter} onChange={setFilter} options={["All", "Under review", "Needs corrections", "Approved", "Rejected"]} /></div>
      <p className="mt-6 text-sm text-muted-foreground">{visible.length} application{visible.length === 1 ? "" : "s"}</p>
      <ul className="mt-3 divide-y border-y">{visible.map(a => <li key={a.id} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-4 py-5"><div className="min-w-0"><h2 className="truncate text-lg font-semibold">{section(a.brand)["store_name"] || "Unnamed store"}</h2><p className="mt-1 truncate text-sm text-muted-foreground">{a.applicant_email}</p><p className="mt-2 text-xs font-medium text-accent">{STATUS_LABEL[a.status]}</p></div><Button variant="outline" onClick={() => { setSelectedId(a.id); setComment(a.admin_comment ?? ""); }}>Review</Button></li>)}</ul>
      {!visible.length && <p className="py-12 text-center text-muted-foreground">{search || filter !== "All" ? "No applications match your search." : "No submitted applications yet."}</p>}
    </>}
  </main></div>;
}
