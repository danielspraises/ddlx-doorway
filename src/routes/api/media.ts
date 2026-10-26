import { createFileRoute } from "@tanstack/react-router";

// Public product photos for the marketplace. Only photos of live (approved) products are served.
export const Route = createFileRoute("/api/media")({
  server: {
    handlers: {
      GET: async ({ request }: { request: Request }) => {
        const storage = await import("@/server/storage.server");
        const { getSql } = await import("@/server/db.server");
        const path = new URL(request.url).searchParams.get("p") ?? "";
        const notFound = () => new Response("Not found", { status: 404 });
        const store = storage.STORE_PATH.exec(path);
        if (store) {
          const found = await getSql()`SELECT 1 FROM store_profiles WHERE logo = ${path} OR banner = ${path} LIMIT 1`;
          if (found.length === 0) return notFound();
          try {
            const bytes = await storage.readStoreFile(path);
            return new Response(new Uint8Array(bytes), { headers: { "content-type": storage.CONTENT_TYPE[store[2] ?? ""] ?? "application/octet-stream", "cache-control": "public, max-age=3600" } });
          } catch {
            return notFound();
          }
        }
        const match = storage.PRODUCT_PATH.exec(path);
        if (!match) return notFound();
        const live = await getSql()`SELECT 1 FROM products WHERE live AND published_snapshot->'image_paths' @> to_jsonb(${path}::text) LIMIT 1`;
        if (live.length === 0) return notFound();
        try {
          const bytes = await storage.readProductFile(path);
          return new Response(new Uint8Array(bytes), {
            headers: { "content-type": storage.CONTENT_TYPE[match[3] ?? ""] ?? "application/octet-stream", "cache-control": "public, max-age=86400" },
          });
        } catch {
          return notFound();
        }
      },
    },
  },
});
