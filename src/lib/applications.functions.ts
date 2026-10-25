import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import type { Application, Failure } from "@/lib/types";

const fields = z.record(z.string().max(5000));
const REVIEW_STATUSES = ["approved", "rejected", "needs_corrections"] as const;

export const getMyApplication = createServerFn({ method: "GET" }).handler(async (): Promise<Application | null> => {
  const { requireUser } = await import("@/server/session.server");
  const { getSql, plain } = await import("@/server/db.server");
  const user = await requireUser();
  const rows = await getSql()<Application[]>`SELECT * FROM seller_applications WHERE user_id = ${user.id}`;
  return rows[0] ? plain(rows[0]) : null;
});

export const saveApplication = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) =>
    z
      .object({
        sections: z.object({ personal: fields, business: fields, brand: fields, registration: fields }),
        declaration: fields,
        current_step: z.number().int().min(1).max(5),
        submit: z.boolean(),
      })
      .parse(input),
  )
  .handler(async ({ data }): Promise<{ ok: true; application: Application } | Failure> => {
    const { requireUser } = await import("@/server/session.server");
    const { getSql, json, plain, isUniqueViolation } = await import("@/server/db.server");
    const { validate, STEPS } = await import("@/lib/application");
    const user = await requireUser();
    const sql = getSql();
    const taken: Failure = { ok: false, code: "email_taken", error: "This email is already used by another application." };

    const existing = (await sql<Application[]>`SELECT * FROM seller_applications WHERE user_id = ${user.id}`)[0];
    if (existing && !["draft", "needs_corrections"].includes(existing.status)) {
      return { ok: false, error: "This application can no longer be edited." };
    }

    if (data.submit) {
      for (const step of STEPS.slice(0, 4)) {
        const errors = validate(step.n as 1 | 2 | 3 | 4, data.sections[step.key as "personal" | "business" | "brand" | "registration"]);
        if (Object.keys(errors).length) return { ok: false, error: "Please complete every section before submitting." };
      }
      if ([0, 1, 2, 3].some((i) => data.declaration[String(i)] !== "yes")) {
        return { ok: false, error: "Please confirm all four declarations." };
      }
    }

    const email = data.sections.personal["email"]?.trim().toLowerCase() || null;
    if (email) {
      const clash = await sql`SELECT 1 FROM seller_applications
        WHERE lower(applicant_email) = ${email} AND status <> 'rejected' AND user_id <> ${user.id} LIMIT 1`;
      if (clash.length) return taken;
    }

    const { personal, business, brand, registration } = data.sections;
    try {
      const rows = existing
        ? await sql<Application[]>`
            UPDATE seller_applications SET
              personal = ${json(personal)}, business = ${json(business)}, brand = ${json(brand)},
              registration = ${json(registration)}, declaration = ${json(data.declaration)},
              applicant_email = ${email}, current_step = ${data.current_step},
              status = CASE WHEN ${data.submit}::boolean THEN 'submitted'::application_status ELSE status END,
              submitted_at = CASE WHEN ${data.submit}::boolean THEN date_trunc('milliseconds', now()) ELSE submitted_at END,
              updated_at = date_trunc('milliseconds', now())
            WHERE id = ${existing.id} RETURNING *`
        : await sql<Application[]>`
            INSERT INTO seller_applications
              (user_id, personal, business, brand, registration, declaration, applicant_email, current_step, status, submitted_at)
            VALUES (${user.id}, ${json(personal)}, ${json(business)}, ${json(brand)}, ${json(registration)},
              ${json(data.declaration)}, ${email}, ${data.current_step},
              CASE WHEN ${data.submit}::boolean THEN 'submitted'::application_status ELSE 'draft'::application_status END,
              CASE WHEN ${data.submit}::boolean THEN date_trunc('milliseconds', now()) END)
            RETURNING *`;
      const row = rows[0];
      if (!row) return { ok: false, error: "Could not save your application. Please try again." };
      return { ok: true, application: plain(row) };
    } catch (error) {
      if (isUniqueViolation(error)) return taken;
      throw error;
    }
  });

export const listApplicationReviews = createServerFn({ method: "GET" }).handler(async (): Promise<Application[]> => {
  const { requireAdmin } = await import("@/server/session.server");
  const { getSql, plain } = await import("@/server/db.server");
  await requireAdmin();
  const rows = await getSql()<Application[]>`
    SELECT * FROM seller_applications WHERE status <> 'draft' ORDER BY updated_at DESC`;
  return rows.map((row) => plain(row));
});

export const reviewApplication = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) =>
    z
      .object({
        id: z.string().uuid(),
        status: z.enum(REVIEW_STATUSES),
        comment: z.string().trim().max(5000),
        expected_updated_at: z.string().min(1),
      })
      .parse(input),
  )
  .handler(async ({ data }): Promise<{ ok: true } | Failure> => {
    const { requireAdmin } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const admin = await requireAdmin();
    if (data.status !== "approved" && !data.comment) return { ok: false, error: "Add feedback for the applicant first." };
    const rows = await getSql()`
      UPDATE seller_applications SET
        status = ${data.status}::application_status, admin_comment = ${data.comment || null},
        reviewed_by = ${admin.id}, reviewed_at = now(), updated_at = date_trunc('milliseconds', now())
      WHERE id = ${data.id} AND status = 'submitted' AND updated_at = ${data.expected_updated_at}::timestamptz
      RETURNING id`;
    if (!rows.length) return { ok: false, error: "This application was already decided or has changed. Refresh and try again." };
    return { ok: true };
  });
