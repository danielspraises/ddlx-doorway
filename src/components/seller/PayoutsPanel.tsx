import { useState } from "react";
import { useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { addBankAccount, cancelWithdrawal, removeBankAccount, requestWithdrawal } from "@/lib/payouts.functions";
import { WITHDRAWAL_LABEL, payoutsQuery } from "@/lib/payouts";
import { formatPrice } from "@/lib/products";
import type { Currency } from "@/lib/types";

const select = "h-10 w-full rounded-md border border-input bg-background px-3 text-sm";
const when = (iso: string) => new Date(iso).toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });

export function PayoutsPanel() {
  const { data } = useSuspenseQuery(payoutsQuery);
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [bank, setBank] = useState({ bank_name: "", account_number: "", account_name: "" });
  const [adding, setAdding] = useState(false);
  const [accountId, setAccountId] = useState("");
  const [currency, setCurrency] = useState<Currency>(data.available[0]?.currency ?? "NGN");
  const [amount, setAmount] = useState("");
  const available = data.available.find((a) => a.currency === currency)?.amount ?? 0;
  const chosen = accountId || data.accounts[0]?.id || "";

  const run = async (job: () => Promise<{ ok: true } | { ok: false; error: string }>, done: string, after?: () => void) => {
    if (busy) return;
    setBusy(true);
    try {
      const result = await job();
      if (!result.ok) { toast.error(result.error); return; }
      await queryClient.invalidateQueries({ queryKey: ["payouts"] });
      void queryClient.invalidateQueries({ queryKey: ["sales"] });
      toast.success(done);
      after?.();
    } catch { toast.error("Something went wrong. Please try again."); }
    finally { setBusy(false); }
  };

  return <div className="mt-8 space-y-10">
    <section>
      <h2 className="text-xl font-semibold">Withdraw money</h2>
      {data.accounts.length === 0 ? <p className="mt-3 text-muted-foreground">Add a bank account below, then request a withdrawal.</p> :
        <form className="mt-4 grid max-w-xl gap-4" onSubmit={(e) => {
          e.preventDefault();
          const value = Number(amount);
          if (!Number.isFinite(value) || value <= 0) { toast.error("Enter an amount."); return; }
          void run(() => requestWithdrawal({ data: { bank_account_id: chosen, currency, amount: value } }), "Withdrawal requested.", () => setAmount(""));
        }}>
          <p className="text-sm text-muted-foreground">Available now: <strong className="text-foreground">{formatPrice(available, currency)}</strong>. Smallest withdrawal {formatPrice(data.min_withdrawal, currency)}{data.fee > 0 ? `, fee ${formatPrice(data.fee, currency)} per withdrawal` : ""}.</p>
          <div className="grid gap-4 sm:grid-cols-2">
            {data.available.length > 1 && <div><Label htmlFor="wd-currency">Currency</Label><select id="wd-currency" className={select} value={currency} onChange={(e) => setCurrency(e.target.value as Currency)}>{data.available.map((a) => <option key={a.currency} value={a.currency}>{a.currency}</option>)}</select></div>}
            <div><Label htmlFor="wd-amount">Amount</Label><Input id="wd-amount" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="0.00" /></div>
          </div>
          <div><Label htmlFor="wd-bank">Pay to</Label><select id="wd-bank" className={select} value={chosen} onChange={(e) => setAccountId(e.target.value)}>{data.accounts.map((a) => <option key={a.id} value={a.id}>{a.bank_name} · {a.account_number} · {a.account_name}</option>)}</select></div>
          <div className="flex gap-2"><Button type="submit" disabled={busy}>Request withdrawal</Button><Button type="button" variant="outline" onClick={() => setAmount(String(available))}>Withdraw all</Button></div>
        </form>}
    </section>

    <section>
      <div className="flex items-center justify-between"><h2 className="text-xl font-semibold">Bank accounts</h2>{!adding && data.accounts.length < 3 && <Button variant="outline" size="sm" onClick={() => setAdding(true)}>Add account</Button>}</div>
      <ul className="mt-4 divide-y border-y">
        {data.accounts.map((a) => <li key={a.id} className="flex flex-wrap items-center justify-between gap-3 py-3"><div><p className="font-medium">{a.bank_name} · {a.account_number}</p><p className="text-sm text-muted-foreground">{a.account_name}</p></div><Button variant="ghost" size="sm" disabled={busy} onClick={() => { if (window.confirm("Remove this bank account?")) void run(() => removeBankAccount({ data: { id: a.id } }), "Account removed."); }}>Remove</Button></li>)}
        {!data.accounts.length && <li className="py-6 text-center text-muted-foreground">No bank accounts yet.</li>}
      </ul>
      {adding && <form className="mt-4 grid max-w-xl gap-4" onSubmit={(e) => { e.preventDefault(); void run(() => addBankAccount({ data: bank }), "Bank account saved.", () => { setBank({ bank_name: "", account_number: "", account_name: "" }); setAdding(false); }); }}>
        <div><Label htmlFor="bk-name">Bank</Label><Input id="bk-name" value={bank.bank_name} onChange={(e) => setBank({ ...bank, bank_name: e.target.value })} placeholder="e.g. GTBank" /></div>
        <div><Label htmlFor="bk-number">Account number</Label><Input id="bk-number" inputMode="numeric" maxLength={10} value={bank.account_number} onChange={(e) => setBank({ ...bank, account_number: e.target.value.replace(/\D/g, "") })} /></div>
        <div><Label htmlFor="bk-holder">Account name</Label><Input id="bk-holder" value={bank.account_name} onChange={(e) => setBank({ ...bank, account_name: e.target.value })} /></div>
        <div className="flex gap-2"><Button type="submit" disabled={busy}>Save account</Button><Button type="button" variant="ghost" onClick={() => setAdding(false)}>Cancel</Button></div>
      </form>}
    </section>

    <section>
      <h2 className="text-xl font-semibold">Withdrawal history</h2>
      <ul className="mt-4 divide-y border-y">
        {data.withdrawals.map((w) => <li key={w.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
          <div><p className="font-medium">{formatPrice(w.amount, w.currency)}{w.fee > 0 ? <span className="text-sm font-normal text-muted-foreground"> (you receive {formatPrice(w.amount - w.fee, w.currency)})</span> : null}</p>
            <p className="text-sm text-muted-foreground">{when(w.requested_at)} · {w.bank_name} {w.account_number}{w.reference ? ` · Ref ${w.reference}` : ""}</p>
            {w.admin_note && <p className="mt-1 text-sm">{w.admin_note}</p>}</div>
          <div className="flex items-center gap-2"><Badge variant={w.status === "paid" ? "default" : "outline"}>{WITHDRAWAL_LABEL[w.status]}</Badge>
            {w.status === "requested" && <Button variant="ghost" size="sm" disabled={busy} onClick={() => { if (window.confirm("Cancel this withdrawal?")) void run(() => cancelWithdrawal({ data: { id: w.id } }), "Withdrawal cancelled."); }}>Cancel</Button>}</div>
        </li>)}
        {!data.withdrawals.length && <li className="py-6 text-center text-muted-foreground">No withdrawals yet.</li>}
      </ul>
    </section>
  </div>;
}
