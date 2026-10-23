import { createFileRoute, Link } from "@tanstack/react-router";
import { useState } from "react";
import { useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { ArrowLeft, ArrowRight, CheckCircle2, Clock3, Save, Send, Upload } from "lucide-react";
import { toast } from "sonner";
import { SiteHeader } from "@/components/SiteHeader";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { TextField } from "@/components/FormFields";
import { VerificationSummary } from "@/components/VerificationSummary";
import { applicationQuery, section } from "@/lib/application-data";
import { VERIFICATION_STEPS, verificationQuery, verificationErrors, fileToBase64 } from "@/lib/verification";
import { STATUS_LABEL, SOCIALS, type Section } from "@/lib/application";
import { saveVerification, uploadVerificationDocument } from "@/lib/verification.functions";

export const Route = createFileRoute("/_authenticated/verification")({
  head: () => ({ meta: [{ title: "Seller verification — Verola" }, { name: "description", content: "Complete identity, business and address verification for your DDLX store." }, { property: "og:title", content: "Seller verification — Verola" }, { property: "og:description", content: "Your next step to becoming an approved DDLX seller." }, { property: "og:type", content: "website" }, { name: "twitter:card", content: "summary" }] }),
  loader: async ({ context }) => { await Promise.all([context.queryClient.ensureQueryData(applicationQuery(context.user.id)), context.queryClient.ensureQueryData(verificationQuery(context.user.id))]); },
  component: Verification,
});
function Verification() {
  const { user } = Route.useRouteContext();
  const queryClient = useQueryClient();
  const { data: application } = useSuspenseQuery(applicationQuery(user.id));
  const { data: verification } = useSuspenseQuery(verificationQuery(user.id));
  const [step, setStep] = useState(verification?.current_step ?? 1);
  const [details, setDetails] = useState<Section>(section(verification?.details ?? {}));
  const [documents, setDocuments] = useState<Section>(section(verification?.documents ?? {}));
  const [errors, setErrors] = useState<Section>({});
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const editable = !verification || ["draft", "needs_corrections"].includes(verification.status);
  const registered = section(application?.registration ?? {})["registered"] === "yes";
  const current = VERIFICATION_STEPS[step - 1];
  const go = (next: number) => { setStep(next); setErrors({}); window.scrollTo({ top: 0, behavior: "smooth" }); };
  const save = async (advance: boolean, submit = false) => {
    if (busy) return;
    for (const n of submit ? [1,2,3,4,5,6,7,8] : advance ? [step] : []) {
      const found = verificationErrors(n, details, documents, registered);
      if (Object.keys(found).length) { go(n); setErrors(found); return; }
    }
    setBusy(true);
    try {
      const next = advance ? Math.min(8, step + 1) : step;
      const result = await saveVerification({ data: { details, current_step: next, submit, expected_updated_at: verification?.updated_at ?? null } });
      if (!result.ok) { toast.error(result.error); return; }
      queryClient.setQueryData(verificationQuery(user.id).queryKey, result.verification);
      setDocuments(section(result.verification.documents));
      setSaved(true); if (advance) go(next);
      toast.success(submit ? "Verification submitted for review." : "Verification progress saved.");
    } catch { toast.error("Could not save verification. Refresh and try again."); }
    finally { setBusy(false); }
  };
  const upload = async (file: File | undefined) => {
    if (!file || busy || !current?.file) return;
    const allowed = current.file === "portrait" || current.file === "presence" ? ["image/jpeg", "image/png", "image/webp"] : ["image/jpeg", "image/png", "image/webp", "application/pdf"];
    if (!allowed.includes(file.type) || file.size > 10 * 1024 * 1024) { setErrors({ file: "Choose a JPG, PNG, WebP or permitted PDF up to 10 MB." }); return; }
    setBusy(true);
    try {
      const result = await uploadVerificationDocument({ data: { kind: current.file, content_type: file.type, data_base64: await fileToBase64(file), details, current_step: step, expected_updated_at: verification?.updated_at ?? null } });
      if (!result.ok) { setErrors({ file: result.error }); return; }
      setDocuments(section(result.verification.documents)); setErrors({}); setSaved(true);
      queryClient.setQueryData(verificationQuery(user.id).queryKey, result.verification);
      toast.success("Document uploaded and saved.");
    } catch { toast.error("Could not save the document. Please try again."); }
    finally { setBusy(false); }
  };
  const confirm = (key: string, text: string) => <label className="flex cursor-pointer items-start gap-3 text-base leading-7"><Checkbox disabled={busy} className="mt-1.5" checked={details[key] === "yes"} onCheckedChange={value => { setDetails(prev => ({ ...prev, [key]: value === true ? "yes" : "no" })); setErrors({}); }} />{text}</label>;
  return <div className="min-h-screen"><SiteHeader /><main className="mx-auto max-w-5xl px-4 py-12 sm:px-8"><p className="eyebrow text-accent">STAGE 2</p><div className="mt-3 flex flex-wrap items-center justify-between gap-4"><h1 className="text-3xl font-semibold sm:text-5xl">Seller verification</h1><span className="text-sm text-muted-foreground">{STATUS_LABEL[verification?.status ?? "draft"]}</span></div>
    {!application || application.status !== "approved" ? <div className="mt-10 border-y py-8"><h2 className="text-2xl font-semibold">Application approval comes first</h2><p className="mt-3 text-muted-foreground">Your verification opens once your application has been approved.</p><Button asChild className="mt-6"><Link to="/apply">My application <ArrowRight className="size-4" /></Link></Button></div> : <>
      {verification?.admin_comment && <div className="mt-8 border-l-2 border-accent bg-secondary p-5"><h2 className="font-semibold">Verification feedback</h2><p className="mt-2 whitespace-pre-wrap">{verification.admin_comment}</p></div>}
      {!editable ? <div className="mt-10"><div className="mb-8 flex gap-4 border-y py-7">{verification?.status === "approved" ? <CheckCircle2 className="size-7 text-success" /> : <Clock3 className="size-7 text-accent" />}<div><h2 className="text-2xl font-semibold">{verification?.status === "approved" ? "Verification complete" : verification?.status === "rejected" ? "Verification not approved" : "Verification under review"}</h2><p className="mt-2 text-muted-foreground">{verification?.status === "approved" ? "Your identity and business have been approved. Store management is the next phase." : "Your documents and review updates appear here."}</p></div></div><VerificationSummary documents={documents} details={details} /></div> : <>
        <ol aria-label="Verification progress" className="mt-10 grid grid-cols-2 gap-2 sm:grid-cols-4">{VERIFICATION_STEPS.map((item, index) => <li key={item.title}><Button variant="outline" disabled={busy} aria-current={step === index + 1 ? "step" : undefined} onClick={() => go(index + 1)} className="workflow-tab h-full min-h-20 w-full justify-start whitespace-normal text-left"><span className="font-display text-2xl">0{index + 1}</span><span>{item.title}</span></Button></li>)}</ol>
        <section className="mt-10"><p className="text-sm text-muted-foreground">Step {step} of 8</p><h2 className="mt-2 text-2xl font-semibold sm:text-3xl">{current?.title}</h2><div className="mt-7 space-y-6">
          {current?.file && (current.file !== "registration" || registered) && <div className="border-y py-7"><Label htmlFor="verification-file" className="text-base"><Upload className="size-5" />{current.label}</Label><p className="mt-3 text-sm text-muted-foreground">{current.file === "portrait" || current.file === "presence" ? "JPG, PNG or WebP" : "PDF, JPG, PNG or WebP"} · Maximum 10 MB</p><Input key={`${step}-${documents[current.file] ?? ""}`} id="verification-file" className="mt-4 h-auto py-3" type="file" disabled={busy} accept={current.file === "portrait" || current.file === "presence" ? "image/jpeg,image/png,image/webp" : "application/pdf,image/jpeg,image/png,image/webp"} onChange={event => { void upload(event.target.files?.[0]); }} />{documents[current.file] && <p className="mt-4 flex items-center gap-2 text-sm text-success"><CheckCircle2 className="size-4" />Document saved</p>}{busy && <p className="mt-3 text-sm">Saving…</p>}</div>}
          {step === 3 && !registered && <p className="border-y py-6 text-muted-foreground">You declared that your business is not registered. No registration certificate is required.</p>}
          {step === 4 && <><dl className="grid gap-4 border-y py-6 sm:grid-cols-2">{SOCIALS.filter(s => section(application.brand)[s.key]).map(s => <div key={s.key}><dt className="text-sm text-muted-foreground">{s.label}</dt><dd className="mt-1 break-all font-medium">{section(application.brand)[s.key]}</dd></div>)}</dl>{confirm("social_verified", "These social accounts are current and belong to my business.")}</>}
          {step === 5 && <><div className="border-y py-6"><h3 className="font-semibold">Business address</h3><p className="mt-3 whitespace-pre-wrap">{["address","city","state","country"].map(key => section(application.business)[key]).filter(Boolean).join(", ")}</p></div>{confirm("address_confirmed", "I confirm that this is my current business address.")}</>}
          {step === 7 && <TextField name="business_presence" label="Business presence" required multiline value={details["business_presence"] ?? ""} onChange={value => { setDetails(prev => ({ ...prev, business_presence: value })); setErrors({}); }} error={errors["presence"]} />}
          {step === 8 && <><VerificationSummary documents={documents} details={details} />{confirm("declaration", "I confirm that these documents are genuine, belong to me or my business, and may be used to verify my seller application.")}</>}
          {Object.entries(errors).filter(([key]) => key !== "presence").map(([key, error]) => <p key={key} role="alert" className="text-sm text-destructive">{error}</p>)}
        </div><div className="mt-10 grid gap-3 border-t pt-6 sm:grid-cols-[auto_minmax(0,1fr)_auto]"><Button variant="ghost" disabled={busy || step === 1} onClick={() => go(step - 1)}><ArrowLeft className="size-4" />Back</Button><Button variant="outline" disabled={busy} onClick={() => save(false)}><Save className="size-4" />Save progress</Button><Button disabled={busy} onClick={() => save(step < 8, step === 8)}>{step === 8 ? <Send className="size-4" /> : <ArrowRight className="size-4" />}{step === 8 ? "Submit verification" : "Save and continue"}</Button></div>{saved && <p className="mt-4 text-sm text-muted-foreground">Progress saved</p>}</section>
      </>}
    </>}
  </main></div>;
}