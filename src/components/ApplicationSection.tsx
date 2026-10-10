import { TextField, SelectField, YesNoField } from "@/components/FormFields";
import { BUSINESS_TYPES, CATEGORIES, COUNTRIES, ID_TYPES, NIGERIAN_STATES, ROLES, SOCIALS, type Section } from "@/lib/application";

type Props = { step: number; data: Section; errors: Section; onChange: (name: string, value: string) => void };
export function ApplicationSection({ step, data, errors, onChange }: Props) {
  const field = (name: string, label: string, required = true, type = "text", multiline = false) => <TextField key={name} name={name} label={label} required={required} type={type} multiline={multiline} value={data[name] ?? ""} onChange={v => onChange(name, v)} error={errors[name] ?? ""} className={multiline ? "sm:col-span-2" : ""} />;
  const select = (name: string, label: string, options: readonly string[]) => <SelectField key={name} name={name} label={label} required options={options} value={data[name] ?? ""} onChange={v => onChange(name, v)} error={errors[name] ?? ""} />;
  const yesNo = (name: string, label: string) => <YesNoField name={name} label={label} required value={data[name] ?? ""} onChange={v => onChange(name, v)} error={errors[name] ?? ""} className="sm:col-span-2" />;
  const address = (label: string) => <>{field("address", label, true, "text", true)}{field("city", "City")}{data["country"] === "Nigeria" ? select("state", "State", NIGERIAN_STATES) : field("state", "State / Region")}{select("country", "Country", COUNTRIES)}</>;
  return <div className="grid gap-x-6 gap-y-6 sm:grid-cols-2">
    {step === 1 && <>
      {field("first_name", "First name")}{field("last_name", "Last name")}{field("other_names", "Other name(s)", false)}{field("email", "Email address", true, "email")}{field("phone", "Phone number", true, "tel")}{select("role", "Applicant’s role", ROLES)}
      {address("Residential address")}{select("id_type", "ID type", ID_TYPES)}{field("id_number", "ID number")}
    </>}
    {step === 2 && <>
      {field("name", "Business name")}{select("type", "Business type", BUSINESS_TYPES)}{data["type"] === "Other" && field("type_other", "Specify business type")}{select("category", "Business category", CATEGORIES)}{data["category"] === "Others" && field("category_other", "Specify category")}
      {field("phone", "Business phone", false, "tel")}{field("email", "Business email", false, "email")}{address("Business address")}{field("website", "Website", false, "url")}{field("years", "Years in business", false, "number")}{field("employees", "Number of employees", false, "number")}{field("description", "Business description", true, "text", true)}
    </>}
    {step === 3 && <>
      {field("store_name", "Store name")}
      <div className="sm:col-span-2 border-t pt-6"><h3 className="text-lg font-semibold">Social presence</h3><p className="mt-1 text-sm text-muted-foreground">Your account links or handles.</p>{errors["socials"] && <p role="alert" className="mt-2 text-sm text-destructive">{errors["socials"]}</p>}</div>
      {SOCIALS.map(s => field(s.key, s.label, false))}
      {yesNo("owns_brand", "Do you own a brand?")}
      {data["owns_brand"] === "yes" && <>{field("brand_name", "Brand name")}{select("brand_category", "Brand category", CATEGORIES)}{data["brand_category"] === "Others" && field("brand_category_other", "Specify brand category")}{field("brand_description", "Brand description", true, "text", true)}</>}
    </>}
    {step === 4 && <>{yesNo("registered", "Is your business registered?")}{data["registered"] === "yes" && <>{field("reg_number", "Registration number")}{field("reg_name", "Registered business name")}</>}</>}
  </div>;
}