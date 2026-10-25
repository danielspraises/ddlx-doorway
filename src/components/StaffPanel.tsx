import { useState } from "react";
import { useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { Eye, EyeOff } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { staffQuery } from "@/lib/product-reviews";
import { createStaff, setStaffPassword, updateStaff } from "@/lib/staff.functions";

const PERMISSION_LABEL: Record<string, string> = { products: "Review and publish products" };

export function StaffPanel() {
  const { data: staff } = useSuspenseQuery(staffQuery);
  const queryClient = useQueryClient();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [resetId, setResetId] = useState<string | null>(null);
  const [newPassword, setNewPassword] = useState("");
  const refresh = () => queryClient.invalidateQueries({ queryKey: staffQuery.queryKey });

  const run = async (work: () => Promise<void>) => {
    if (busy) return;
    setBusy(true);
    try { await work(); } catch { toast.error("Something went wrong. Please try again."); } finally { setBusy(false); }
  };

  const create = () => run(async () => {
    const result = await createStaff({ data: { email, password, permissions: ["products"] } });
    if (!result.ok) { toast.error(result.error); return; }
    toast.success("Staff account created. Share the email and password with them securely.");
    setEmail(""); setPassword("");
    await refresh();
  });
  const toggle = (userId: string, active: boolean, permissions: string[]) => run(async () => {
    const result = await updateStaff({ data: { user_id: userId, active, permissions: permissions as ["products"] } });
    if (!result.ok) { toast.error(result.error); return; }
    toast.success(active ? "Account turned on." : "Account turned off. They can no longer review products.");
    await refresh();
  });
  const reset = (userId: string) => run(async () => {
    const result = await setStaffPassword({ data: { user_id: userId, password: newPassword } });
    if (!result.ok) { toast.error(result.error); return; }
    toast.success("Password changed. They were signed out.");
    setResetId(null); setNewPassword("");
  });

  return (
    <div className="mt-8 space-y-10">
      <section className="rounded-md border p-5">
        <h2 className="text-lg font-semibold">Add a staff member</h2>
        <p className="mt-1 text-sm text-muted-foreground">Staff can review and publish seller products. They cannot see applications, verification documents or other admin pages.</p>
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          <div className="space-y-1.5"><Label htmlFor="staff-email">Email</Label><Input id="staff-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="off" /></div>
          <div className="space-y-1.5"><Label htmlFor="staff-password">Temporary password</Label>
            <div className="relative"><Input id="staff-password" type={show ? "text" : "password"} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" className="pr-10" />
              <button type="button" className="absolute right-3 top-2.5 text-muted-foreground" aria-label={show ? "Hide password" : "Show password"} onClick={() => setShow((s) => !s)}>{show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}</button></div>
            <p className="text-xs text-muted-foreground">At least 8 characters.</p></div>
        </div>
        <label className="mt-4 flex items-center gap-3 text-sm"><Checkbox checked disabled />Review and publish products</label>
        <Button className="mt-5" disabled={busy || !email.trim() || password.length < 8} onClick={() => void create()}>Create account</Button>
      </section>

      <section>
        <h2 className="text-lg font-semibold">Staff accounts</h2>
        <ul className="mt-3 divide-y border-y">
          {staff.map((m) => (
            <li key={m.user_id} className="py-4">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0"><p className="truncate font-medium">{m.email}</p><p className="mt-1 text-sm text-muted-foreground">{m.permissions.map((p) => PERMISSION_LABEL[p] ?? p).join(", ")}</p></div>
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={m.active ? "default" : "outline"}>{m.active ? "Active" : "Turned off"}</Badge>
                  <Button size="sm" variant="outline" disabled={busy} onClick={() => void toggle(m.user_id, !m.active, m.permissions)}>{m.active ? "Turn off" : "Turn on"}</Button>
                  <Button size="sm" variant="outline" disabled={busy} onClick={() => { setResetId(resetId === m.user_id ? null : m.user_id); setNewPassword(""); }}>Reset password</Button>
                </div>
              </div>
              {resetId === m.user_id && (
                <div className="mt-3 flex flex-wrap items-end gap-3">
                  <div className="space-y-1.5"><Label htmlFor={`new-password-${m.user_id}`}>New password</Label><Input id={`new-password-${m.user_id}`} type="text" className="w-64" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} autoComplete="off" /></div>
                  <Button disabled={busy || newPassword.length < 8} onClick={() => void reset(m.user_id)}>Save password</Button>
                </div>
              )}
            </li>
          ))}
        </ul>
        {!staff.length && <p className="py-10 text-center text-muted-foreground">No staff accounts yet.</p>}
      </section>
    </div>
  );
}
