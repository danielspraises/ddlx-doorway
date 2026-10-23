import { queryOptions } from "@tanstack/react-query";
import { section } from "@/lib/application-data";
import { getMyVerification, getVerificationFile, listVerificationReviews } from "@/lib/verification.functions";
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
  queryFn: () => getMyVerification(),
});
export const verificationReviewsQuery = queryOptions({
  queryKey: ["verification-reviews"],
  queryFn: () => listVerificationReviews(),
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
  const result = await getVerificationFile({ data: { path } });
  if (!result.ok) throw new Error(result.error);
  const binary = atob(result.data_base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
  return new Blob([bytes], { type: result.content_type });
}
export function fileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(reader.error ?? new Error("Could not read the file."));
    reader.onload = () => resolve(String(reader.result).split(",")[1] ?? "");
    reader.readAsDataURL(file);
  });
}
export { section };