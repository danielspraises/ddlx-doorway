import { queryOptions } from "@tanstack/react-query";
import { getMyStore } from "@/lib/store.functions";

export const storeQuery = queryOptions({ queryKey: ["store"], queryFn: () => getMyStore() });
