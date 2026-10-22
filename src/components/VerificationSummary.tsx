import { useState } from "react";
import { FileCheck2, Eye } from "lucide-react";
import { Button } from "@/components/ui/button";
import { DocumentPreviewDialog } from "@/components/DocumentPreviewDialog";
import { VERIFICATION_STEPS } from "@/lib/verification";
import type { Section } from "@/lib/application";

export function VerificationSummary({ documents, details }: { documents: Section; details: Section }) {
  const [previewKey, setPreviewKey] = useState<string | null>(null);
  const preview = VERIFICATION_STEPS.find(step => step.file && step.file === previewKey);
  return <div className="space-y-6">
    <div className="divide-y border-y">{VERIFICATION_STEPS.filter(step => step.file && documents[step.file]).map(step => <div key={step.file} className="flex flex-wrap items-center justify-between gap-3 py-4"><span className="flex items-center gap-3 font-medium"><FileCheck2 className="size-5 text-success" />{step.label}</span><Button variant="outline" size="sm" onClick={() => setPreviewKey(step.file)}><Eye className="size-4" />View document</Button></div>)}</div>
    <DocumentPreviewDialog open={previewKey !== null} onOpenChange={open => { if (!open) setPreviewKey(null); }} path={preview ? documents[preview.file] ?? null : null} title={preview?.label ?? "Document"} />
    <dl className="grid gap-5 text-sm sm:grid-cols-2">{[["Social accounts confirmed", details["social_verified"] === "yes" ? "Yes" : "No"], ["Address confirmed", details["address_confirmed"] === "yes" ? "Yes" : "No"], ["Business presence", details["business_presence"] || "—"], ["Declaration confirmed", details["declaration"] === "yes" ? "Yes" : "No"]].map(([label, value]) => <div key={label}><dt className="text-muted-foreground">{label}</dt><dd className="mt-1 whitespace-pre-wrap break-words font-medium">{value}</dd></div>)}</dl>
  </div>;
}
