import { queryOptions } from "@tanstack/react-query";
import { section } from "@/lib/application-data";
import { base64ToBlob, fileToBase64 } from "@/lib/files";
import { getMyVerification, getVerificationFile, listVerificationReviews } from "@/lib/verification.functions";
import type { Section } from "@/lib/application";

export type StepGuide = {
  // One line saying what this step is for.
  lead: string;
  // What counts as a valid upload.
  accepted?: readonly string[];
  // How to make sure it is approved first time.
  tips?: readonly string[];
};

export const VERIFICATION_STEPS = [
  {
    title: "Identity", file: "identity", label: "Government-issued ID",
    guide: {
      lead: "We need to confirm that you are the person named on your application.",
      accepted: ["National ID card", "International passport (photo page)", "Driver's licence", "Voter's card"],
      tips: ["The name must match the name on your application.", "All four corners are visible and the text is sharp, with no glare.", "If the card has details on the back, put both sides in one PDF or image."],
    },
  },
  {
    title: "Passport photograph", file: "portrait", label: "Your photograph",
    guide: {
      lead: "A recent photo of you, so we can match your face to your ID.",
      accepted: ["A clear, recent photo of your face and shoulders"],
      tips: ["Face the camera in good light, against a plain background.", "No sunglasses, hat, filters or other people in the photo."],
    },
  },
  {
    title: "Business registration", file: "registration", label: "Registration certificate",
    guide: {
      lead: "Proof that your business is registered. Only needed if you said it is registered.",
      accepted: ["CAC certificate of incorporation", "CAC business name registration certificate"],
      tips: ["The business name must match the one on your application.", "Upload the whole page, with the registration (RC or BN) number readable."],
    },
  },
  { title: "Social media", file: "", label: "", guide: { lead: "Check that the social accounts you gave are correct. Nothing to upload." } },
  { title: "Address", file: "", label: "", guide: { lead: "Check that the business address you gave is correct. Nothing to upload." } },
  {
    title: "Proof of address", file: "proof_address", label: "Proof of address",
    guide: {
      lead: "A document that links you or your business to the address from the previous step.",
      accepted: ["Utility bill (electricity, water, waste)", "Bank statement", "Tenancy or lease agreement", "Official letter from a government body"],
      tips: ["It must be dated within the last 3 months, unless it is a tenancy agreement.", "It must show your name or your business name and the same address you gave us."],
    },
  },
  {
    title: "Business presence", file: "presence", label: "Photo of your business or products",
    guide: {
      lead: "A photo that shows your business is real and that you make or sell what you plan to list.",
      accepted: ["Your shop or stall front", "Your workshop or studio", "Your products, with your brand name, label or packaging visible"],
      tips: ["Take the photo yourself, in good light.", "Do not use pictures from other websites or stock images."],
    },
  },
  { title: "Declaration", file: "", label: "", guide: { lead: "Review your documents, then confirm and submit. You can open each one to check it." } },
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
  return base64ToBlob(result.data_base64, result.content_type);
}
export { fileToBase64 };
export { section };