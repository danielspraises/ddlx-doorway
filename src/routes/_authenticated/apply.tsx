import { createFileRoute, Link } from "@tanstack/react-router";
import { SiteHeader } from "@/components/SiteHeader";
import { useState } from "react";
import { useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { ArrowLeft, ArrowRight, Check, CheckCircle2, Clock3, Save, Send } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { ApplicationSection } from "@/components/ApplicationSection";
import { ApplicationSummary, type ApplicationSections } from "@/components/ApplicationSummary";
import { applicationQuery, section } from "@/lib/application-data";
import { STEPS, STATUS_LABEL, validate, type Section } from "@/lib/application";
import { saveApplication } from "@/lib/applications.functions";

const DECLARATIONS = ["I confirm that the information provided is accurate.", "I agree to the Seller Terms.", "I agree to the Privacy Policy.", "I agree to the Seller and Marketplace policies."];

export const Route = createFileRoute("/_authenticated/apply")({
  head: () => ({ meta: [{ title: "My application — Vendor Hub" }, { name: "description", content: "My application on Vendor Hub." }, { property: "og:title", content: "My application — DDLX" }, { property: "og:description", content: "My application on Vendor Hub." }, { property: "og:type", content: "website" }, { name: "twitter:card", content: "summary" }] }),
  loader: ({ context }) => context.queryClient.ensureQueryData(applicationQuery(context.user.id)),
  component: Apply,
});

function Apply() {
  const { user } = Route.useRouteContext();
  const queryClient = useQueryClient();
  const { data: application } = useSuspenseQuery(applicationQuery(user.id));
  const [step, setStep] = useState(Math.min(5, Math.max(1, application?.current_step ?? 1)));
  const [sections, setSections] = useState<ApplicationSections>(() => ({
    personal: { country: "Nigeria", email: user.email ?? "", ...section(application?.personal ?? {}) },
    business: { country: "Nigeria", ...section(application?.business ?? {}) },
    brand: section(application?.brand ?? {}), registration: section(application?.registration ?? {}),
  }));
  const [declarations, setDeclarations] = useState<Section>(section(application?.declaration ?? {}));
  const [errors, setErrors] = useState<Section>({});
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState<string | null>(null);
  const editable = !application || ["draft", "needs_corrections"].includes(application.status);
  const current = STEPS[step - 1];
  if (!current) return null;
  const go = (next: number) => { setStep(next); setErrors({}); window.scrollTo({ top: 0, behavior: "smooth" }); };
  const save = async (advance: boolean, submit = false) => {
    if (saving) return;
    if (submit) {
      for (const s of STEPS.slice(0, 4)) {
        const errs = validate(s.n as 1 | 2 | 3 | 4, sections[s.key as keyof ApplicationSections]);
        if (Object.keys(errs).length) { go(s.n); setErrors(errs); toast.error("Please complete the highlighted fields."); return; }
      }
      if (DECLARATIONS.some((_, index) => declarations[String(index)] !== "yes")) { setErrors({ declaration: "Please confirm all four declarations." }); return; }
    } else if (advance && step < 5) {
      const errs = validate(step as 1 | 2 | 3 | 4, sections[current.key as keyof ApplicationSections]);
      if (Object.keys(errs).length) { setErrors(errs); toast.error("Please check the highlighted fields."); return; }
    }
    setSaving(true);
    try {
      const next = advance ? Math.min(step + 1, 5) : step;
      const result = await saveApplication({ data: { sections, declaration: declarations, current_step: next, submit } });
      if (!result.ok) {
        if (result.code === "email_taken") { go(1); setErrors({ email: result.error }); return; }
        throw new Error(result.error);
      }
      queryClient.setQueryData(applicationQuery(user.id).queryKey, result.application);
      if (submit) { void queryClient.invalidateQueries({ queryKey: ["me"] }); window.scrollTo({ top: 0, behavior: "smooth" }); }
      setSaved(new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }));
      setErrors({});
      if (advance) go(next);
      toast.success(submit ? "Application submitted for review." : "Your progress is saved.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not save your application. Please try again.");
    } finally { setSaving(false); }
  };
  return <div className="min-h-screen"><SiteHeader /><main className="mx-auto max-w-6xl px-4 py-10 sm:px-8 sm:py-14">
    <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-4"><div className="min-w-0"><p className="eyebrow text-accent">BECOME A DDLX SELLER</p><h1 className="mt-3 text-3xl font-semibold sm:text-5xl">My application</h1></div><span className="mt-2 flex shrink-0 items-center gap-2 text-xs font-medium text-muted-foreground"><span className="h-2 w-2 rounded-full bg-accent" />{STATUS_LABEL[application?.status ?? "draft"]}</span></div>
    {application?.admin_comment && <div className="mt-8 border-l-2 border-accent bg-secondary px-5 py-4"><h2 className="font-semibold">Review feedback</h2><p className="mt-2 whitespace-pre-wrap text-sm">{application.admin_comment}</p></div>}
    {!editable ? <div className="mt-12"><div className="flex items-start gap-4 border-y py-8">{application?.status === "approved" ? <CheckCircle2 className="size-8 shrink-0 text-success" /> : <Clock3 className="size-8 shrink-0 text-accent" />}<div><h2 className="text-2xl font-semibold">{application?.status === "approved" ? "Your application is approved" : application?.status === "rejected" ? "Application not approved" : "Your application is under review"}</h2><p className="mt-2 text-muted-foreground">{application?.status === "approved" ? "Stage 1 is complete. Verification is the next stage before your store opens." : application?.status === "rejected" ? "Please see the review feedback above." : "Your details have been saved. Any updates or correction requests will appear here."}</p><p className="mt-4 break-all text-xs text-muted-foreground">Application reference: {application?.id}</p>{application?.status === "approved" && <Button asChild className="mt-6"><Link to="/verification">Continue to verification <ArrowRight className="size-4" /></Link></Button>}</div></div><div className="mt-8"><ApplicationSummary sections={sections} /></div></div> : <>
      <ol className="mt-10 grid grid-cols-2 gap-2 sm:grid-cols-5 sm:gap-3" aria-label="Application progress">{STEPS.map(s => <li key={s.n} className="min-w-0"><Button variant="ghost" disabled={saving} aria-label={s.title} onClick={() => go(s.n)} aria-current={step === s.n ? "step" : undefined} className="workflow-tab h-full w-full flex-col items-start justify-start gap-3 whitespace-normal px-4 py-4 text-left"><span className="font-display text-2xl">0{s.n}</span><span className="text-sm">{s.title}</span></Button></li>)}</ol>
      <div className="mt-6 grid gap-8 lg:grid-cols-[minmax(0,1fr)_240px] lg:gap-14">
        <section className="min-w-0"><div className="mb-8"><p className="text-sm text-muted-foreground">Step {step} of 5</p><h2 className="mt-2 text-2xl font-semibold sm:text-3xl">{current.title}</h2></div>
          {step < 5 ? <ApplicationSection key={step} step={step} data={sections[current.key as keyof ApplicationSections]} errors={errors} onChange={(name, value) => { setSections(prev => ({ ...prev, [current.key]: { ...prev[current.key as keyof ApplicationSections], [name]: value } })); setErrors(prev => ({ ...prev, [name]: "" })); }} /> : <><ApplicationSummary sections={sections} onEdit={go} /><div className="space-y-4 border-t pt-6">{DECLARATIONS.map((text, index) => <label key={text} className="flex cursor-pointer items-start gap-3 text-sm leading-6"><Checkbox className="mt-1" checked={declarations[String(index)] === "yes"} onCheckedChange={checked => setDeclarations(prev => ({ ...prev, [String(index)]: checked === true ? "yes" : "no" }))} />{text}</label>)}{errors["declaration"] && <p role="alert" className="text-sm text-destructive">{errors["declaration"]}</p>}</div></>}
          <div className="mt-10 grid gap-3 border-t pt-6 sm:grid-cols-[auto_minmax(0,1fr)_auto]">
            <Button variant="ghost" disabled={step === 1 || saving} onClick={() => go(step - 1)}><ArrowLeft className="size-4" /> Back</Button>
            <Button variant="outline" disabled={saving} onClick={() => save(false)}><Save className="size-4" />{saving ? "Saving…" : "Save progress"}</Button>
            <Button disabled={saving} onClick={() => save(step < 5, step === 5)}>{step === 5 ? <Send className="size-4" /> : <ArrowRight className="size-4" />}{step === 5 ? "Submit application" : "Save and continue"}</Button>
          </div>
        </section>
        <aside className="border-t pt-6 lg:border-t-0 lg:border-l lg:pl-6 lg:pt-0"><h3 className="text-lg font-semibold">Your next chapter</h3><p className="mt-3 text-sm leading-6 text-muted-foreground">Application → Review → Verification → Seller Center</p><div className="mt-6 flex items-center gap-2 text-sm text-muted-foreground"><Check className="size-4 text-success" />{saved ? `Saved at ${saved}` : application ? "Saved application loaded" : "New application"}</div></aside>
      </div>
    </>}
  </main></div>;
}
