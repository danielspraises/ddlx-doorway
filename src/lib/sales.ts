import { queryOptions } from "@tanstack/react-query";
import { getSalesOverview, listMySales } from "@/lib/sales.functions";

export const salesOverviewQuery = queryOptions({ queryKey: ["sales", "overview"], queryFn: () => getSalesOverview() });
export const salesQuery = queryOptions({ queryKey: ["sales", "items"], queryFn: () => listMySales() });
