import { createFileRoute, Outlet, redirect } from "@tanstack/react-router";
import { meQuery } from "@/lib/auth-data";

export const Route = createFileRoute("/_authenticated")({
  ssr: false,
  beforeLoad: async ({ context }) => {
    // Always ask the server: role and stage can change between page visits.
    const user = await context.queryClient.fetchQuery({ ...meQuery, staleTime: 0 });
    if (!user) throw redirect({ to: "/auth" });
    return { user };
  },
  component: () => <Outlet />,
});
