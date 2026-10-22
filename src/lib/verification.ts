import { queryOptions } from "@tanstack/react-query";
import { supabase } from "@/integrations/supabase/client";
import { section } from "@/lib/application-data";
import type { Section } from "@/lib/application";

export const VERIFICATION_STEPS = [
  { title: "Identity", file: "identity", label: "Identity document" },
  { title: "Passport photograph", file: "portrait", label: "Passport photograph" },
  { title: "Business registration", file: "registration", label: "Registration certificate" },
  { title: "Social media", file: "", label: "" },
  { title: "Address", file: "", label: "" },
  { title: "Proof of address", file: "proof_address", label: "Proof of address" },
  { title: "Business presence", file: "presence", label: "Business or product photograph" },
  { title: "Declaration", file: "", label: "" },
] as const;
export const verificationQuery = (userId: string) => queryOptions({
  queryKey: ["verification", userId],
  queryFn: async () => {
    const { data, error } = await supabase.from("seller_verifications").select("*").eq("user_id", userId).maybeSingle();
    if (error) throw error;
    return data;
  },
});
export const verificationReviewsQuery = queryOptions({
  queryKey: ["verification-reviews"],
  queryFn: async () => {
    const { data, error } = await supabase.from("seller_verifications").select("*, seller_applications(brand, personal, business, registration, applicant_email)").neq("status", "draft").order("updated_at", { ascending: false });
    if (error) throw error;
    return data;
  },
});
export function verificationErrors(step: number, details: Section, documents: Section, registered: boolean): Section {
  const file = VERIFICATION_STEPS[step - 1]?.file;
  if (file && (file !== "registration" || registered) && !documents[file]) return { file: "Upload this document before continuing." };
  if (step === 4 && details["social_verified"] !== "yes") return { confirm: "Confirm that your submitted social accounts are current." };
  if (step === 5 && details["address_confirmed"] !== "yes") return { confirm: "Confirm your submitted address." };
  if (step === 7 && !details["business_presence"]?.trim()) return { presence: "Describe your business presence." };
  if (step === 8 && details["declaration"] !== "yes") return { confirm: "Confirm the verification declaration." };
  return {};
}
export async function downloadVerificationFile(path: string) {
  const { data, error } = await supabase.storage.from("seller-verification").download(path);
  if (error) throw error;
  const extension = path.split(".").pop()?.toLowerCase();
  const type = data.type || (extension === "pdf" ? "application/pdf" : extension === "png" ? "image/png" : extension === "webp" ? "image/webp" : "image/jpeg");
  return data.type === type ? data : new Blob([data], { type });
}
export { section };