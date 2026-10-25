import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import type { Failure, StaffMember } from "@/lib/types";

const PERMISSIONS = z.array(z.enum(["products"])).min(1, "Choose at least one permission");
type Result = { ok: true } | Failure;

export const listStaff = createServerFn({ method: "GET" }).handler(async (): Promise<StaffMember[]> => {
  const { requireAdmin } = await import("@/server/session.server");
  const { getSql, plain } = await import("@/server/db.server");
  await requireAdmin();
  const rows = await getSql()<StaffMember[]>`
    SELECT s.user_id, u.email, s.permissions, s.active, s.created_at
    FROM staff_members s JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC`;
  return rows.map((row) => plain(row));
});

// Staff accounts are made by an admin only. They cannot register themselves or become sellers' reviewers by accident.
export const createStaff = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) =>
    z.object({
      email: z.string().trim().toLowerCase().email("Enter a valid email").max(255),
      password: z.string().min(8, "Password must be at least 8 characters").max(128),
      permissions: PERMISSIONS,
    }).parse(input),
  )
  .handler(async ({ data }): Promise<Result> => {
    const { requireAdmin } = await import("@/server/session.server");
    const { getSql, isUniqueViolation } = await import("@/server/db.server");
    const { hashPassword } = await import("@/server/password.server");
    const admin = await requireAdmin();
    const hash = await hashPassword(data.password);
    try {
      await getSql().begin(async (tx) => {
        const rows = await tx<{ id: string }[]>`
          INSERT INTO users (email, password_hash, email_confirmed_at) VALUES (${data.email}, ${hash}, now()) RETURNING id`;
        const user = rows[0];
        if (!user) throw new Error("Could not create the account.");
        await tx`INSERT INTO staff_members (user_id, permissions, created_by) VALUES (${user.id}, ${tx.array(data.permissions)}, ${admin.id})`;
      });
      return { ok: true };
    } catch (error) {
      if (isUniqueViolation(error)) return { ok: false, error: "An account with this email already exists." };
      throw error;
    }
  });

export const updateStaff = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ user_id: z.string().uuid(), active: z.boolean(), permissions: PERMISSIONS }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireAdmin } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    await requireAdmin();
    const rows = await getSql()<{ user_id: string }[]>`
      UPDATE staff_members SET active = ${data.active}, permissions = ${getSql().array(data.permissions)}
      WHERE user_id = ${data.user_id} RETURNING user_id`;
    return rows[0] ? { ok: true } : { ok: false, error: "Staff member not found." };
  });

export const setStaffPassword = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ user_id: z.string().uuid(), password: z.string().min(8, "Password must be at least 8 characters").max(128) }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireAdmin } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const { hashPassword } = await import("@/server/password.server");
    await requireAdmin();
    const hash = await hashPassword(data.password);
    const done = await getSql().begin(async (tx) => {
      const rows = await tx<{ user_id: string }[]>`SELECT user_id FROM staff_members WHERE user_id = ${data.user_id}`;
      if (!rows[0]) return false;
      await tx`UPDATE users SET password_hash = ${hash} WHERE id = ${data.user_id}`;
      await tx`DELETE FROM sessions WHERE user_id = ${data.user_id}`;
      return true;
    });
    return done ? { ok: true } : { ok: false, error: "Staff member not found." };
  });
