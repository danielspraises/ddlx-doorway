import type { Status } from "@/lib/types";

// Where a seller is on the journey: application -> review -> verification -> review -> seller center.
export type SellerStage =
  | "no_application"
  | "application_draft"
  | "application_review"
  | "application_corrections"
  | "application_rejected"
  | "verification_needed"
  | "verification_draft"
  | "verification_review"
  | "verification_corrections"
  | "verification_rejected"
  | "verified";

export function sellerStage(application: Status | null, verification: Status | null): SellerStage {
  if (!application) return "no_application";
  if (application === "draft") return "application_draft";
  if (application === "submitted") return "application_review";
  if (application === "needs_corrections") return "application_corrections";
  if (application === "rejected") return "application_rejected";
  if (!verification) return "verification_needed";
  if (verification === "draft") return "verification_draft";
  if (verification === "submitted") return "verification_review";
  if (verification === "needs_corrections") return "verification_corrections";
  if (verification === "rejected") return "verification_rejected";
  return "verified";
}

export const STAGE_LABEL: Record<SellerStage, string> = {
  no_application: "Not started",
  application_draft: "Application in progress",
  application_review: "Application under review",
  application_corrections: "Application needs corrections",
  application_rejected: "Application not approved",
  verification_needed: "Ready for verification",
  verification_draft: "Verification in progress",
  verification_review: "Verification under review",
  verification_corrections: "Verification needs corrections",
  verification_rejected: "Verification not approved",
  verified: "Verified seller",
};

export type HomeInfo = { isAdmin: boolean; stage: SellerStage };
export type HomePath = "/admin" | "/apply" | "/verification" | "/seller";

// The page a person should land on after signing in or finishing an action.
export function homePath(user: HomeInfo): HomePath {
  if (user.isAdmin) return "/admin";
  if (user.stage === "verified") return "/seller";
  if (user.stage.startsWith("verification_")) return "/verification";
  return "/apply";
}

export const applicationApproved = (stage: SellerStage) => stage.startsWith("verification_") || stage === "verified";
