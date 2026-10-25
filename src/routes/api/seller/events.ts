import { createFileRoute } from "@tanstack/react-router";

// Live updates for the signed-in person (new sales, review decisions, new items to review) as server-sent events.
export const Route = createFileRoute("/api/seller/events")({
  server: {
    handlers: {
      GET: async ({ request }: { request: Request }) => {
        const { userFromToken } = await import("@/server/session.server");
        const { subscribe } = await import("@/server/events.server");
        const token = /(?:^|;\s*)ddlx_session=([^;]+)/.exec(request.headers.get("cookie") ?? "")?.[1];
        const user = token ? await userFromToken(decodeURIComponent(token)) : null;
        if (!user) return new Response("Unauthorized", { status: 401 });

        const encoder = new TextEncoder();
        let stop = () => {};
        const stream = new ReadableStream<Uint8Array>({
          start: async (controller) => {
            const send = (text: string) => {
              try {
                controller.enqueue(encoder.encode(text));
              } catch {
                stop();
              }
            };
            send("retry: 5000\n\n");
            const unsubscribe = await subscribe((event) => {
              const mine = (event.audience === "seller" && event.seller_id === user.id) || (event.audience === "reviewers" && user.canReviewProducts);
              if (mine) send(`data: ${JSON.stringify({ type: event.type, message: event.message ?? "" })}\n\n`);
            });
            const beat = setInterval(() => send(": keep-alive\n\n"), 25_000);
            stop = () => {
              clearInterval(beat);
              unsubscribe();
              try {
                controller.close();
              } catch {
                // already closed
              }
            };
            request.signal.addEventListener("abort", () => stop());
          },
          cancel: () => stop(),
        });
        return new Response(stream, {
          headers: { "content-type": "text/event-stream", "cache-control": "no-cache, no-transform", "x-accel-buffering": "no" },
        });
      },
    },
  },
});
