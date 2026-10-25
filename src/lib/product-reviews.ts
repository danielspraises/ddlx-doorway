import { queryOptions } from "@tanstack/react-query";
import { listProductReviews } from "@/lib/product-review.functions";
import { listStaff } from "@/lib/staff.functions";

export const productReviewsQuery = queryOptions({ queryKey: ["product-reviews"], queryFn: () => listProductReviews() });
export const staffQuery = queryOptions({ queryKey: ["staff"], queryFn: () => listStaff() });
