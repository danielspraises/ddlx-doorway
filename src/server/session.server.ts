import { createHash, randomBytes } from "node:crypto";
import { deleteCookie, getCookie, setCookie } from "@tanstack/react-start/server";
import { getSql } from "./db.server";

const COOKIE = "ddlx_session";
const SESSION_DAYS = 30;

export type SessionUser = { id: string; email: string; isAdmin: boolean };

export const hashToken = (token: string) => createHash("sha256").update(token).digest("hex");

export async function createSession(userId: string) {
  const sql = getSql();
  const token = randomBytes(32).toString("base64url");
  await sql`INSERT INTO sessions (token_hash, user_id, expires_at)
    VALUES (${hashToken(token)}, ${userId}, now() + make_interval(days => ${SESSION_DAYS}))`;
  setCookie(COOKIE, token, {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env["NODE_ENV"] === "production",
    path: "/",
    maxAge: 60 * 60 * 24 * SESSION_DAYS,
  });
}

export async function destroySession() {
  const token = getCookie(COOKIE);
  if (token) await getSql()`DELETE FROM sessions WHERE token_hash = ${hashToken(token)}`;
  deleteCookie(COOKIE, { path: "/" });
}

export async function currentUser(): Promise<SessionUser | null> {
  const token = getCookie(COOKIE);
  if (!token) return null;
  const sql = getSql();
  const rows = await sql<{ id: string; email: string; is_admin: boolean }[]>`
    SELECT u.id, u.email,
      EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'admin') AS is_admin
    FROM sessions s JOIN users u ON u.id = s.user_id
    WHERE s.token_hash = ${hashToken(token)} AND s.expires_at > now()`;
  const row = rows[0];
  return row ? { id: row.id, email: row.email, isAdmin: row.is_admin } : null;
}

export async function requireUser(): Promise<SessionUser> {
  const user = await currentUser();
  if (!user) throw new Error("You need to sign in.");
  return user;
}

export async function requireAdmin(): Promise<SessionUser> {
  const user = await requireUser();
  if (!user.isAdmin) throw new Error("Staff access required.");
  return user;
}
