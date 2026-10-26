import { queryOptions } from "@tanstack/react-query";
import { getPayouts, listWithdrawalsForReview } from "@/lib/payouts.functions";

export const payoutsQuery = queryOptions({ queryKey: ["payouts", "mine"], queryFn: () => getPayouts() });
export const withdrawalReviewsQuery = queryOptions({ queryKey: ["payouts", "review"], queryFn: () => listWithdrawalsForReview() });

export const WITHDRAWAL_LABEL = { requested: "Waiting to be paid", paid: "Paid", rejected: "Rejected", cancelled: "Cancelled" } as const;
