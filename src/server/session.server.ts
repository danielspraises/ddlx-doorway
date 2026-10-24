import { createHash, randomBytes } from "node:crypto";
import { deleteCookie, getCookie, setCookie } from "@tanstack/react-start/server";
import { sellerStage, type SellerStage } from "../lib/stage";
import type { Status } from "../lib/types";
import { getSql } from "./db.server";

const COOKIE = "ddlx_session";
const SESSION_DAYS = 30;

export type SessionUser = {
  id: string;
  email: string;
  isAdmin: boolean;
  stage: SellerStage;
  // Only filled in for admins: how many items are waiting for a decision.
  pending: { applications: number; verifications: number };
};

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
  const rows = await sql<
    { id: string; email: string; is_admin: boolean; application_status: Status | null; verification_status: Status | null }[]
  >`
    SELECT u.id, u.email,
      EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'admin') AS is_admin,
      a.status AS application_status, v.status AS verification_status
    FROM sessions s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN seller_applications a ON a.user_id = u.id
    LEFT JOIN seller_verifications v ON v.user_id = u.id
    WHERE s.token_hash = ${hashToken(token)} AND s.expires_at > now()`;
  const row = rows[0];
  if (!row) return null;
  let pending = { applications: 0, verifications: 0 };
  if (row.is_admin) {
    const counts = await sql<{ applications: number; verifications: number }[]>`
      SELECT (SELECT count(*) FROM seller_applications WHERE status = 'submitted')::int AS applications,
             (SELECT count(*) FROM seller_verifications WHERE status = 'submitted')::int AS verifications`;
    pending = counts[0] ?? pending;
  }
  return {
    id: row.id,
    email: row.email,
    isAdmin: row.is_admin,
    stage: sellerStage(row.application_status, row.verification_status),
    pending,
  };
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

export async function requireVerifiedSeller(): Promise<SessionUser> {
  const user = await requireUser();
  if (user.stage !== "verified") throw new Error("Only verified sellers can do this.");
  return user;
}
