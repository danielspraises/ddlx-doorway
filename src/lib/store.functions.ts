import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import type { Failure, StoreProfile } from "@/lib/types";

type Saved = { ok: true; store: StoreProfile } | Failure;
const fail = (error: string): Failure => ({ ok: false, error });
const EMPTY: StoreProfile = { tagline: "", description: "", phone: "", support_email: "", return_policy: "", shipping_policy: "", logo: null, banner: null, vacation: false, vacation_message: "" };

async function load(sellerId: string): Promise<StoreProfile> {
  const { getSql } = await import("@/server/db.server");
  const rows = await getSql()<StoreProfile[]>`SELECT tagline, description, phone, support_email, return_policy, shipping_policy, logo, banner, vacation, vacation_message FROM store_profiles WHERE seller_id = ${sellerId}`;
  return rows[0] ? { ...rows[0] } : { ...EMPTY };
}

export const getMyStore = createServerFn({ method: "GET" }).handler(async (): Promise<StoreProfile> => {
  const { requireVerifiedSeller } = await import("@/server/session.server");
  return load((await requireVerifiedSeller()).id);
});

// Changing anything the marketplace shows (or vacation mode) makes the seller's products re-sync.
async function touchProducts(sellerId: string) {
  const { getSql } = await import("@/server/db.server");
  await getSql()`UPDATE products SET updated_at = date_trunc('milliseconds', now()) WHERE seller_id = ${sellerId} AND published_snapshot IS NOT NULL`;
}

export const saveStore = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({
    tagline: z.string().trim().max(160),
    description: z.string().trim().max(3000),
    phone: z.string().trim().max(30),
    support_email: z.string().trim().max(255).refine((v) => v === "" || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v), "Enter a valid support email"),
    return_policy: z.string().trim().max(3000),
    shipping_policy: z.string().trim().max(3000),
    vacation: z.boolean(),
    vacation_message: z.string().trim().max(300),
  }).parse(input))
  .handler(async ({ data }): Promise<Saved> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const user = await requireVerifiedSeller();
    await getSql()`
      INSERT INTO store_profiles (seller_id, tagline, description, phone, support_email, return_policy, shipping_policy, vacation, vacation_message, updated_at)
      VALUES (${user.id}, ${data.tagline}, ${data.description}, ${data.phone}, ${data.support_email}, ${data.return_policy}, ${data.shipping_policy}, ${data.vacation}, ${data.vacation_message}, now())
      ON CONFLICT (seller_id) DO UPDATE SET tagline = EXCLUDED.tagline, description = EXCLUDED.description, phone = EXCLUDED.phone,
        support_email = EXCLUDED.support_email, return_policy = EXCLUDED.return_policy, shipping_policy = EXCLUDED.shipping_policy,
        vacation = EXCLUDED.vacation, vacation_message = EXCLUDED.vacation_message, updated_at = now()`;
    await touchProducts(user.id);
    return { ok: true, store: await load(user.id) };
  });

export const uploadStoreImage = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ kind: z.enum(["logo", "banner"]), content_type: z.string().max(60), data_base64: z.string().max(8_000_000) }).parse(input))
  .handler(async ({ data }): Promise<Saved> => {
    const { randomUUID } = await import("node:crypto");
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const storage = await import("@/server/storage.server");
    const user = await requireVerifiedSeller();
    const bytes = Buffer.from(data.data_base64, "base64");
    if (!["image/jpeg", "image/png", "image/webp"].includes(data.content_type) || bytes.length === 0 || bytes.length > 5 * 1024 * 1024 || !storage.matchesDeclaredType(data.content_type, bytes)) {
      return fail("Choose a JPG, PNG or WebP image up to 5 MB.");
    }
    const path = `${user.id}/${randomUUID()}.${storage.EXTENSION[data.content_type] ?? "jpg"}`;
    await storage.saveStoreFile(path, bytes);
    const sql = getSql();
    const old = (await load(user.id))[data.kind];
    await sql`INSERT INTO store_profiles (seller_id) VALUES (${user.id}) ON CONFLICT DO NOTHING`;
    if (data.kind === "logo") await sql`UPDATE store_profiles SET logo = ${path}, updated_at = now() WHERE seller_id = ${user.id}`;
    else await sql`UPDATE store_profiles SET banner = ${path}, updated_at = now() WHERE seller_id = ${user.id}`;
    if (old) await storage.removeStoreFile(old);
    await touchProducts(user.id);
    return { ok: true, store: await load(user.id) };
  });

export const removeStoreImage = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ kind: z.enum(["logo", "banner"]) }).parse(input))
  .handler(async ({ data }): Promise<Saved> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const storage = await import("@/server/storage.server");
    const user = await requireVerifiedSeller();
    const old = (await load(user.id))[data.kind];
    const sql = getSql();
    if (data.kind === "logo") await sql`UPDATE store_profiles SET logo = NULL, updated_at = now() WHERE seller_id = ${user.id}`;
    else await sql`UPDATE store_profiles SET banner = NULL, updated_at = now() WHERE seller_id = ${user.id}`;
    if (old) await storage.removeStoreFile(old);
    await touchProducts(user.id);
    return { ok: true, store: await load(user.id) };
  });

// Sellers see their own images in the editor through this (the public route only serves them to the marketplace).
export const getStoreImage = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ kind: z.enum(["logo", "banner"]) }).parse(input))
  .handler(async ({ data }): Promise<{ ok: true; content_type: string; data_base64: string } | Failure> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const storage = await import("@/server/storage.server");
    const user = await requireVerifiedSeller();
    const path = (await load(user.id))[data.kind];
    const match = path ? storage.STORE_PATH.exec(path) : null;
    if (!path || !match) return fail("No image.");
    try {
      return { ok: true, content_type: storage.CONTENT_TYPE[match[2] ?? ""] ?? "application/octet-stream", data_base64: (await storage.readStoreFile(path)).toString("base64") };
    } catch {
      return fail("The image could not be found.");
    }
  });
