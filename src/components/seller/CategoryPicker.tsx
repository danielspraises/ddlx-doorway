import { useSuspenseQuery } from "@tanstack/react-query";
import { Label } from "@/components/ui/label";
import { categoriesQuery, childrenOf, pathOf } from "@/lib/categories";

const selectClass = "h-10 w-full rounded-md border border-input bg-background px-3 text-sm";

// Drill-down picker: choose a top-level category, then keep choosing until there is nothing more specific.
export function CategoryPicker({ value, onChange, error }: { value: string | null; onChange: (id: string | null) => void; error?: string }) {
  const { data: all } = useSuspenseQuery(categoriesQuery);
  const chain = pathOf(all, value);
  const levels: { parent: string | null; options: typeof all; selected: string }[] = [];
  let parent: string | null = null;
  for (let depth = 0; depth <= chain.length; depth++) {
    // Hidden categories stay visible only while they are the current selection.
    const options = childrenOf(all, parent).filter((c) => c.active || c.id === chain[depth]?.id);
    if (!options.length) break;
    levels.push({ parent, options, selected: chain[depth]?.id ?? "" });
    parent = chain[depth]?.id ?? null;
    if (!parent) break;
  }
  const pick = (level: number, id: string) => onChange(id || (level > 0 ? levels[level - 1]?.selected ?? null : null));
  return (
    <div className="space-y-2">
      <Label>Category <span className="text-destructive">*</span></Label>
      <div className="grid gap-2 sm:grid-cols-2">
        {levels.map((level, index) => (
          <select key={level.parent ?? "root"} aria-label={index === 0 ? "Category" : `Sub-category ${index}`} className={selectClass} value={level.selected} onChange={(e) => pick(index, e.target.value)}>
            <option value="">{index === 0 ? "Choose a category…" : "Choose a sub-category…"}</option>
            {level.options.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        ))}
      </div>
      {chain.length > 0 && <p className="text-sm text-muted-foreground">{chain.map((c) => c.name).join(" › ")}</p>}
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    </div>
  );
}
