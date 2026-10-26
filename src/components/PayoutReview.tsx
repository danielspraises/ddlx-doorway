import { useState } from "react";
import { useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { decideWithdrawal } from "@/lib/payouts.functions";
import { WITHDRAWAL_LABEL, withdrawalReviewsQuery } from "@/lib/payouts";
import { formatPrice } from "@/lib/products";

// Money is sent from the company's bank (or Paystack dashboard) by hand; then the reference is recorded here.
export function PayoutReview() {
  const { data: items } = useSuspenseQuery(withdrawalReviewsQuery);
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [open, setOpen] = useState<string | null>(null);
  const [text, setText] = useState("");

  const decide = async (id: string, decision: "paid" | "rejected") => {
    if (busy) return;
    setBusy(true);
    try {
      const result = await decideWithdrawal({ data: { id, decision, ...(decision === "paid" ? { reference: text } : { note: text }) } });
      if (!result.ok) { toast.error(result.error); return; }
      await queryClient.invalidateQueries({ queryKey: ["payouts"] });
      void queryClient.invalidateQueries({ queryKey: ["me"] });
      toast.success(decision === "paid" ? "Marked as paid." : "Withdrawal rejected and refunded to the wallet.");
      setOpen(null); setText("");
    } catch { toast.error("Something went wrong. Please try again."); }
    finally { setBusy(false); }
  };

  return <ul className="mt-8 divide-y border-y">
    {items.map((w) => <li key={w.id} className="py-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0"><p className="font-semibold">{w.store_name} <span className="font-normal text-muted-foreground">· {w.seller_email}</span></p>
          <p className="mt-1">Pay <strong>{formatPrice(w.amount - w.fee, w.currency)}</strong>{w.fee > 0 ? ` (${formatPrice(w.amount, w.currency)} less ${formatPrice(w.fee, w.currency)} fee)` : ""}</p>
          <p className="text-sm text-muted-foreground">{w.bank_name} · {w.account_number} · {w.account_name}</p>
          <p className="text-xs text-muted-foreground">Requested {new Date(w.requested_at).toLocaleString()}{w.reference ? ` · Ref ${w.reference}` : ""}{w.admin_note ? ` · ${w.admin_note}` : ""}</p></div>
        {w.status === "requested" ? <div className="flex gap-2"><Button size="sm" onClick={() => { setOpen(`paid:${w.id}`); setText(""); }}>Mark paid</Button><Button size="sm" variant="outline" onClick={() => { setOpen(`rejected:${w.id}`); setText(""); }}>Reject</Button></div>
          : <Badge variant={w.status === "paid" ? "default" : "outline"}>{WITHDRAWAL_LABEL[w.status]}</Badge>}
      </div>
      {(open === `paid:${w.id}` || open === `rejected:${w.id}`) && <form className="mt-3 flex gap-2" onSubmit={(e) => { e.preventDefault(); void decide(w.id, open?.startsWith("paid") ? "paid" : "rejected"); }}>
        <Input autoFocus value={text} onChange={(e) => setText(e.target.value)} maxLength={open.startsWith("paid") ? 120 : 500} placeholder={open.startsWith("paid") ? "Transfer reference" : "Reason shown to the seller"} aria-label={open.startsWith("paid") ? "Transfer reference" : "Reason"} />
        <Button type="submit" disabled={busy || !text.trim()}>Confirm</Button><Button type="button" variant="ghost" onClick={() => setOpen(null)}>Cancel</Button>
      </form>}
    </li>)}
    {!items.length && <li className="py-12 text-center text-muted-foreground">No withdrawal requests yet.</li>}
  </ul>;
}
