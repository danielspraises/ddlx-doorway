import { queryOptions } from "@tanstack/react-query";
import { supabase } from "@/integrations/supabase/client";
import type { Tables, Json } from "@/integrations/supabase/types";
import type { Section } from "@/lib/application";

export type Application = Tables<"seller_applications">;
export function section(value: Json): Section {
  if (!value || typeof value !== "object" || Array.isArray(value)) return {};
  return Object.fromEntries(Object.entries(value).filter((entry): entry is [string, string] => typeof entry[1] === "string"));
}
export const applicationQuery = (userId: string) => queryOptions({
  queryKey: ["application", userId],
  queryFn: async () => {
    const { data, error } = await supabase.from("seller_applications").select("*").eq("user_id", userId).maybeSingle();
    if (error) throw error;
    return data;
  },
});
export const reviewsQuery = queryOptions({
  queryKey: ["application-reviews"],
  queryFn: async () => {
    const { data, error } = await supabase.from("seller_applications").select("*").neq("status", "draft").order("updated_at", { ascending: false });
    if (error) throw error;
    return data;
  },
});