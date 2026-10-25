import { useState } from "react";
import { useSuspenseQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Check, MessageSquare, Search, X } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { VerificationSummary } from "@/components/VerificationSummary";
import { verificationReviewsQuery, section } from "@/lib/verification";
import { STATUS_LABEL } from "@/lib/application";
import { reviewVerification } from "@/lib/verification.functions";

export function VerificationReview() {
  const { data: items } = useSuspenseQuery(verificationReviewsQuery);
  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [comment, setComment] = useState("");
  const [saving, setSaving] = useState(false);
  const selected = items.find(v => v.id === selectedId);
  const name = (v: (typeof items)[number]) => section(v.seller_applications?.brand ?? {})["store_name"] || "Unnamed store";
  const visible = items.filter(v => `${name(v)} ${v.seller_applications?.applicant_email ?? ""}`.toLowerCase().includes(search.toLowerCase()));

  const decide = async (status: "approved" | "rejected" | "needs_corrections") => {
    if (!selected || saving) return;
    if (status !== "approved" && !comment.trim()) { toast.error("Add feedback for the seller first."); return; }
    if (!window.confirm(`${STATUS_LABEL[status]} — confirm this verification decision?`)) return;
    setSaving(true);
    try {
      const result = await reviewVerification({ data: { id: selected.id, status, comment: comment.trim(), expected_updated_at: selected.updated_at } });
      if (!result.ok) { toast.error(result.error); return; }
      await queryClient.invalidateQueries({ queryKey: verificationReviewsQuery.queryKey });
      await queryClient.invalidateQueries({ queryKey: ["verification"] });
      await queryClient.invalidateQueries({ queryKey: ["me"] });
      toast.success(status === "approved" ? "Verified. The seller can now open Seller Center." : status === "needs_corrections" ? "Sent back to the seller for corrections." : "Verification rejected.");
      setSelectedId(null);
    } catch { toast.error("Could not save the decision. Refresh and try again."); }
    finally { setSaving(false); }
  };

  if (selected) return <div className="mt-6">
    <Button variant="ghost" onClick={() => setSelectedId(null)}><ArrowLeft className="size-4" /> All verifications</Button>
    <h2 className="mt-4 text-2xl font-semibold sm:text-3xl">{name(selected)}</h2>
    <div className="mt-4 flex flex-wrap gap-x-6 gap-y-2 border-y py-5 text-sm"><span className="font-semibold">{STATUS_LABEL[selected.status]}</span><span className="text-muted-foreground">{selected.seller_applications?.applicant_email}</span><span className="text-muted-foreground">Submitted {selected.submitted_at ? new Date(selected.submitted_at).toLocaleDateString() : "—"}</span></div>
    <div className="mt-8"><VerificationSummary documents={section(selected.documents)} details={section(selected.details)} /></div>
    {selected.status !== "submitted" ? <section className="mt-8 border-t pt-6"><h3 className="font-semibold">Decision</h3><p className="mt-2 text-sm text-muted-foreground">{STATUS_LABEL[selected.status]}{selected.reviewed_at ? ` on ${new Date(selected.reviewed_at).toLocaleDateString()}` : ""}. This item is no longer waiting for review.</p>{selected.admin_comment && <p className="mt-3 whitespace-pre-wrap">{selected.admin_comment}</p>}</section> : <section className="mt-8 border-t pt-6"><Label htmlFor="verification-comment">Feedback to seller</Label><Textarea id="verification-comment" className="mt-3" rows={4} value={comment} onChange={e => setComment(e.target.value)} />
      <div className="mt-5 flex flex-wrap gap-3"><Button disabled={saving} onClick={() => decide("approved")}><Check className="size-4" /> Approve verification</Button><Button variant="outline" disabled={saving} onClick={() => decide("needs_corrections")}><MessageSquare className="size-4" /> Request corrections</Button><Button variant="destructive" disabled={saving} onClick={() => decide("rejected")}><X className="size-4" /> Reject</Button></div></section>}
  </div>;

  return <div className="mt-6">
    <div className="relative"><Search className="absolute left-3 top-3 size-4 text-muted-foreground" /><Input aria-label="Search verifications" className="pl-10" placeholder="Search store or email" value={search} onChange={e => setSearch(e.target.value)} /></div>
    <p className="mt-6 text-sm text-muted-foreground">{visible.length} verification{visible.length === 1 ? "" : "s"}</p>
    <ul className="mt-3 divide-y border-y">{visible.map(v => <li key={v.id} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-4 py-5"><div className="min-w-0"><h3 className="truncate text-lg font-semibold">{name(v)}</h3><p className="mt-1 truncate text-sm text-muted-foreground">{v.seller_applications?.applicant_email}</p><p className="mt-2 text-xs font-medium text-accent">{STATUS_LABEL[v.status]}</p></div><Button variant="outline" onClick={() => { setSelectedId(v.id); setComment(v.admin_comment ?? ""); }}>Review</Button></li>)}</ul>
    {!visible.length && <p className="py-12 text-center text-muted-foreground">No submitted verifications yet.</p>}
  </div>;
}
