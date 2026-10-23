import { queryOptions } from "@tanstack/react-query";
import { getMyApplication, listApplicationReviews } from "@/lib/applications.functions";
import type { Application, Json } from "@/lib/types";
import type { Section } from "@/lib/application";

export type { Application, Json };
export function section(value: Json): Section {
  if (!value || typeof value !== "object" || Array.isArray(value)) return {};
  return Object.fromEntries(Object.entries(value).filter((entry): entry is [string, string] => typeof entry[1] === "string"));
}
export const applicationQuery = (userId: string) => queryOptions({
  queryKey: ["application", userId],
  queryFn: () => getMyApplication(),
});
export const reviewsQuery = queryOptions({
  queryKey: ["application-reviews"],
  queryFn: () => listApplicationReviews(),
});
