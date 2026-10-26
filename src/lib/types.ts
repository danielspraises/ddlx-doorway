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

export type ProductStatus = "draft" | "pending_review" | "changes_requested" | "rejected" | "approved";
export type Currency = "NGN" | "USD" | "GBP" | "EUR";
export type Attribute = { name: string; values: string[] };

export type Variation = {
  id: string;
  sku: string;
  options: Record<string, string>;
  regular_price: number | null;
  sale_price: number | null;
  sale_starts_at: string | null;
  sale_ends_at: string | null;
  stock: number;
  image: string | null;
  enabled: boolean;
  position: number;
};

export type Product = {
  id: string;
  seller_id: string;
  name: string;
  slug: string;
  product_type: "simple" | "variable";
  sku: string;
  brand: string;
  category: string;
  category_id: string | null;
  // Names from the top of the tree down to the chosen category.
  category_path: string[];
  tags: string[];
  short_description: string;
  description: string;
  currency: Currency;
  regular_price: number | null;
  sale_price: number | null;
  sale_starts_at: string | null;
  sale_ends_at: string | null;
  manage_stock: boolean;
  stock: number;
  low_stock_threshold: number;
  allow_backorders: boolean;
  weight_kg: number | null;
  length_cm: number | null;
  width_cm: number | null;
  height_cm: number | null;
  attributes: Attribute[];
  featured_image: string | null;
  gallery: string[];
  status: ProductStatus;
  // true while the marketplace is showing the last approved version
  live: boolean;
  admin_comment: string | null;
  submitted_at: string | null;
  reviewed_at: string | null;
  published_at: string | null;
  created_at: string;
  updated_at: string;
  variations: Variation[];
};

export type Category = { id: string; parent_id: string | null; name: string; position: number; active: boolean; product_count: number };

export type ProductReviewItem = Product & { store_name: string; seller_email: string };

export type SaleItem = {
  id: string;
  order_number: string;
  placed_at: string;
  name: string;
  sku: string;
  options: Record<string, string>;
  quantity: number;
  unit_price: number;
  line_total: number;
  commission: number;
  net: number;
  currency: Currency;
  status: "paid" | "refunded";
  customer_name: string;
  ship_city: string;
  ship_state: string;
};

export type Balance = { currency: Currency; available: number; pending: number; total: number };
export type SalesOverview = {
  balances: Balance[];
  sales_30d: { currency: Currency; gross: number; net: number; units: number }[];
  commission_percent: number;
  hold_days: number;
};

export type StaffMember = { user_id: string; email: string; permissions: string[]; active: boolean; created_at: string };
