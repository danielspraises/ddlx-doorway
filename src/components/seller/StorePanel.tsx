import { useEffect, useState } from "react";
import { useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { base64ToBlob, fileToBase64 } from "@/lib/files";
import { storeQuery } from "@/lib/store";
import { getStoreImage, removeStoreImage, saveStore, uploadStoreImage } from "@/lib/store.functions";

function StoreImage({ kind, path, onChange }: { kind: "logo" | "banner"; path: string | null; onChange: (file: File | null) => void }) {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    if (!path) { setUrl(null); return; }
    let cancelled = false;
    let made: string | null = null;
    getStoreImage({ data: { kind } }).then((r) => {
      if (cancelled || !r.ok) return;
      made = URL.createObjectURL(base64ToBlob(r.data_base64, r.content_type));
      setUrl(made);
    }).catch(() => undefined);
    return () => { cancelled = true; if (made) URL.revokeObjectURL(made); };
  }, [path, kind]);
  return <div>
    <Label htmlFor={`store-${kind}`}>{kind === "logo" ? "Logo" : "Banner"}</Label>
    <div className={`mt-2 overflow-hidden rounded-md border bg-muted/30 ${kind === "logo" ? "size-28" : "h-32 w-full max-w-xl"}`}>
      {url ? <img src={url} alt={kind} className="h-full w-full object-cover" /> : <p className="grid h-full place-items-center text-xs text-muted-foreground">None yet</p>}
    </div>
    <div className="mt-2 flex items-center gap-2">
      <Input id={`store-${kind}`} type="file" accept="image/jpeg,image/png,image/webp" className="max-w-xs" onChange={(e) => { onChange(e.target.files?.[0] ?? null); e.target.value = ""; }} />
      {path && <Button type="button" variant="ghost" size="sm" onClick={() => onChange(null)}>Remove</Button>}
    </div>
  </div>;
}

export function StorePanel() {
  const { data } = useSuspenseQuery(storeQuery);
  const queryClient = useQueryClient();
  const [form, setForm] = useState(data);
  const [busy, setBusy] = useState(false);
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((prev) => ({ ...prev, [key]: value }));

  const image = async (kind: "logo" | "banner", file: File | null) => {
    if (busy) return;
    setBusy(true);
    try {
      const result = file
        ? await uploadStoreImage({ data: { kind, content_type: file.type, data_base64: await fileToBase64(file) } })
        : await removeStoreImage({ data: { kind } });
      if (!result.ok) { toast.error(result.error); return; }
      queryClient.setQueryData(storeQuery.queryKey, result.store);
      setForm((prev) => ({ ...prev, logo: result.store.logo, banner: result.store.banner }));
      toast.success(file ? "Image saved." : "Image removed.");
    } catch { toast.error("Could not save the image. Please try again."); }
    finally { setBusy(false); }
  };

  const save = async () => {
    if (busy) return;
    setBusy(true);
    try {
      const { logo: _logo, banner: _banner, ...fields } = form;
      const result = await saveStore({ data: fields });
      if (!result.ok) { toast.error(result.error); return; }
      queryClient.setQueryData(storeQuery.queryKey, result.store);
      toast.success("Store saved.");
    } catch { toast.error("Could not save your store. Please try again."); }
    finally { setBusy(false); }
  };

  return <div className="mt-8 max-w-2xl space-y-6">
    <div className="flex flex-wrap gap-8"><StoreImage kind="logo" path={data.logo} onChange={(f) => void image("logo", f)} /><StoreImage kind="banner" path={data.banner} onChange={(f) => void image("banner", f)} /></div>
    <div><Label htmlFor="st-tagline">Tagline</Label><Input id="st-tagline" maxLength={160} value={form.tagline} onChange={(e) => set("tagline", e.target.value)} /></div>
    <div><Label htmlFor="st-desc">About your store</Label><Textarea id="st-desc" rows={5} maxLength={3000} value={form.description} onChange={(e) => set("description", e.target.value)} /></div>
    <div className="grid gap-4 sm:grid-cols-2">
      <div><Label htmlFor="st-phone">Store phone</Label><Input id="st-phone" maxLength={30} value={form.phone} onChange={(e) => set("phone", e.target.value)} /></div>
      <div><Label htmlFor="st-email">Support email</Label><Input id="st-email" type="email" maxLength={255} value={form.support_email} onChange={(e) => set("support_email", e.target.value)} /></div>
    </div>
    <div><Label htmlFor="st-ship">Shipping policy</Label><Textarea id="st-ship" rows={4} maxLength={3000} value={form.shipping_policy} onChange={(e) => set("shipping_policy", e.target.value)} /></div>
    <div><Label htmlFor="st-return">Return policy</Label><Textarea id="st-return" rows={4} maxLength={3000} value={form.return_policy} onChange={(e) => set("return_policy", e.target.value)} /></div>
    <div className="rounded-md border p-4">
      <label className="flex cursor-pointer items-start gap-3"><Checkbox className="mt-1" checked={form.vacation} onCheckedChange={(v) => set("vacation", v === true)} /><span><span className="font-medium">Vacation mode</span><span className="block text-sm text-muted-foreground">Hides all your products from the marketplace until you switch it off. Pending orders still need fulfilling.</span></span></label>
      {form.vacation && <div className="mt-3"><Label htmlFor="st-vac">Message to customers</Label><Input id="st-vac" maxLength={300} value={form.vacation_message} onChange={(e) => set("vacation_message", e.target.value)} /></div>}
    </div>
    <Button disabled={busy} onClick={() => void save()}>Save store</Button>
  </div>;
}
