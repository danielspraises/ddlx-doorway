import { getSql } from "./db.server";

// Live updates: a change is announced with Postgres NOTIFY, and every open browser
// connection (see /api/seller/events) hears about the ones meant for it.
export type LiveEvent = {
  audience: "seller" | "reviewers" | "admins";
  seller_id?: string;
  type: "sale" | "refund" | "product" | "review_queue" | "payout";
  message?: string;
};

const CHANNEL = "verola_events";

export async function announce(event: LiveEvent): Promise<void> {
  try {
    await getSql()`SELECT pg_notify(${CHANNEL}, ${JSON.stringify(event)})`;
  } catch (error) {
    // Live updates are a convenience; never fail the real action because of them.
    console.error("[events] could not announce", error);
  }
}

type Listener = (event: LiveEvent) => void;
const hub = globalThis as unknown as { __verolaListeners?: Set<Listener>; __verolaListening?: boolean };

export async function subscribe(listener: Listener): Promise<() => void> {
  const listeners = (hub.__verolaListeners ??= new Set<Listener>());
  listeners.add(listener);
  if (!hub.__verolaListening) {
    hub.__verolaListening = true;
    try {
      await getSql().listen(CHANNEL, (payload) => {
        try {
          const event = JSON.parse(payload) as LiveEvent;
          for (const each of listeners) each(event);
        } catch {
          // ignore malformed payloads
        }
      });
    } catch (error) {
      hub.__verolaListening = false;
      console.error("[events] could not listen", error);
    }
  }
  return () => {
    listeners.delete(listener);
  };
}
