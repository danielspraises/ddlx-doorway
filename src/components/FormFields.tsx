import type { ReactNode } from "react";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { cn } from "@/lib/utils";

type Base = {
  name: string;
  label: string;
  required?: boolean;
  error?: string;
  hint?: string;
  className?: string;
};

function Wrap({ name, label, required, error, hint, className, children }: Base & { children: ReactNode }) {
  return (
    <div className={cn("space-y-1.5", className)}>
      <Label htmlFor={name} className="text-sm font-medium">
        {label} {required ? <span className="text-accent">*</span> : <span className="text-xs font-normal text-muted-foreground">(optional)</span>}
      </Label>
      {children}
      {error ? <p className="text-xs text-destructive">{error}</p> : hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}

export function TextField(p: Base & { value: string; onChange: (v: string) => void; type?: string; placeholder?: string; multiline?: boolean }) {
  const cls = p.error ? "border-destructive focus-visible:ring-destructive" : "";
  return (
    <Wrap {...p}>
      {p.multiline ? (
        <Textarea id={p.name} value={p.value} onChange={(e) => p.onChange(e.target.value)} placeholder={p.placeholder} rows={3} className={cls} />
      ) : (
        <Input id={p.name} type={p.type ?? "text"} value={p.value} onChange={(e) => p.onChange(e.target.value)} placeholder={p.placeholder} className={cls} min={p.type === "number" ? 0 : undefined} />
      )}
    </Wrap>
  );
}

export function SelectField(p: Base & { value: string; onChange: (v: string) => void; options: readonly string[]; placeholder?: string }) {
  return (
    <Wrap {...p}>
      <Select value={p.value} onValueChange={p.onChange}>
        <SelectTrigger id={p.name} className={cn("w-full", p.error && "border-destructive")}>
          <SelectValue placeholder={p.placeholder ?? "Select…"} />
        </SelectTrigger>
        <SelectContent>
          {p.options.map((o) => (
            <SelectItem key={o} value={o}>
              {o}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </Wrap>
  );
}

export function YesNoField(p: Base & { value: string; onChange: (v: string) => void }) {
  return (
    <Wrap {...p}>
      <RadioGroup value={p.value} onValueChange={p.onChange} className="flex gap-3">
        {([
          ["yes", "Yes"],
          ["no", "No"],
        ] as const).map(([v, l]) => (
          <label
            key={v}
            className={cn(
              "flex cursor-pointer items-center gap-2 rounded-lg border px-4 py-2.5 text-sm transition-colors",
              p.value === v ? "border-primary bg-secondary" : "hover:bg-secondary/50",
            )}
          >
            <RadioGroupItem value={v} id={`${p.name}-${v}`} /> {l}
          </label>
        ))}
      </RadioGroup>
    </Wrap>
  );
}
