import { useState } from "react";
import { FileCheck2, ExternalLink } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { VERIFICATION_STEPS, openVerificationFile } from "@/lib/verification";
import type { Section } from "@/lib/application";

export function VerificationSummary({ documents, details }: { documents: Section; details: Section }) {
  const [opening, setOpening] = useState<string | null>(null);
  return <div className="space-y-6">
    <div className="divide-y border-y">{VERIFICATION_STEPS.filter(step => step.file && documents[step.file]).map(step => <div key={step.file} className="flex flex-wrap items-center justify-between gap-3 py-4"><span className="flex items-center gap-3 font-medium"><FileCheck2 className="size-5 text-success" />{step.label}</span><Button variant="outline" size="sm" disabled={opening !== null} onClick={async () => { const path = documents[step.file]; if (!path) return; const tab = window.open("about:blank", "_blank"); if (tab) tab.opener = null; setOpening(step.file); try { const url = await openVerificationFile(path); if (tab) tab.location.href = url; else toast.error("Allow pop-ups to view your document."); } catch { tab?.close(); toast.error("Could not open the document."); } finally { setOpening(null); } }}><ExternalLink className="size-4" />{opening === step.file ? "Opening…" : "View document"}</Button></div>)}</div>
    <dl className="grid gap-5 text-sm sm:grid-cols-2">{[["Social accounts confirmed", details["social_verified"] === "yes" ? "Yes" : "No"], ["Address confirmed", details["address_confirmed"] === "yes" ? "Yes" : "No"], ["Business presence", details["business_presence"] || "—"], ["Declaration confirmed", details["declaration"] === "yes" ? "Yes" : "No"]].map(([label, value]) => <div key={label}><dt className="text-muted-foreground">{label}</dt><dd className="mt-1 whitespace-pre-wrap break-words font-medium">{value}</dd></div>)}</dl>
  </div>;
}