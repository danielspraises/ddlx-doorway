import { useState } from "react";
import { Link } from "@tanstack/react-router";
import { ArrowRight, UserRound, Building2, Store, FileCheck2, Send } from "lucide-react";
import { Button } from "@/components/ui/button";
import { STEPS } from "@/lib/application";
import { cn } from "@/lib/utils";

const DETAILS = [
  { icon: UserRound, description: "Your contact details, address and identification.", items: ["Contact details", "Residential address", "Identity & role"] },
  { icon: Building2, description: "The business behind your store, and what you sell.", items: ["Business details", "Product category", "Business address"] },
  { icon: Store, description: "Your store name, brand story and social presence.", items: ["Store name", "Social channels", "Brand details"] },
  { icon: FileCheck2, description: "Your registration status and registered business details.", items: ["Registration status", "Registration number", "Registered name"] },
  { icon: Send, description: "Check your details and send your application for review.", items: ["Review details", "Declarations", "Submit application"] },
];

export function ApplicationSteps() {
  const [active, setActive] = useState(0);
  const detail = DETAILS[active];
  if (!detail) return null;
  const Icon = detail.icon;
  return <>
    <ol className="mt-10 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
      {STEPS.map((step, index) => <li key={step.n} className="min-w-0">
        <Button variant="outline" aria-pressed={active === index} onClick={() => setActive(index)} className={cn("application-step group h-full min-h-44 w-full flex-col items-start justify-between whitespace-normal rounded-md border-2 p-5 text-left text-foreground hover:text-foreground sm:min-h-52 sm:p-6", active === index ? "border-primary bg-secondary hover:bg-secondary" : "border-border bg-background hover:border-primary hover:bg-secondary")}>
          <span className="flex w-full items-center justify-between"><span className="font-display text-4xl font-medium text-foreground">0{step.n}</span><ArrowRight className={cn("size-5 transition-transform", active === index ? "-rotate-45" : "group-hover:-rotate-45")} /></span>
          <span className="mt-7 font-display text-2xl font-medium leading-tight">{step.title}</span>
        </Button>
      </li>)}
    </ol>
    <div className="mt-6 grid gap-6 border-y py-8 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center" aria-live="polite">
      <div key={active} className="step-detail grid grid-cols-[auto_minmax(0,1fr)] gap-4"><Icon className="mt-1 size-7 text-accent" /><div className="min-w-0"><p className="text-lg font-medium sm:text-xl">{detail.description}</p><ul className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">{detail.items.map(item => <li key={item}>{item}</li>)}</ul></div></div>
      <Button asChild className="h-12 gap-6"><Link to="/apply">Begin application <ArrowRight className="size-4" /></Link></Button>
    </div>
  </>;
}