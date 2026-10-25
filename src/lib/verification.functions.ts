import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import type { Failure, Json, Verification, VerificationReviewItem } from "@/lib/types";

const KINDS = ["identity", "portrait", "registration", "proof_address", "presence"] as const;
const IMAGE_ONLY = ["portrait", "presence"];
const MAX_BYTES = 10 * 1024 * 1024;
const EDITABLE = ["draft", "needs_corrections"];
const details = z.record(z.string().max(5000));
const step = z.number().int().min(1).max(8);
const expectedVersion = z.string().min(1).nullable();

type Saved = { ok: true; verification: Verification } | Failure;
const conflict: Failure = { ok: false, error: "This verification changed. Refresh and try again." };

// Finds the seller's verification record, creating it once their application is approved.
async function loadRecord(userId: string) {
  const { getSql, plain } = await import("@/server/db.server");
  const sql = getSql();
  const existing = (await sql<Verification[]>`SELECT * FROM seller_verifications WHERE user_id = ${userId}`)[0];
  if (existing) return { record: plain(existing), created: false };
  const approved = await sql<{ id: string }[]>`
    SELECT id FROM seller_applications WHERE user_id = ${userId} AND status = 'approved'`;
  const application = approved[0];
  if (!application) return null;
  const rows = await sql<Verification[]>`
    INSERT INTO seller_verifications (application_id, user_id) VALUES (${application.id}, ${userId})
    ON CONFLICT (application_id) DO NOTHING RETURNING *`;
  const inserted = rows[0] ?? (await sql<Verification[]>`SELECT * FROM seller_verifications WHERE user_id = ${userId}`)[0];
  return inserted ? { record: plain(inserted), created: true } : null;
}

export const getMyVerification = createServerFn({ method: "GET" }).handler(async (): Promise<Verification | null> => {
  const { requireUser } = await import("@/server/session.server");
  const { getSql, plain } = await import("@/server/db.server");
  const user = await requireUser();
  const rows = await getSql()<Verification[]>`SELECT * FROM seller_verifications WHERE user_id = ${user.id}`;
  return rows[0] ? plain(rows[0]) : null;
});

export const saveVerification = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) =>
    z.object({ details, current_step: step, submit: z.boolean(), expected_updated_at: expectedVersion }).parse(input),
  )
  .handler(async ({ data }): Promise<Saved> => {
    const { requireUser } = await import("@/server/session.server");
    const { getSql, json, plain } = await import("@/server/db.server");
    const user = await requireUser();
    const sql = getSql();
    const loaded = await loadRecord(user.id);
    if (!loaded) return { ok: false, error: "Application approval is required." };
    const { record, created } = loaded;
    if (!EDITABLE.includes(record.status)) return { ok: false, error: "This verification can no longer be edited." };
    if (!created && record.updated_at !== data.expected_updated_at) return conflict;

    if (data.submit) {
      const docs = (record.documents ?? {}) as Record<string, Json>;
      if (data.details["declaration"] !== "yes" || data.details["social_verified"] !== "yes" || data.details["address_confirmed"] !== "yes" || !data.details["business_presence"]?.trim()) {
        return { ok: false, error: "Complete the verification details and declarations." };
      }
      if (!docs["identity"] || !docs["portrait"] || !docs["proof_address"] || !docs["presence"]) {
        return { ok: false, error: "Required verification files are missing." };
      }
      const registered = (await sql<{ registered: string | null }[]>`
        SELECT registration->>'registered' AS registered FROM seller_applications WHERE id = ${record.application_id}`)[0]?.registered;
      if (registered === "yes" && !docs["registration"]) return { ok: false, error: "A registration document is required." };
    }

    const rows = await sql<Verification[]>`
      UPDATE seller_verifications SET
        details = ${json(data.details)}, current_step = ${data.current_step},
        status = CASE WHEN ${data.submit}::boolean THEN 'submitted'::application_status ELSE status END,
        submitted_at = CASE WHEN ${data.submit}::boolean THEN date_trunc('milliseconds', now()) ELSE submitted_at END,
        updated_at = date_trunc('milliseconds', now())
      WHERE id = ${record.id} AND updated_at = ${record.updated_at}::timestamptz RETURNING *`;
    const row = rows[0];
    return row ? { ok: true, verification: plain(row) } : conflict;
  });

export const uploadVerificationDocument = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) =>
    z
      .object({
        kind: z.enum(KINDS),
        content_type: z.string().max(100),
        data_base64: z.string().min(1).max(15_000_000),
        details,
        current_step: step,
        expected_updated_at: expectedVersion,
      })
      .parse(input),
  )
  .handler(async ({ data }): Promise<Saved> => {
    const { randomUUID } = await import("node:crypto");
    const { requireUser } = await import("@/server/session.server");
    const { getSql, json, plain } = await import("@/server/db.server");
    const storage = await import("@/server/storage.server");
    const user = await requireUser();

    const allowed = IMAGE_ONLY.includes(data.kind) ? ["image/jpeg", "image/png", "image/webp"] : ["image/jpeg", "image/png", "image/webp", "application/pdf"];
    const bytes = Buffer.from(data.data_base64, "base64");
    if (!allowed.includes(data.content_type) || bytes.length === 0 || bytes.length > MAX_BYTES || !storage.matchesDeclaredType(data.content_type, bytes)) {
      return { ok: false, error: "Choose a JPG, PNG, WebP or permitted PDF up to 10 MB." };
    }

    const loaded = await loadRecord(user.id);
    if (!loaded) return { ok: false, error: "Application approval is required." };
    const { record, created } = loaded;
    if (!EDITABLE.includes(record.status)) return { ok: false, error: "This verification can no longer be edited." };
    if (!created && record.updated_at !== data.expected_updated_at) return conflict;

    const extension = storage.EXTENSION[data.content_type] ?? "jpg";
    const path = `${user.id}/${record.id}/${data.kind}-${randomUUID()}.${extension}`;
    await storage.saveVerificationFile(path, bytes);
    const previous = ((record.documents ?? {}) as Record<string, Json>)[data.kind];

    const rows = await getSql()<Verification[]>`
      UPDATE seller_verifications SET
        documents = documents || ${json({ [data.kind]: path })}, details = ${json(data.details)},
        current_step = ${data.current_step}, updated_at = date_trunc('milliseconds', now())
      WHERE id = ${record.id} AND updated_at = ${record.updated_at}::timestamptz RETURNING *`;
    const row = rows[0];
    if (!row) {
      await storage.removeVerificationFile(path);
      return conflict;
    }
    if (typeof previous === "string" && storage.VERIFICATION_PATH.test(previous)) {
      await storage.removeVerificationFile(previous).catch(() => undefined);
    }
    return { ok: true, verification: plain(row) };
  });

export const getVerificationFile = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ path: z.string().max(300) }).parse(input))
  .handler(async ({ data }): Promise<{ ok: true; content_type: string; data_base64: string } | Failure> => {
    const { requireUser } = await import("@/server/session.server");
    const storage = await import("@/server/storage.server");
    const user = await requireUser();
    const match = storage.VERIFICATION_PATH.exec(data.path);
    if (!match) return { ok: false, error: "Invalid file." };
    if (match[1] !== user.id && !user.isAdmin) return { ok: false, error: "You do not have access to this file." };
    try {
      const bytes = await storage.readVerificationFile(data.path);
      return { ok: true, content_type: storage.CONTENT_TYPE[match[3] ?? ""] ?? "application/octet-stream", data_base64: bytes.toString("base64") };
    } catch {
      return { ok: false, error: "The file could not be found." };
    }
  });

export const listVerificationReviews = createServerFn({ method: "GET" }).handler(async (): Promise<VerificationReviewItem[]> => {
  const { requireAdmin } = await import("@/server/session.server");
  const { getSql, plain } = await import("@/server/db.server");
  await requireAdmin();
  const rows = await getSql()<VerificationReviewItem[]>`
    SELECT v.*, jsonb_build_object('brand', a.brand, 'personal', a.personal, 'business', a.business,
      'registration', a.registration, 'applicant_email', a.applicant_email) AS seller_applications
    FROM seller_verifications v LEFT JOIN seller_applications a ON a.id = v.application_id
    WHERE v.status <> 'draft' ORDER BY v.updated_at DESC`;
  return rows.map((row) => plain(row));
});

export const reviewVerification = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) =>
    z
      .object({
        id: z.string().uuid(),
        status: z.enum(["approved", "rejected", "needs_corrections"]),
        comment: z.string().trim().max(5000),
        expected_updated_at: z.string().min(1),
      })
      .parse(input),
  )
  .handler(async ({ data }): Promise<{ ok: true } | Failure> => {
    const { requireAdmin } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const admin = await requireAdmin();
    if (data.status !== "approved" && !data.comment) return { ok: false, error: "Add feedback for the seller first." };
    const rows = await getSql()`
      UPDATE seller_verifications SET
        status = ${data.status}::application_status, admin_comment = ${data.comment || null},
        reviewed_by = ${admin.id}, reviewed_at = now(), updated_at = date_trunc('milliseconds', now())
      WHERE id = ${data.id} AND status = 'submitted' AND updated_at = ${data.expected_updated_at}::timestamptz RETURNING id`;
    if (!rows.length) return { ok: false, error: "This verification was already decided or has changed. Refresh and try again." };
    return { ok: true };
  });
