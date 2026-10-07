import { z } from "zod";

export const NIGERIAN_STATES = [
  "Abia","Adamawa","Akwa Ibom","Anambra","Bauchi","Bayelsa","Benue","Borno","Cross River","Delta",
  "Ebonyi","Edo","Ekiti","Enugu","FCT - Abuja","Gombe","Imo","Jigawa","Kaduna","Kano","Katsina",
  "Kebbi","Kogi","Kwara","Lagos","Nasarawa","Niger","Ogun","Ondo","Osun","Oyo","Plateau","Rivers",
  "Sokoto","Taraba","Yobe","Zamfara",
];
export const COUNTRIES = ["Nigeria", "Ghana", "Kenya", "South Africa", "United Kingdom", "United States", "Other"];
export const ROLES = ["Owner", "Director", "Manager", "Authorised Representative"];
export const ID_TYPES = ["National Identity Number (NIN)", "International Passport", "Driver's Licence", "Voter's Card"];
export const BUSINESS_TYPES = ["Individual / Sole proprietorship", "Partnership", "Other"];
export const CATEGORIES = ["Clothing", "Footwear", "Accessories", "Others"];
export const SOCIALS = [
  { key: "instagram", label: "Instagram" },
  { key: "facebook", label: "Facebook" },
  { key: "tiktok", label: "TikTok" },
  { key: "x", label: "X / Twitter" },
  { key: "whatsapp", label: "WhatsApp Business" },
  { key: "snapchat", label: "Snapchat" },
  { key: "other", label: "Other social media" },
] as const;

export const STEPS = [
  { n: 1, key: "personal", title: "Personal Information" },
  { n: 2, key: "business", title: "Business Information" },
  { n: 3, key: "brand", title: "Brand & Store" },
  { n: 4, key: "registration", title: "Business Registration" },
  { n: 5, key: "review", title: "Review & Submit" },
] as const;

export type Section = Record<string, string>;

const req = (label: string, max = 200) => z.string().trim().min(1, `${label} is required`).max(max);
const opt = (max = 200) => z.string().trim().max(max).optional().or(z.literal(""));

export function normalizePhone(v: string) {
  return v.replace(/[\s\-()]/g, "");
}
export const isNigerianPhone = (v: string) => /^(\+?234|0)[789][01]\d{8}$/.test(normalizePhone(v));

const nonNeg = (label: string) =>
  z.string().trim().optional().or(z.literal("")).refine((v) => !v || (/^\d+$/.test(v) && Number(v) >= 0), `${label} must be 0 or more`);

export const personalSchema = z.object({
  first_name: req("First name", 100),
  last_name: req("Last name", 100),
  other_names: opt(100),
  email: z.string().trim().email("Enter a valid email address").max(255),
  phone: req("Phone number", 20).refine(isNigerianPhone, "Enter a valid Nigerian phone number, e.g. 0803 123 4567"),
  address: req("Residential address", 500),
  city: req("City", 100),
  state: req("State", 50),
  country: req("Country", 50),
  role: req("Applicant's role", 50),
  id_type: req("ID type", 50),
  id_number: req("ID number", 50),
});

export const businessSchema = z
  .object({
    name: req("Business name"),
    type: req("Business type", 50),
    type_other: opt(100),
    category: req("Business category", 50),
    category_other: opt(100),
    phone: opt(20).refine((v) => !v || isNigerianPhone(v), "Enter a valid Nigerian phone number"),
    email: opt(255).refine((v) => !v || z.string().email().safeParse(v).success, "Enter a valid email"),
    address: req("Business address", 500),
    city: req("City", 100),
    state: req("State", 50),
    country: req("Country", 50),
    website: opt(255).refine((v) => !v || /^https?:\/\/[^\s.]+\.[^\s]+$/i.test(v), "Enter a valid URL starting with http:// or https://"),
    years: nonNeg("Years in business"),
    employees: nonNeg("Number of employees"),
    description: req("Business description", 2000),
  })
  .superRefine((d, ctx) => {
    if (d.type === "Other" && !d.type_other?.trim())
      ctx.addIssue({ code: "custom", path: ["type_other"], message: "Please specify the business type" });
    if (d.category === "Others" && !d.category_other?.trim())
      ctx.addIssue({ code: "custom", path: ["category_other"], message: "Please specify the category" });
  });

export const brandSchema = z
  .object({
    store_name: req("Store name"),
    instagram: opt(), facebook: opt(), tiktok: opt(), x: opt(), whatsapp: opt(), snapchat: opt(), other: opt(),
    owns_brand: z.enum(["yes", "no"], { message: "Please choose Yes or No" }),
    brand_name: opt(),
    brand_category: opt(50),
    brand_category_other: opt(100),
    brand_description: opt(2000),
  })
  .superRefine((d, ctx) => {
    const count = SOCIALS.filter((s) => (d as Record<string, unknown>)[s.key] && String((d as Record<string, unknown>)[s.key]).trim()).length;
    if (count < 2) ctx.addIssue({ code: "custom", path: ["socials"], message: "Please provide at least 2 social media accounts" });
    if (d.owns_brand === "yes") {
      if (!d.brand_name?.trim()) ctx.addIssue({ code: "custom", path: ["brand_name"], message: "Brand name is required" });
      if (!d.brand_category?.trim()) ctx.addIssue({ code: "custom", path: ["brand_category"], message: "Brand category is required" });
      if (d.brand_category === "Others" && !d.brand_category_other?.trim())
        ctx.addIssue({ code: "custom", path: ["brand_category_other"], message: "Please specify the brand category" });
      if (!d.brand_description?.trim()) ctx.addIssue({ code: "custom", path: ["brand_description"], message: "Brand description is required" });
    }
  });

export const registrationSchema = z
  .object({
    registered: z.enum(["yes", "no"], { message: "Please choose Yes or No" }),
    reg_number: opt(50),
    reg_name: opt(),
  })
  .superRefine((d, ctx) => {
    if (d.registered === "yes") {
      if (!d.reg_number?.trim()) ctx.addIssue({ code: "custom", path: ["reg_number"], message: "Registration number is required" });
      if (!d.reg_name?.trim()) ctx.addIssue({ code: "custom", path: ["reg_name"], message: "Registered business name is required" });
    }
  });

export const SCHEMAS = { 1: personalSchema, 2: businessSchema, 3: brandSchema, 4: registrationSchema } as const;

export function validate(step: 1 | 2 | 3 | 4, data: Section): Record<string, string> {
  const r = SCHEMAS[step].safeParse(data);
  if (r.success) return {};
  const errs: Record<string, string> = {};
  for (const i of r.error.issues) {
    const k = String(i.path[0] ?? "form");
    if (!errs[k]) errs[k] = i.message;
  }
  return errs;
}

export const STATUS_LABEL: Record<string, string> = {
  draft: "Draft",
  submitted: "Under review",
  needs_corrections: "Needs corrections",
  approved: "Approved",
  rejected: "Rejected",
};

/** Human-readable summary rows for review screens. */
export function summaryRows(step: 1 | 2 | 3 | 4, d: Section): [string, string][] {
  const v = (k: string) => d[k] || "—";
  if (step === 1)
    return [
      ["First name", v("first_name")], ["Last name", v("last_name")], ["Other name(s)", v("other_names")],
      ["Email", v("email")], ["Phone", v("phone")], ["Residential address", v("address")],
      ["City", v("city")], ["State", v("state")], ["Country", v("country")],
      ["Role", v("role")], ["ID type", v("id_type")], ["ID number", v("id_number")],
    ];
  if (step === 2)
    return [
      ["Business name", v("name")],
      ["Business type", d.type === "Other" ? `Other — ${v("type_other")}` : v("type")],
      ["Category", d.category === "Others" ? `Others — ${v("category_other")}` : v("category")],
      ["Business phone", v("phone")], ["Business email", v("email")], ["Address", v("address")],
      ["City", v("city")], ["State", v("state")], ["Country", v("country")], ["Website", v("website")],
      ["Years in business", v("years")], ["Employees", v("employees")], ["Description", v("description")],
    ];
  if (step === 3) {
    const rows: [string, string][] = [["Store name", v("store_name")]];
    SOCIALS.forEach((s) => d[s.key] && rows.push([s.label, d[s.key]]));
    rows.push(["Owns a brand", d.owns_brand === "yes" ? "Yes" : d.owns_brand === "no" ? "No" : "—"]);
    if (d.owns_brand === "yes")
      rows.push(
        ["Brand name", v("brand_name")],
        ["Brand category", d.brand_category === "Others" ? `Others — ${v("brand_category_other")}` : v("brand_category")],
        ["Brand description", v("brand_description")],
      );
    return rows;
  }
  const rows: [string, string][] = [["Registered", d.registered === "yes" ? "Yes" : d.registered === "no" ? "No" : "—"]];
  if (d.registered === "yes") rows.push(["Registration number", v("reg_number")], ["Registered name", v("reg_name")]);
  return rows;
}
