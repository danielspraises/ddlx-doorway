import { queryOptions } from "@tanstack/react-query";
import { getCurrentUser } from "@/lib/auth.functions";

// The signed-in person (or null), including their role and seller stage.
// Invalidate ["me"] after anything that can change it: submitting, approving, signing in or out.
export const meQuery = queryOptions({
  queryKey: ["me"],
  queryFn: () => getCurrentUser(),
  staleTime: 15_000,
});
