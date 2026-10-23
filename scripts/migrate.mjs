import { readdirSync, readFileSync } from "node:fs";
import postgres from "postgres";
import { databaseUrl } from "./_env.mjs";

const sql = postgres(databaseUrl(), { onnotice: () => {} });
try {
  await sql`CREATE TABLE IF NOT EXISTS schema_migrations (name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())`;
  const done = new Set((await sql`SELECT name FROM schema_migrations`).map((row) => row.name));
  const dir = new URL("../db/migrations/", import.meta.url);
  for (const file of readdirSync(dir).filter((name) => name.endsWith(".sql")).sort()) {
    if (done.has(file)) continue;
    console.log(`Applying ${file}`);
    await sql.begin(async (tx) => {
      await tx.unsafe(readFileSync(new URL(file, dir), "utf8"));
      await tx`INSERT INTO schema_migrations (name) VALUES (${file})`;
    });
  }
  console.log("Database is up to date.");
} catch (error) {
  console.error("Migration failed:", error instanceof Error ? error.message : error);
  process.exitCode = 1;
} finally {
  await sql.end();
}
