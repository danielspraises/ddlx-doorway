import { useState } from "react";
import { useQueryClient, useSuspenseQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { categoriesQuery, childrenOf, flatten } from "@/lib/categories";
import { createCategory, deleteCategory, moveCategory, renameCategory, setCategoryActive } from "@/lib/categories.functions";
import type { Category } from "@/lib/types";

const MAX_DEPTH = 4;

export function CategoriesPanel() {
  const { data: all } = useSuspenseQuery(categoriesQuery);
  const queryClient = useQueryClient();
  const [adding, setAdding] = useState<string | "root" | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [moving, setMoving] = useState<string | null>(null);
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const rows = flatten(all);
  const reset = () => { setAdding(null); setEditing(null); setMoving(null); setText(""); };

  const run = async (job: () => Promise<{ ok: true } | { ok: false; error: string }>, done?: string) => {
    if (busy) return;
    setBusy(true);
    try {
      const result = await job();
      if (!result.ok) { toast.error(result.error); return; }
      await queryClient.invalidateQueries({ queryKey: categoriesQuery.queryKey });
      if (done) toast.success(done);
      reset();
    } catch { toast.error("Something went wrong. Please try again."); }
    finally { setBusy(false); }
  };

  // Where a category may be moved: anywhere except itself and its own descendants.
  const targets = (node: Category) => {
    const banned = new Set<string>([node.id]);
    for (const { category } of rows) if (category.parent_id && banned.has(category.parent_id)) banned.add(category.id);
    return rows.filter(({ category }) => !banned.has(category.id));
  };

  const form = (placeholder: string, onSave: () => void) => (
    <form className="mt-2 flex gap-2" onSubmit={(e) => { e.preventDefault(); onSave(); }}>
      <Input autoFocus value={text} onChange={(e) => setText(e.target.value)} placeholder={placeholder} maxLength={80} aria-label={placeholder} />
      <Button type="submit" disabled={busy || !text.trim()}>Save</Button>
      <Button type="button" variant="ghost" onClick={reset}>Cancel</Button>
    </form>
  );

  return <section className="mt-8">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <p className="text-sm text-muted-foreground">{all.length} categories · up to {MAX_DEPTH} levels. Sellers pick the most specific one.</p>
      <Button onClick={() => { reset(); setAdding("root"); }}>Add top-level category</Button>
    </div>
    {adding === "root" && form("New top-level category", () => void run(() => createCategory({ data: { parent_id: null, name: text } }), "Category added."))}
    <ul className="mt-4 divide-y border-y">
      {rows.map(({ category, depth }) => {
        const hasChildren = childrenOf(all, category.id).length > 0;
        return <li key={category.id} className="py-3" style={{ paddingLeft: depth * 20 }}>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span className={`font-medium ${category.active ? "" : "text-muted-foreground line-through"}`}>{category.name}</span>
            <span className="text-xs text-muted-foreground">{category.product_count} product{category.product_count === 1 ? "" : "s"}{category.active ? "" : " · hidden"}</span>
            <span className="ml-auto flex flex-wrap gap-1">
              {depth < MAX_DEPTH - 1 && <Button size="sm" variant="ghost" onClick={() => { reset(); setAdding(category.id); }}>Add sub</Button>}
              <Button size="sm" variant="ghost" onClick={() => { reset(); setEditing(category.id); setText(category.name); }}>Rename</Button>
              <Button size="sm" variant="ghost" onClick={() => { reset(); setMoving(category.id); }}>Move</Button>
              <Button size="sm" variant="ghost" disabled={busy} onClick={() => void run(() => setCategoryActive({ data: { id: category.id, active: !category.active } }))}>{category.active ? "Hide" : "Show"}</Button>
              <Button size="sm" variant="ghost" disabled={busy || hasChildren || category.product_count > 0} onClick={() => { if (window.confirm(`Delete “${category.name}”?`)) void run(() => deleteCategory({ data: { id: category.id } }), "Category deleted."); }}>Delete</Button>
            </span>
          </div>
          {adding === category.id && form(`New sub-category of ${category.name}`, () => void run(() => createCategory({ data: { parent_id: category.id, name: text } }), "Category added."))}
          {editing === category.id && form("Category name", () => void run(() => renameCategory({ data: { id: category.id, name: text } }), "Category renamed."))}
          {moving === category.id && <div className="mt-2 flex gap-2">
            <select aria-label="Move to" className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" defaultValue="" onChange={(e) => { const v = e.target.value; if (v) void run(() => moveCategory({ data: { id: category.id, parent_id: v === "root" ? null : v } }), "Category moved."); }}>
              <option value="">Move to…</option>
              <option value="root">Top level</option>
              {targets(category).map(({ category: c, depth: d }) => <option key={c.id} value={c.id}>{`${"— ".repeat(d)}${c.name}`}</option>)}
            </select>
            <Button type="button" variant="ghost" onClick={reset}>Cancel</Button>
          </div>}
        </li>;
      })}
    </ul>
  </section>;
}
