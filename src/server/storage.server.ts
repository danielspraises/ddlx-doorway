import { mkdir, readFile, rm, writeFile } from "node:fs/promises";
import { dirname, resolve, sep } from "node:path";
import { env } from "./env.server";

// Private documents live on local disk, outside the web root. They are only ever served
// through server functions that check ownership or staff access first.
const root = () => resolve(process.cwd(), env("STORAGE_DIR", "./storage"));

const UUID = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}";
export const VERIFICATION_PATH = new RegExp(`^(${UUID})/(${UUID})/[a-z_]+-${UUID}\\.(jpg|png|webp|pdf)$`);

type Area = "verification" | "products";

function fullPath(area: Area, relative: string) {
  const base = resolve(root(), area);
  const target = resolve(base, relative);
  if (!target.startsWith(base + sep)) throw new Error("Invalid file path.");
  return target;
}

async function save(area: Area, relative: string, bytes: Buffer) {
  const target = fullPath(area, relative);
  await mkdir(dirname(target), { recursive: true });
  await writeFile(target, bytes);
}

export const saveVerificationFile = (relative: string, bytes: Buffer) => save("verification", relative, bytes);
export const readVerificationFile = (relative: string) => readFile(fullPath("verification", relative));
export const removeVerificationFile = (relative: string) => rm(fullPath("verification", relative), { force: true });

// Product photos: <seller id>/<product id>/<image id>.<ext>
export const PRODUCT_PATH = new RegExp(`^(${UUID})/(${UUID})/${UUID}\\.(jpg|png|webp)$`);
export const saveProductFile = (relative: string, bytes: Buffer) => save("products", relative, bytes);
export const readProductFile = (relative: string) => readFile(fullPath("products", relative));
export const removeProductFile = (relative: string) => rm(fullPath("products", relative), { force: true });

const SIGNATURES: Record<string, (b: Buffer) => boolean> = {
  "image/jpeg": (b) => b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff,
  "image/png": (b) => b.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])),
  "image/webp": (b) => b.subarray(0, 4).toString("latin1") === "RIFF" && b.subarray(8, 12).toString("latin1") === "WEBP",
  "application/pdf": (b) => b.subarray(0, 5).toString("latin1") === "%PDF-",
};

// The browser-reported type is not trusted: the file's own first bytes must match it.
export function matchesDeclaredType(contentType: string, bytes: Buffer) {
  return SIGNATURES[contentType]?.(bytes) ?? false;
}

export const EXTENSION: Record<string, string> = {
  "image/jpeg": "jpg",
  "image/png": "png",
  "image/webp": "webp",
  "application/pdf": "pdf",
};
export const CONTENT_TYPE: Record<string, string> = { jpg: "image/jpeg", png: "image/png", webp: "image/webp", pdf: "application/pdf" };
