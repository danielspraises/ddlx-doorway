import { loadEnv } from "./env.server";

// SKUs must be unique across the whole marketplace store, not just per seller. The Vendor Hub Connector plugin
// exposes two small endpoints on the WordPress site (reached with the same API key) so Vendor Hub can ask for the
// next free SKU and check for clashes before a product is saved. Set MARKETPLACE_URL to turn this on.
function config() {
  loadEnv();
  const url = (process.env["MARKETPLACE_URL"] ?? "").replace(/\/$/, "");
  const key = process.env["MARKETPLACE_API_KEY"] ?? "";
  return url && key ? { url, key } : null;
}

export const marketplaceSkuEnabled = () => config() !== null;

async function call(path: string, init: { method: "GET" | "POST"; body?: string }): Promise<unknown> {
  const c = config();
  if (!c) return null;
  try {
    const response = await fetch(`${c.url}/wp-json/vendor-hub/v1${path}`, {
      method: init.method,
      ...(init.body !== undefined ? { body: init.body } : {}),
      headers: { authorization: `Bearer ${c.key}`, "content-type": "application/json" },
      signal: AbortSignal.timeout(8000),
    });
    return response.ok ? await response.json() : null;
  } catch {
    // The marketplace being slow or offline must never stop a seller from working.
    return null;
  }
}

export async function marketplaceNextSku(prefix: string): Promise<string | null> {
  const data = (await call(`/sku/next?prefix=${encodeURIComponent(prefix)}`, { method: "GET" })) as { sku?: unknown } | null;
  return typeof data?.sku === "string" && data.sku ? data.sku : null;
}

export type SkuCheck = { sku: string; product_id?: string | null; variation_id?: string | null };

// Returns the SKUs already taken by something else on the marketplace, or null when it could not be asked.
export async function marketplaceSkuConflicts(items: SkuCheck[]): Promise<string[] | null> {
  if (items.length === 0) return [];
  const data = (await call("/sku/check", { method: "POST", body: JSON.stringify({ items }) })) as { conflicts?: unknown } | null;
  return Array.isArray(data?.conflicts) ? data.conflicts.filter((x): x is string => typeof x === "string") : null;
}
