import { createFileRoute } from "@tanstack/react-router";

// The marketplace reports paid and refunded orders here. Requests are signed (see docs/marketplace-api.md).
export const Route = createFileRoute("/api/marketplace/orders")({
  server: {
    handlers: {
      POST: async ({ request }: { request: Request }) => {
        const market = await import("@/server/marketplace.server");
        const raw = await request.text();
        if (raw.length > 1_000_000) return market.json({ error: "Payload too large." }, 413);
        const denied = market.checkSignature(request, raw);
        if (denied) return denied;
        let parsed;
        try {
          parsed = market.parseOrderEvent(raw);
        } catch {
          return market.json({ error: "The body must be valid JSON." }, 400);
        }
        if (!parsed.success) {
          return market.json({ error: "Invalid order payload.", issues: parsed.error.issues.map((i) => `${i.path.join(".")}: ${i.message}`) }, 400);
        }
        try {
          if (parsed.data.event === "order.paid") {
            const result = await market.recordPaidOrder(parsed.data);
            return market.json({ ok: true, duplicate: result.duplicate });
          }
          const result = await market.recordRefund(parsed.data);
          return result.found ? market.json({ ok: true }) : market.json({ error: "Unknown order." }, 404);
        } catch (error) {
          if (error instanceof market.Rejected) return market.json({ error: error.message }, 422);
          console.error("[marketplace] order failed", error);
          return market.json({ error: "Could not record the order." }, 500);
        }
      },
    },
  },
});
