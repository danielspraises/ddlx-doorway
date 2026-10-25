import { createFileRoute } from "@tanstack/react-router";

// The marketplace pulls approved products from here. Authenticated with `Authorization: Bearer <MARKETPLACE_API_KEY>`.
export const Route = createFileRoute("/api/marketplace/products")({
  server: {
    handlers: {
      GET: async ({ request }: { request: Request }) => {
        const market = await import("@/server/marketplace.server");
        const denied = market.checkApiKey(request);
        if (denied) return denied;
        const params = new URL(request.url).searchParams;
        const since = params.get("updated_since");
        if (since && Number.isNaN(Date.parse(since))) return market.json({ error: "updated_since must be an ISO date." }, 400);
        const limit = Math.min(500, Math.max(1, Number(params.get("limit") ?? 100) || 100));
        return market.json(await market.catalogue(request, since ? new Date(since).toISOString() : null, limit));
      },
    },
  },
});
