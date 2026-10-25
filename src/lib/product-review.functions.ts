import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import { readinessProblems } from "@/lib/product-rules";
import type { Failure, Product, ProductReviewItem } from "@/lib/types";

// Newest submissions first; items waiting for a decision always come before the rest.
export const listProductReviews = createServerFn({ method: "GET" }).handler(async (): Promise<ProductReviewItem[]> => {
  const { requireProductReviewer } = await import("@/server/session.server");
  const { getSql, plain } = await import("@/server/db.server");
  const { productCols } = await import("@/server/products.server");
  await requireProductReviewer();
  const rows = await getSql()<ProductReviewItem[]>`
    SELECT ${productCols()},
      COALESCE(NULLIF(a.brand->>'store_name', ''), 'Unnamed store') AS store_name, u.email AS seller_email
    FROM products p
    JOIN users u ON u.id = p.seller_id
    LEFT JOIN seller_applications a ON a.user_id = p.seller_id
    WHERE p.status <> 'draft' OR p.live
    ORDER BY (p.status = 'pending_review') DESC, p.submitted_at DESC NULLS LAST, p.updated_at DESC
    LIMIT 300`;
  return rows.map((row) => plain(row));
});

type Result = { ok: true; product: Product } | Failure;

export const reviewProduct = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) =>
    z.object({
      id: z.string().uuid(),
      decision: z.enum(["approve", "request_changes", "reject", "take_down"]),
      comment: z.string().trim().max(2000),
      expected_updated_at: z.string().datetime({ offset: true }),
    }).parse(input),
  )
  .handler(async ({ data }): Promise<Result> => {
    const { requireProductReviewer } = await import("@/server/session.server");
    const { getSql, plain, json } = await import("@/server/db.server");
    const { buildSnapshot, loadProduct, removeUnreferenced } = await import("@/server/products.server");
    const { announce } = await import("@/server/events.server");
    const reviewer = await requireProductReviewer();
    if (data.decision !== "approve" && !data.comment) return { ok: false, error: "Add a note so the seller knows why." };

    const sql = getSql();
    const product = await loadProduct(data.id);
    if (!product) return { ok: false, error: "Product not found." };
    // The seller may have edited or withdrawn the product while it was open on this screen.
    if (new Date(product.updated_at).getTime() !== new Date(data.expected_updated_at).getTime()) {
      return { ok: false, error: "This product changed while you were reviewing it. Reopen it and review again." };
    }

    let oldPaths: string[] = [];
    let message = "";
    if (data.decision === "take_down") {
      if (!product.live) return { ok: false, error: "This product is not live." };
      await sql`UPDATE products SET live = false, admin_comment = ${data.comment}, reviewed_by = ${reviewer.id}, reviewed_at = now(),
        updated_at = date_trunc('milliseconds', now()) WHERE id = ${data.id}`;
      message = `${product.name} was taken off the marketplace`;
    } else {
      if (product.status !== "pending_review") return { ok: false, error: "This product is not waiting for review." };
      if (data.decision === "approve") {
        const problems = readinessProblems(product);
        if (problems.length) return { ok: false, error: `Cannot approve yet: ${problems[0] ?? "incomplete product"}` };
        const before = await sql<{ paths: string[] }[]>`
          SELECT ARRAY(SELECT jsonb_array_elements_text(COALESCE(published_snapshot->'image_paths', '[]'::jsonb))) AS paths FROM products WHERE id = ${data.id}`;
        oldPaths = before[0]?.paths ?? [];
        await sql`UPDATE products SET status = 'approved', live = true, published_snapshot = ${json(buildSnapshot(product))},
          published_at = now(), admin_comment = ${data.comment || null}, reviewed_by = ${reviewer.id}, reviewed_at = now(),
          updated_at = date_trunc('milliseconds', now())
          WHERE id = ${data.id} AND status = 'pending_review'`;
        message = `${product.name} was approved and is live`;
      } else {
        const status = data.decision === "reject" ? "rejected" : "changes_requested";
        await sql`UPDATE products SET status = ${status}, admin_comment = ${data.comment}, reviewed_by = ${reviewer.id}, reviewed_at = now(),
          updated_at = date_trunc('milliseconds', now()) WHERE id = ${data.id} AND status = 'pending_review'`;
        message = data.decision === "reject" ? `${product.name} was not approved` : `Changes requested on ${product.name}`;
      }
    }
    if (oldPaths.length) await removeUnreferenced(data.id, oldPaths);
    await announce({ audience: "seller", seller_id: product.seller_id, type: "product", message });
    await announce({ audience: "reviewers", type: "review_queue" });
    const fresh = await loadProduct(data.id);
    return fresh ? { ok: true, product: plain(fresh) } : { ok: false, error: "Product not found." };
  });
