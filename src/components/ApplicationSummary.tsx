import { Pencil } from "lucide-react";
import { Button } from "@/components/ui/button";
import { STEPS, summaryRows, type Section } from "@/lib/application";

export type ApplicationSections = { personal: Section; business: Section; brand: Section; registration: Section };
export function ApplicationSummary({ sections, onEdit }: { sections: ApplicationSections; onEdit?: (step: number) => void }) {
  return <div className="divide-y">{STEPS.slice(0, 4).map(s => <section key={s.n} className="py-6 first:pt-0">
    <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3"><h3 className="text-xl font-semibold">{s.title}</h3>{onEdit && <Button variant="ghost" size="sm" onClick={() => onEdit(s.n)}><Pencil className="size-3.5" /> Edit</Button>}</div>
    <dl className="mt-5 grid gap-x-6 gap-y-4 sm:grid-cols-2">{summaryRows(s.n as 1 | 2 | 3 | 4, sections[s.key as keyof ApplicationSections]).map(([label, value]) => <div key={label} className="min-w-0"><dt className="text-xs text-muted-foreground">{label}</dt><dd className="mt-1 whitespace-pre-wrap break-words text-sm">{value}</dd></div>)}</dl>
  </section>)}</div>;
}