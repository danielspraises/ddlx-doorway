import { useEffect } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

type Live = { type: "sale" | "refund" | "product" | "review_queue"; message: string };

// One live connection per signed-in browser tab. A new sale, a review decision or a new item to review
// refreshes the data on screen and shows a short message. The browser reconnects on its own if it drops.
export function useLiveEvents(enabled: boolean) {
  const queryClient = useQueryClient();
  useEffect(() => {
    if (!enabled || typeof EventSource === "undefined") return;
    const source = new EventSource("/api/seller/events");
    source.onmessage = (message) => {
      let event: Live;
      try {
        event = JSON.parse(message.data) as Live;
      } catch {
        return;
      }
      const refresh = (...keys: string[]) => keys.forEach((key) => void queryClient.invalidateQueries({ queryKey: [key] }));
      if (event.type === "sale" || event.type === "refund") refresh("sales", "products", "me");
      else if (event.type === "product") refresh("products", "me");
      else refresh("product-reviews", "me");
      if (event.message) (event.type === "sale" ? toast.success : toast)(event.message);
    };
    return () => source.close();
  }, [enabled, queryClient]);
}
