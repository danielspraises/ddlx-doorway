import postgres from "postgres";
import { env } from "./env.server";

type Sql = ReturnType<typeof postgres>;

// Reuse one pool across hot reloads in development.
const globalForDb = globalThis as unknown as { __ddlxSql?: Sql };

export function getSql(): Sql {
  if (!globalForDb.__ddlxSql) {
    globalForDb.__ddlxSql = postgres(env("DATABASE_URL"), { max: 10, onnotice: () => {} });
  }
  return globalForDb.__ddlxSql;
}

export function json(value: unknown) {
  return getSql().json(value as never);
}

// Dates leave the server as ISO strings so they survive JSON and can be sent back unchanged.
export function plain<T extends object>(row: T): T {
  return Object.fromEntries(
    Object.entries(row).map(([key, value]) => [key, value instanceof Date ? value.toISOString() : value]),
  ) as T;
}

export function isUniqueViolation(error: unknown): boolean {
  return typeof error === "object" && error !== null && (error as { code?: string }).code === "23505";
}
