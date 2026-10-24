export type Json = string | number | boolean | null | Json[] | { [key: string]: Json };

export type Status = "draft" | "submitted" | "needs_corrections" | "approved" | "rejected";

export type Application = {
  id: string;
  user_id: string;
  status: Status;
  current_step: number;
  personal: Json;
  business: Json;
  brand: Json;
  registration: Json;
  declaration: Json;
  applicant_email: string | null;
  admin_comment: string | null;
  reviewed_by: string | null;
  reviewed_at: string | null;
  submitted_at: string | null;
  created_at: string;
  updated_at: string;
};

export type Verification = {
  id: string;
  application_id: string;
  user_id: string;
  status: Status;
  current_step: number;
  details: Json;
  documents: Json;
  admin_comment: string | null;
  reviewed_by: string | null;
  reviewed_at: string | null;
  submitted_at: string | null;
  created_at: string;
  updated_at: string;
};

export type VerificationReviewItem = Verification & {
  seller_applications: Pick<Application, "brand" | "personal" | "business" | "registration" | "applicant_email"> | null;
};

export type Failure = { ok: false; error: string; code?: string };

export type Product = {
  id: string;
  user_id: string;
  name: string;
  description: string;
  sku: string;
  category: string;
  price: number;
  currency: "NGN" | "USD" | "GBP" | "EUR";
  stock: number;
  low_stock_threshold: number;
  status: "draft" | "ready" | "archived";
  images: string[];
  created_at: string;
  updated_at: string;
};
