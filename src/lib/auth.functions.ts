import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import type { Failure } from "@/lib/types";

const email = z.string().trim().toLowerCase().email("Enter a valid email").max(255);
const newPassword = z.string().min(8, "Password must be at least 8 characters").max(128);

type Result = { ok: true } | Failure;

export const getCurrentUser = createServerFn({ method: "GET" }).handler(async () => {
  const { currentUser } = await import("@/server/session.server");
  return currentUser();
});

export const signUp = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ email, password: newPassword }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { getSql, isUniqueViolation } = await import("@/server/db.server");
    const { hashPassword } = await import("@/server/password.server");
    const { createSession } = await import("@/server/session.server");
    const { env } = await import("@/server/env.server");
    // The designated admin account is created with `npm run db:admin`, never through public sign-up.
    if (data.email === env("ADMIN_EMAIL", "admin@ddlxapparel.com").toLowerCase()) {
      return { ok: false, error: "This email is reserved. Choose another one." };
    }
    const sql = getSql();
    try {
      const rows = await sql<{ id: string }[]>`
        INSERT INTO users (email, password_hash, email_confirmed_at)
        VALUES (${data.email}, ${await hashPassword(data.password)}, now()) RETURNING id`;
      const user = rows[0];
      if (!user) return { ok: false, error: "Could not create the account. Please try again." };
      await createSession(user.id);
      return { ok: true };
    } catch (error) {
      if (isUniqueViolation(error)) return { ok: false, error: "An account with this email already exists." };
      throw error;
    }
  });

export const signIn = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ email, password: z.string().min(1).max(128) }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { getSql } = await import("@/server/db.server");
    const { verifyPassword, DUMMY_HASH } = await import("@/server/password.server");
    const { createSession } = await import("@/server/session.server");
    const rows = await getSql()<{ id: string; password_hash: string }[]>`
      SELECT id, password_hash FROM users WHERE lower(email) = ${data.email}`;
    const user = rows[0];
    const valid = await verifyPassword(data.password, user?.password_hash ?? DUMMY_HASH);
    if (!user || !valid) return { ok: false, error: "Invalid email or password." };
    await createSession(user.id);
    return { ok: true };
  });

export const signOut = createServerFn({ method: "POST" }).handler(async () => {
  const { destroySession } = await import("@/server/session.server");
  await destroySession();
  return { ok: true } as const;
});

// Email delivery is not configured yet, so the reset link is printed in the server console.
// Staff can also reset any password directly with `npm run db:admin`.
export const requestPasswordReset = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ email }).parse(input))
  .handler(async ({ data }): Promise<{ ok: true }> => {
    const { randomBytes } = await import("node:crypto");
    const { getRequest } = await import("@tanstack/react-start/server");
    const { getSql } = await import("@/server/db.server");
    const { hashToken } = await import("@/server/session.server");
    const { loadEnv } = await import("@/server/env.server");
    const sql = getSql();
    const rows = await sql<{ id: string }[]>`SELECT id FROM users WHERE lower(email) = ${data.email}`;
    const user = rows[0];
    if (user) {
      const token = randomBytes(32).toString("base64url");
      await sql`INSERT INTO password_resets (token_hash, user_id, expires_at)
        VALUES (${hashToken(token)}, ${user.id}, now() + interval '1 hour')`;
      loadEnv();
      const base = process.env["APP_URL"] || new URL(getRequest().url).origin;
      console.log(`[password reset] Email is not configured. Reset link for ${data.email}: ${base}/auth?token=${token}`);
    }
    return { ok: true };
  });

export const resetPassword = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ token: z.string().min(20).max(200), password: newPassword }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { getSql } = await import("@/server/db.server");
    const { hashPassword } = await import("@/server/password.server");
    const { createSession, hashToken } = await import("@/server/session.server");
    const hash = await hashPassword(data.password);
    const userId = await getSql().begin(async (tx) => {
      const rows = await tx<{ user_id: string }[]>`
        UPDATE password_resets SET used_at = now()
        WHERE token_hash = ${hashToken(data.token)} AND used_at IS NULL AND expires_at > now()
        RETURNING user_id`;
      const row = rows[0];
      if (!row) return null;
      await tx`UPDATE users SET password_hash = ${hash} WHERE id = ${row.user_id}`;
      await tx`DELETE FROM sessions WHERE user_id = ${row.user_id}`;
      return row.user_id;
    });
    if (!userId) return { ok: false, error: "This reset link is invalid or has expired. Request a new one." };
    await createSession(userId);
    return { ok: true };
  });
