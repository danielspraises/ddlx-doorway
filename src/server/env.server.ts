import { readFileSync } from "node:fs";
import { resolve } from "node:path";

let loaded = false;

// Vite only exposes VITE_* variables to client code, so server code reads .env itself.
// Real environment variables always win over the file.
export function loadEnv() {
  if (loaded) return;
  loaded = true;
  try {
    const text = readFileSync(resolve(process.cwd(), ".env"), "utf8");
    for (const line of text.split(/\r?\n/)) {
      const match = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/);
      const key = match?.[1];
      let value = match?.[2];
      if (!key || value === undefined || line.trimStart().startsWith("#")) continue;
      if (
        value.length >= 2 &&
        ((value.startsWith('"') && value.endsWith('"')) ||
          (value.startsWith("'") && value.endsWith("'")))
      ) {
        value = value.slice(1, -1);
      }
      if (process.env[key] === undefined) process.env[key] = value;
    }
  } catch {
    // No .env file: rely on the real environment.
  }
}

export function env(name: string, fallback?: string): string {
  loadEnv();
  const value = process.env[name] ?? fallback;
  if (value === undefined || value === "") {
    throw new Error(`Missing environment variable ${name}. Copy .env.example to .env and fill it in.`);
  }
  return value;
}
