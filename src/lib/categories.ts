import { queryOptions } from "@tanstack/react-query";
import { listCategories } from "@/lib/categories.functions";
import type { Category } from "@/lib/types";

export const categoriesQuery = queryOptions({ queryKey: ["categories"], queryFn: () => listCategories() });

export const childrenOf = (all: Category[], parentId: string | null) =>
  all.filter((c) => c.parent_id === parentId).sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));

// Root-to-category chain for an id.
export function pathOf(all: Category[], id: string | null): Category[] {
  const byId = new Map(all.map((c) => [c.id, c]));
  const chain: Category[] = [];
  for (let node = id ? byId.get(id) : undefined; node; node = node.parent_id ? byId.get(node.parent_id) : undefined) chain.unshift(node);
  return chain;
}

// The tree as an indented list, parents before their children.
export function flatten(all: Category[]): { category: Category; depth: number }[] {
  const out: { category: Category; depth: number }[] = [];
  const walk = (parent: string | null, depth: number) => {
    for (const category of childrenOf(all, parent)) {
      out.push({ category, depth });
      walk(category.id, depth + 1);
    }
  };
  walk(null, 0);
  return out;
}
