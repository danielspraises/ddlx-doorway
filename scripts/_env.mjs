import { readFileSync } from "node:fs";
import { resolve } from "node:path";

// Minimal .env loader so the scripts work without extra dependencies.
export function loadEnv() {
  try {
    const text = readFileSync(resolve(process.cwd(), ".env"), "utf8");
    for (const line of text.split(/\r?\n/)) {
      if (line.trimStart().startsWith("#")) continue;
      const match = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/);
      if (!match) continue;
      let value = match[2];
      if (value.length >= 2 && ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'")))) value = value.slice(1, -1);
      if (process.env[match[1]] === undefined) process.env[match[1]] = value;
    }
  } catch {
    // No .env file: use the real environment.
  }
}

export function databaseUrl() {
  loadEnv();
  const url = process.env.DATABASE_URL;
  if (!url) {
    console.error("DATABASE_URL is not set. Copy .env.example to .env and fill it in.");
    process.exit(1);
  }
  return url;
}
