import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import type { Category, Failure } from "@/lib/types";

const MAX_DEPTH = 4;
const name = z.string().trim().min(1, "Enter a name").max(80);
type Result = { ok: true } | Failure;
const fail = (error: string): Failure => ({ ok: false, error });

// Everyone signed in can read the tree; sellers are shown only the active part of it by the picker.
export const listCategories = createServerFn({ method: "GET" }).handler(async (): Promise<Category[]> => {
  const { requireUser } = await import("@/server/session.server");
  const { getSql } = await import("@/server/db.server");
  await requireUser();
  const rows = await getSql()<Category[]>`
    SELECT c.id, c.parent_id, c.name, c.position, c.active,
      (SELECT count(*) FROM products p WHERE p.category_id = c.id)::int AS product_count
    FROM categories c ORDER BY c.position, lower(c.name)`;
  return [...rows];
});

type Tx = Parameters<Parameters<ReturnType<typeof import("@/server/db.server").getSql>["begin"]>[0]>[0];

// How many levels are above (and including) this category.
async function depthOf(tx: Tx, id: string): Promise<number> {
  const rows = await tx<{ n: number }[]>`SELECT coalesce(array_length(category_path(${id}::uuid), 1), 0) AS n`;
  return rows[0]?.n ?? 0;
}

// How many levels this category and everything under it take up.
async function heightOf(tx: Tx, id: string): Promise<number> {
  const rows = await tx<{ n: number }[]>`
    WITH RECURSIVE down AS (
      SELECT id, 1 AS level FROM categories WHERE id = ${id}
      UNION ALL SELECT c.id, down.level + 1 FROM categories c JOIN down ON c.parent_id = down.id
    ) SELECT max(level)::int AS n FROM down`;
  return rows[0]?.n ?? 1;
}

export const createCategory = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ parent_id: z.string().uuid().nullable(), name }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireAdmin } = await import("@/server/session.server");
    const { getSql, isUniqueViolation } = await import("@/server/db.server");
    await requireAdmin();
    try {
      return await getSql().begin(async (tx): Promise<Result> => {
        if (data.parent_id) {
          const parent = await tx<{ id: string }[]>`SELECT id FROM categories WHERE id = ${data.parent_id}`;
          if (!parent[0]) return fail("The parent category no longer exists.");
          if ((await depthOf(tx, data.parent_id)) >= MAX_DEPTH) return fail(`Categories can go ${MAX_DEPTH} levels deep at most.`);
        }
        await tx`
          INSERT INTO categories (parent_id, name, position)
          VALUES (${data.parent_id}, ${data.name}, COALESCE((SELECT max(position) + 1 FROM categories WHERE parent_id IS NOT DISTINCT FROM ${data.parent_id}::uuid), 0))`;
        return { ok: true };
      });
    } catch (error) {
      if (isUniqueViolation(error)) return fail("There is already a category with this name here.");
      throw error;
    }
  });

export const renameCategory = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid(), name }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireAdmin } = await import("@/server/session.server");
    const { getSql, isUniqueViolation } = await import("@/server/db.server");
    await requireAdmin();
    try {
      const sql = getSql();
      const rows = await sql<{ id: string }[]>`UPDATE categories SET name = ${data.name} WHERE id = ${data.id} RETURNING id`;
      if (!rows[0]) return fail("Category not found.");
      // Keep the short name stored on products in step.
      await sql`UPDATE products SET category = ${data.name} WHERE category_id = ${data.id}`;
      return { ok: true };
    } catch (error) {
      if (isUniqueViolation(error)) return fail("There is already a category with this name here.");
      throw error;
    }
  });

export const moveCategory = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid(), parent_id: z.string().uuid().nullable() }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireAdmin } = await import("@/server/session.server");
    const { getSql, isUniqueViolation } = await import("@/server/db.server");
    await requireAdmin();
    try {
      return await getSql().begin(async (tx): Promise<Result> => {
        if (data.parent_id === data.id) return fail("A category cannot go inside itself.");
        if (data.parent_id) {
          const inside = await tx<{ id: string }[]>`
            WITH RECURSIVE down AS (
              SELECT id FROM categories WHERE id = ${data.id}
              UNION ALL SELECT c.id FROM categories c JOIN down ON c.parent_id = down.id
            ) SELECT id FROM down WHERE id = ${data.parent_id}`;
          if (inside[0]) return fail("A category cannot be moved into one of its own sub-categories.");
          if ((await depthOf(tx, data.parent_id)) + (await heightOf(tx, data.id)) > MAX_DEPTH) return fail(`That would make the tree deeper than ${MAX_DEPTH} levels.`);
        }
        const rows = await tx<{ id: string }[]>`
          UPDATE categories SET parent_id = ${data.parent_id},
            position = COALESCE((SELECT max(position) + 1 FROM categories WHERE parent_id IS NOT DISTINCT FROM ${data.parent_id}::uuid), 0)
          WHERE id = ${data.id} RETURNING id`;
        return rows[0] ? { ok: true } : fail("Category not found.");
      });
    } catch (error) {
      if (isUniqueViolation(error)) return fail("There is already a category with this name there.");
      throw error;
    }
  });

// Hides or shows a category together with everything under it. Products keep their category.
export const setCategoryActive = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid(), active: z.boolean() }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireAdmin } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    await requireAdmin();
    const rows = await getSql()<{ id: string }[]>`
      WITH RECURSIVE down AS (
        SELECT id FROM categories WHERE id = ${data.id}
        UNION ALL SELECT c.id FROM categories c JOIN down ON c.parent_id = down.id
      ) UPDATE categories SET active = ${data.active} WHERE id IN (SELECT id FROM down) RETURNING id`;
    return rows[0] ? { ok: true } : fail("Category not found.");
  });

export const deleteCategory = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid() }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireAdmin } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    await requireAdmin();
    const sql = getSql();
    const used = await sql<{ children: number; products: number }[]>`
      SELECT (SELECT count(*) FROM categories WHERE parent_id = ${data.id})::int AS children,
             (SELECT count(*) FROM products WHERE category_id = ${data.id})::int AS products`;
    if ((used[0]?.children ?? 0) > 0) return fail("Delete or move its sub-categories first.");
    if ((used[0]?.products ?? 0) > 0) return fail("Products use this category. Move them first, or hide the category instead.");
    const rows = await sql<{ id: string }[]>`DELETE FROM categories WHERE id = ${data.id} RETURNING id`;
    return rows[0] ? { ok: true } : fail("Category not found.");
  });
