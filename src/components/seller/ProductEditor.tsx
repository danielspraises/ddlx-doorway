import { useState } from "react";
import { Link, useNavigate } from "@tanstack/react-router";
import { useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Eye, EyeOff, ImagePlus, Loader2, Send, Undo2, X } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SelectField, TextField } from "@/components/FormFields";
import { ProductImage } from "@/components/seller/ProductImage";
import { fileToBase64 } from "@/lib/files";
import { CURRENCIES, MAX_GALLERY, MAX_VARIATIONS, PRODUCT_CATEGORIES, combinations, optionKey, productsQuery, readinessProblems, validateProduct, visibility } from "@/lib/products";
import { removeProductImage, saveProduct, setProductLive, submitProduct, uploadProductImage, withdrawSubmission } from "@/lib/products.functions";
import type { Product } from "@/lib/types";

type VarForm = { id: string | null; sku: string; options: Record<string, string>; regular_price: string; sale_price: string; sale_starts_at: string; sale_ends_at: string; stock: string; enabled: boolean };
type Form = {
  name: string; sku: string; product_type: "simple" | "variable"; brand: string; category: string; tags: string;
  short_description: string; description: string; currency: string;
  regular_price: string; sale_price: string; sale_starts_at: string; sale_ends_at: string;
  manage_stock: boolean; stock: string; low_stock_threshold: string; allow_backorders: boolean;
  weight_kg: string; length_cm: string; width_cm: string; height_cm: string;
  attributes: { name: string; values: string }[];
  variations: VarForm[];
};
type Tab = "general" | "inventory" | "shipping" | "attributes" | "variations";

const pad = (n: number) => String(n).padStart(2, "0");
const isoToLocal = (iso: string | null) => {
  if (!iso) return "";
  const d = new Date(iso);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};
const localToIso = (value: string) => {
  if (!value) return null;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
};
const text = (value: number | null) => (value === null ? "" : String(value));
const parseValues = (value: string) => {
  const seen = new Set<string>();
  return value.split(",").map((v) => v.trim()).filter((v) => v && !seen.has(v.toLowerCase()) && seen.add(v.toLowerCase()));
};

function toForm(p: Product | null): Form {
  return {
    name: p?.name ?? "", sku: p?.sku ?? "", product_type: p?.product_type ?? "simple", brand: p?.brand ?? "", category: p?.category ?? "",
    tags: (p?.tags ?? []).join(", "), short_description: p?.short_description ?? "", description: p?.description ?? "", currency: p?.currency ?? "NGN",
    regular_price: text(p?.regular_price ?? null), sale_price: text(p?.sale_price ?? null),
    sale_starts_at: isoToLocal(p?.sale_starts_at ?? null), sale_ends_at: isoToLocal(p?.sale_ends_at ?? null),
    manage_stock: p?.manage_stock ?? true, stock: String(p?.stock ?? 0), low_stock_threshold: String(p?.low_stock_threshold ?? 5), allow_backorders: p?.allow_backorders ?? false,
    weight_kg: text(p?.weight_kg ?? null), length_cm: text(p?.length_cm ?? null), width_cm: text(p?.width_cm ?? null), height_cm: text(p?.height_cm ?? null),
    attributes: (p?.attributes ?? []).map((a) => ({ name: a.name, values: a.values.join(", ") })),
    variations: (p?.variations ?? []).map((v) => ({
      id: v.id, sku: v.sku, options: v.options, regular_price: text(v.regular_price), sale_price: text(v.sale_price),
      sale_starts_at: isoToLocal(v.sale_starts_at), sale_ends_at: isoToLocal(v.sale_ends_at), stock: String(v.stock), enabled: v.enabled,
    })),
  };
}

// Turns what was typed into what the server expects. `bad` is set when a number field is not a number.
function toInput(form: Form, id: string | null) {
  let bad = false;
  const num = (value: string): number | null => {
    if (value.trim() === "") return null;
    const n = Number(value);
    if (!Number.isFinite(n)) { bad = true; return null; }
    return n;
  };
  const whole = (value: string) => { const n = num(value); return n === null ? 0 : Math.trunc(n); };
  const input = {
    id,
    name: form.name, sku: form.sku, product_type: form.product_type, brand: form.brand, category: form.category,
    tags: parseValues(form.tags), short_description: form.short_description, description: form.description,
    currency: form.currency as Product["currency"],
    regular_price: num(form.regular_price), sale_price: num(form.sale_price),
    sale_starts_at: localToIso(form.sale_starts_at), sale_ends_at: localToIso(form.sale_ends_at),
    manage_stock: form.manage_stock, stock: whole(form.stock), low_stock_threshold: whole(form.low_stock_threshold), allow_backorders: form.allow_backorders,
    weight_kg: num(form.weight_kg), length_cm: num(form.length_cm), width_cm: num(form.width_cm), height_cm: num(form.height_cm),
    attributes: form.attributes.map((a) => ({ name: a.name.trim(), values: parseValues(a.values) })).filter((a) => a.name || a.values.length),
    variations: form.product_type === "variable"
      ? form.variations.map((v) => ({
          id: v.id, sku: v.sku, options: v.options, regular_price: num(v.regular_price), sale_price: num(v.sale_price),
          sale_starts_at: localToIso(v.sale_starts_at), sale_ends_at: localToIso(v.sale_ends_at), stock: whole(v.stock), enabled: v.enabled,
        }))
      : [],
  };
  return { input, bad };
}

const skuPart = (value: string) => value.toUpperCase().replace(/[^A-Z0-9]+/g, "");

export function ProductEditor({ initial }: { initial: Product | null }) {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [product, setProduct] = useState<Product | null>(initial);
  const [form, setForm] = useState<Form>(() => toForm(initial));
  const [tab, setTab] = useState<Tab>(initial?.product_type === "variable" ? "attributes" : "general");
  const [busy, setBusy] = useState(false);
  const [photoBusy, setPhotoBusy] = useState(false);
  const [bulkPrice, setBulkPrice] = useState("");

  const set = <K extends keyof Form>(key: K) => (value: Form[K]) => setForm((prev) => ({ ...prev, [key]: value }));
  const variable = form.product_type === "variable";
  const locked = product?.status === "pending_review";
  const state = product ? visibility(product) : null;
  const tabs: { key: Tab; label: string }[] = variable
    ? [{ key: "attributes", label: "Attributes" }, { key: "variations", label: `Variations (${form.variations.length})` }, { key: "inventory", label: "Inventory" }, { key: "shipping", label: "Shipping" }]
    : [{ key: "general", label: "Pricing" }, { key: "inventory", label: "Inventory" }, { key: "shipping", label: "Shipping" }];
  const activeTab: Tab = tabs.some((t) => t.key === tab) ? tab : (tabs[0]?.key ?? "general");

  const putProduct = (next: Product) => {
    setProduct(next);
    queryClient.setQueryData<Product[]>(productsQuery.queryKey, (old) =>
      old ? (old.some((p) => p.id === next.id) ? old.map((p) => (p.id === next.id ? next : p)) : [next, ...old]) : [next],
    );
  };

  const save = async (quiet = false): Promise<Product | null> => {
    const { input, bad } = toInput(form, product?.id ?? null);
    if (bad) { toast.error("Check the numbers you entered."); return null; }
    const problem = validateProduct(input);
    if (problem) { toast.error(problem); return null; }
    const result = await saveProduct({ data: input });
    if (!result.ok) { toast.error(result.error); return null; }
    const wasNew = !product;
    putProduct(result.product);
    setForm(toForm(result.product));
    if (!quiet) toast.success(wasNew ? "Product created. You can add photos now." : "Saved.");
    if (wasNew) void navigate({ to: "/product-editor", search: { id: result.product.id }, replace: true });
    return result.product;
  };

  const run = async (work: () => Promise<void>) => {
    if (busy) return;
    setBusy(true);
    try { await work(); } catch { toast.error("Something went wrong. Please try again."); } finally { setBusy(false); }
  };

  const onSave = () => run(async () => { await save(); });
  const onSubmit = () => run(async () => {
    const saved = await save(true);
    if (!saved) return;
    const problems = readinessProblems(saved);
    if (problems.length) { toast.error(problems[0] ?? "Complete the product first."); return; }
    const result = await submitProduct({ data: { id: saved.id } });
    if (!result.ok) { toast.error(result.error); return; }
    putProduct(result.product);
    toast.success("Sent for review. You will be told when it is approved.");
  });
  const onWithdraw = () => run(async () => {
    if (!product) return;
    const result = await withdrawSubmission({ data: { id: product.id } });
    if (!result.ok) { toast.error(result.error); return; }
    putProduct(result.product);
    toast.success("Withdrawn from review. You can edit it again.");
  });
  const onLive = (live: boolean) => run(async () => {
    if (!product) return;
    const result = await setProductLive({ data: { id: product.id, live } });
    if (!result.ok) { toast.error(result.error); return; }
    putProduct(result.product);
    toast.success(live ? "Back on the marketplace." : "Taken off the marketplace.");
  });

  const upload = async (file: File | undefined, slot: "featured" | "gallery" | "variation", variationId: string | null) => {
    if (!file || !product || photoBusy) return;
    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type) || file.size > 5 * 1024 * 1024) { toast.error("Choose a JPG, PNG or WebP photo up to 5 MB."); return; }
    setPhotoBusy(true);
    try {
      const result = await uploadProductImage({ data: { product_id: product.id, slot, variation_id: variationId, content_type: file.type, data_base64: await fileToBase64(file) } });
      if (!result.ok) { toast.error(result.error); return; }
      putProduct(result.product);
    } catch { toast.error("Could not upload the photo. Please try again."); } finally { setPhotoBusy(false); }
  };
  const dropPhoto = async (path: string) => {
    if (!product || photoBusy) return;
    setPhotoBusy(true);
    try {
      const result = await removeProductImage({ data: { product_id: product.id, path } });
      if (!result.ok) { toast.error(result.error); return; }
      putProduct(result.product);
    } catch { toast.error("Could not remove the photo. Please try again."); } finally { setPhotoBusy(false); }
  };

  const setAttr = (index: number, patch: Partial<Form["attributes"][number]>) =>
    setForm((prev) => ({ ...prev, attributes: prev.attributes.map((a, i) => (i === index ? { ...a, ...patch } : a)) }));
  const setVar = (index: number, patch: Partial<VarForm>) =>
    setForm((prev) => ({ ...prev, variations: prev.variations.map((v, i) => (i === index ? { ...v, ...patch } : v)) }));

  const generate = () => {
    const attrs = form.attributes.map((a) => ({ name: a.name.trim(), values: parseValues(a.values) })).filter((a) => a.name && a.values.length);
    const combos = combinations(attrs);
    if (combos.length === 0) { toast.error("Add an attribute with at least one value first."); return; }
    if (combos.length > MAX_VARIATIONS) { toast.error(`That makes ${combos.length} variations. The limit is ${MAX_VARIATIONS}.`); return; }
    setForm((prev) => {
      const existing = new Map(prev.variations.map((v) => [optionKey(v.options), v]));
      const variations = combos.map((options): VarForm =>
        existing.get(optionKey(options)) ?? {
          id: null, sku: [skuPart(prev.sku) || "SKU", ...Object.values(options).map(skuPart)].join("-"), options,
          regular_price: "", sale_price: "", sale_starts_at: "", sale_ends_at: "", stock: "0", enabled: true,
        });
      return { ...prev, attributes: attrs.map((a) => ({ name: a.name, values: a.values.join(", ") })), variations };
    });
    setTab("variations");
    toast.success(`${combos.length} variation${combos.length === 1 ? "" : "s"} ready. Set their prices and stock.`);
  };

  const applyPrice = () => {
    if (bulkPrice.trim() === "" || !Number.isFinite(Number(bulkPrice)) || Number(bulkPrice) < 0) { toast.error("Enter a price first."); return; }
    setForm((prev) => ({ ...prev, variations: prev.variations.map((v) => ({ ...v, regular_price: bulkPrice })) }));
  };

  const categories = form.category && !PRODUCT_CATEGORIES.includes(form.category) ? [form.category, ...PRODUCT_CATEGORIES] : PRODUCT_CATEGORIES;
  const canSubmit = !product || ["draft", "changes_requested", "rejected"].includes(product.status);

  return (
    <div className="mt-8">
      <Button asChild variant="ghost" className="-ml-3"><Link to="/seller" search={{ tab: "products" }}><ArrowLeft className="size-4" /> All products</Link></Button>

      {product?.admin_comment && ["changes_requested", "rejected"].includes(product.status) && (
        <div className="mt-4 border-l-2 border-accent bg-secondary p-5"><h2 className="font-semibold">{product.status === "rejected" ? "Not approved" : "Changes requested"}</h2><p className="mt-2 whitespace-pre-wrap">{product.admin_comment}</p></div>
      )}
      {locked && <div className="mt-4 border-l-2 border-accent bg-secondary p-5"><h2 className="font-semibold">In review</h2><p className="mt-1 text-sm text-muted-foreground">You can edit this product again after you withdraw it from review.</p></div>}

      <div className="mt-6 grid gap-8 lg:grid-cols-[minmax(0,1fr)_320px]">
        <fieldset disabled={locked || busy} className="min-w-0 space-y-6">
          <TextField name="product-name" label="Product name" required value={form.name} onChange={set("name")} />
          <TextField name="product-short" label="Short description" multiline value={form.short_description} onChange={set("short_description")} hint="One or two lines shown next to the price." />
          <TextField name="product-description" label="Description" multiline value={form.description} onChange={set("description")} hint="Materials, sizing, care, what is in the box." />

          <section className="rounded-md border">
            <div className="grid gap-4 border-b p-5 sm:grid-cols-2">
              <SelectField name="product-type" label="Product type" required value={form.product_type === "simple" ? "Simple product" : "Variable product (sizes, colours)"} onChange={(v) => set("product_type")(v.startsWith("Variable") ? "variable" : "simple")} options={["Simple product", "Variable product (sizes, colours)"]} />
              <SelectField name="product-currency" label="Currency" required value={form.currency} onChange={set("currency")} options={CURRENCIES} />
            </div>
            <div className="flex flex-wrap gap-1 border-b px-3 pt-3" role="tablist">
              {tabs.map((t) => <button key={t.key} type="button" role="tab" aria-selected={activeTab === t.key} onClick={() => setTab(t.key)} className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${activeTab === t.key ? "border-accent text-foreground" : "border-transparent text-muted-foreground"}`}>{t.label}</button>)}
            </div>
            <div className="space-y-5 p-5">
              {activeTab === "general" && <>
                <div className="grid gap-4 sm:grid-cols-2">
                  <TextField name="regular-price" label="Regular price" required type="number" value={form.regular_price} onChange={set("regular_price")} />
                  <TextField name="sale-price" label="Sale price" type="number" value={form.sale_price} onChange={set("sale_price")} hint="Must be lower than the regular price." />
                </div>
                {form.sale_price.trim() !== "" && <div className="grid gap-4 sm:grid-cols-2">
                  <TextField name="sale-start" label="Sale starts" type="datetime-local" value={form.sale_starts_at} onChange={set("sale_starts_at")} hint="Leave empty to start straight away." />
                  <TextField name="sale-end" label="Sale ends" type="datetime-local" value={form.sale_ends_at} onChange={set("sale_ends_at")} hint="Leave empty to run until you remove it." />
                </div>}
              </>}

              {activeTab === "inventory" && <>
                <TextField name="product-sku" label="SKU" required value={form.sku} onChange={set("sku")} hint="Your own unique code for this product." />
                {!variable && <label className="flex cursor-pointer items-center gap-3 text-sm"><Checkbox checked={form.manage_stock} onCheckedChange={(v) => set("manage_stock")(v === true)} />Track stock for this product</label>}
                <div className="grid gap-4 sm:grid-cols-2">
                  {!variable && form.manage_stock && <TextField name="product-stock" label="Units in stock" required type="number" value={form.stock} onChange={set("stock")} />}
                  <TextField name="product-threshold" label="Low-stock warning at" required type="number" value={form.low_stock_threshold} onChange={set("low_stock_threshold")} hint="You are warned when stock falls to this number." />
                </div>
                <label className="flex cursor-pointer items-center gap-3 text-sm"><Checkbox checked={form.allow_backorders} onCheckedChange={(v) => set("allow_backorders")(v === true)} />Allow orders when out of stock</label>
              </>}

              {activeTab === "shipping" && <div className="grid gap-4 sm:grid-cols-2">
                <TextField name="weight" label="Weight (kg)" type="number" value={form.weight_kg} onChange={set("weight_kg")} />
                <span className="hidden sm:block" />
                <TextField name="length" label="Length (cm)" type="number" value={form.length_cm} onChange={set("length_cm")} />
                <TextField name="width" label="Width (cm)" type="number" value={form.width_cm} onChange={set("width_cm")} />
                <TextField name="height" label="Height (cm)" type="number" value={form.height_cm} onChange={set("height_cm")} />
              </div>}

              {activeTab === "attributes" && <>
                <p className="text-sm text-muted-foreground">Add what customers choose between, such as Size or Colour. Separate values with commas, then build the variations.</p>
                {form.attributes.map((a, index) => (
                  <div key={index} className="grid gap-3 rounded-md border p-4 sm:grid-cols-[180px_minmax(0,1fr)_auto]">
                    <TextField name={`attr-name-${index}`} label="Name" required value={a.name} onChange={(v) => setAttr(index, { name: v })} placeholder="Size" />
                    <TextField name={`attr-values-${index}`} label="Values" required value={a.values} onChange={(v) => setAttr(index, { values: v })} placeholder="S, M, L, XL" />
                    <Button type="button" variant="ghost" size="icon" className="self-end" aria-label="Remove attribute" onClick={() => setForm((prev) => ({ ...prev, attributes: prev.attributes.filter((_, i) => i !== index) }))}><X className="size-4" /></Button>
                  </div>
                ))}
                <div className="flex flex-wrap gap-3">
                  {form.attributes.length < 3 && <Button type="button" variant="outline" onClick={() => setForm((prev) => ({ ...prev, attributes: [...prev.attributes, { name: "", values: "" }] }))}>Add attribute</Button>}
                  <Button type="button" onClick={generate}>Build variations</Button>
                </div>
              </>}

              {activeTab === "variations" && <>
                {form.variations.length === 0 ? <p className="text-sm text-muted-foreground">No variations yet. Add attributes, then press Build variations.</p> : <>
                  <div className="flex flex-wrap items-end gap-3">
                    <div className="space-y-1.5"><Label htmlFor="bulk-price" className="text-sm">Set one price for all</Label><Input id="bulk-price" type="number" min={0} className="w-40" value={bulkPrice} onChange={(e) => setBulkPrice(e.target.value)} /></div>
                    <Button type="button" variant="outline" onClick={applyPrice}>Apply</Button>
                  </div>
                  <ul className="space-y-4">
                    {form.variations.map((v, index) => {
                      const image = product?.variations.find((x) => x.id === v.id)?.image;
                      return (
                        <li key={v.id ?? `new-${index}`} className="space-y-3 rounded-md border p-4">
                          <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="font-semibold">{Object.entries(v.options).map(([k, val]) => `${k}: ${val}`).join(" · ")}</p>
                            <label className="flex cursor-pointer items-center gap-2 text-sm"><Checkbox checked={v.enabled} onCheckedChange={(c) => setVar(index, { enabled: c === true })} />Available</label>
                          </div>
                          <div className="grid gap-3 sm:grid-cols-4">
                            <TextField name={`v-sku-${index}`} label="SKU" required value={v.sku} onChange={(x) => setVar(index, { sku: x })} />
                            <TextField name={`v-price-${index}`} label="Regular price" required type="number" value={v.regular_price} onChange={(x) => setVar(index, { regular_price: x })} />
                            <TextField name={`v-sale-${index}`} label="Sale price" type="number" value={v.sale_price} onChange={(x) => setVar(index, { sale_price: x })} />
                            <TextField name={`v-stock-${index}`} label="Stock" required type="number" value={v.stock} onChange={(x) => setVar(index, { stock: x })} />
                          </div>
                          {v.sale_price.trim() !== "" && <div className="grid gap-3 sm:grid-cols-2">
                            <TextField name={`v-start-${index}`} label="Sale starts" type="datetime-local" value={v.sale_starts_at} onChange={(x) => setVar(index, { sale_starts_at: x })} />
                            <TextField name={`v-end-${index}`} label="Sale ends" type="datetime-local" value={v.sale_ends_at} onChange={(x) => setVar(index, { sale_ends_at: x })} />
                          </div>}
                          <div className="flex items-center gap-3">
                            {image ? <ProductImage path={image} alt={`${v.sku} photo`} className="size-12 rounded border" /> : <span className="grid size-12 place-items-center rounded border border-dashed text-muted-foreground"><ImagePlus className="size-4" /></span>}
                            {v.id && product ? (
                              <>
                                <label className="cursor-pointer text-sm font-medium underline"><input type="file" className="sr-only" accept="image/jpeg,image/png,image/webp" disabled={photoBusy || locked} onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ""; void upload(f, "variation", v.id); }} />{image ? "Replace photo" : "Add photo"}</label>
                                {image && <button type="button" className="text-sm text-muted-foreground underline" disabled={photoBusy || locked} onClick={() => void dropPhoto(image)}>Remove</button>}
                              </>
                            ) : <span className="text-xs text-muted-foreground">Save the product to add a photo for this variation.</span>}
                          </div>
                        </li>
                      );
                    })}
                  </ul>
                </>}
              </>}
            </div>
          </section>
        </fieldset>

        <aside className="space-y-6">
          <section className="rounded-md border p-5">
            <h2 className="font-semibold">Publish</h2>
            <div className="mt-3 flex flex-wrap items-center gap-2 text-sm">
              <span className="text-muted-foreground">Status</span>
              {state ? <Badge variant={state.tone === "live" ? "default" : "outline"}>{state.label}</Badge> : <Badge variant="outline">New</Badge>}
            </div>
            <p className="mt-3 text-xs text-muted-foreground">Products reach the marketplace after the Vendor Hub team approves them. Stock changes go live straight away.</p>
            <div className="mt-4 flex flex-col gap-2">
              <Button disabled={busy || locked} onClick={() => void onSave()}>{busy ? <Loader2 className="size-4 animate-spin" /> : null}{product ? "Save changes" : "Create product"}</Button>
              {canSubmit && <Button variant="outline" disabled={busy || locked} onClick={() => void onSubmit()}><Send className="size-4" />{product?.live ? "Submit changes for review" : "Submit for review"}</Button>}
              {locked && <Button variant="outline" disabled={busy} onClick={() => void onWithdraw()}><Undo2 className="size-4" />Withdraw from review</Button>}
              {product?.live && <Button variant="outline" disabled={busy} onClick={() => void onLive(false)}><EyeOff className="size-4" />Take off marketplace</Button>}
              {product && !product.live && product.status === "approved" && <Button variant="outline" disabled={busy} onClick={() => void onLive(true)}><Eye className="size-4" />Put back on marketplace</Button>}
            </div>
          </section>

          <fieldset disabled={locked || busy} className="space-y-6">
            <section className="rounded-md border p-5">
              <h2 className="font-semibold">Featured image</h2>
              {product ? (
                product.featured_image ? (
                  <div className="relative mt-3">
                    <ProductImage path={product.featured_image} alt="Featured image" className="aspect-square w-full rounded-md border" />
                    <button type="button" disabled={photoBusy || locked} onClick={() => void dropPhoto(product.featured_image as string)} aria-label="Remove featured image" className="absolute right-2 top-2 grid size-7 place-items-center rounded-full bg-background/90 shadow"><X className="size-4" /></button>
                    <label className="mt-2 block cursor-pointer text-sm font-medium underline"><input type="file" className="sr-only" accept="image/jpeg,image/png,image/webp" disabled={photoBusy || locked} onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ""; void upload(f, "featured", null); }} />Replace image</label>
                  </div>
                ) : (
                  <label className="mt-3 grid aspect-square cursor-pointer place-items-center rounded-md border border-dashed text-muted-foreground hover:bg-secondary">
                    {photoBusy ? <Loader2 className="size-5 animate-spin" /> : <span className="grid place-items-center gap-1 text-sm"><ImagePlus className="size-6" />Add featured image</span>}
                    <input type="file" className="sr-only" accept="image/jpeg,image/png,image/webp" disabled={photoBusy || locked} onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ""; void upload(f, "featured", null); }} />
                  </label>
                )
              ) : <p className="mt-2 text-sm text-muted-foreground">Create the product first, then add photos.</p>}
              <p className="mt-3 text-xs text-muted-foreground">The main photo customers see. JPG, PNG or WebP, up to 5 MB.</p>
            </section>

            <section className="rounded-md border p-5">
              <h2 className="font-semibold">Gallery</h2>
              {product ? (
                <ul className="mt-3 grid grid-cols-3 gap-2">
                  {product.gallery.map((path, index) => (
                    <li key={path} className="relative">
                      <ProductImage path={path} alt={`Gallery photo ${index + 1}`} className="aspect-square w-full rounded border" />
                      <button type="button" disabled={photoBusy || locked} onClick={() => void dropPhoto(path)} aria-label={`Remove gallery photo ${index + 1}`} className="absolute right-1 top-1 grid size-5 place-items-center rounded-full bg-background/90 shadow"><X className="size-3" /></button>
                    </li>
                  ))}
                  {product.gallery.length < MAX_GALLERY && (
                    <li>
                      <label className="grid aspect-square cursor-pointer place-items-center rounded border border-dashed text-muted-foreground hover:bg-secondary">
                        {photoBusy ? <Loader2 className="size-4 animate-spin" /> : <ImagePlus className="size-5" />}
                        <span className="sr-only">Add gallery photo</span>
                        <input type="file" className="sr-only" accept="image/jpeg,image/png,image/webp" disabled={photoBusy || locked} onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ""; void upload(f, "gallery", null); }} />
                      </label>
                    </li>
                  )}
                </ul>
              ) : <p className="mt-2 text-sm text-muted-foreground">Create the product first, then add photos.</p>}
              <p className="mt-3 text-xs text-muted-foreground">Up to {MAX_GALLERY} more photos from other angles.</p>
            </section>

            <section className="space-y-4 rounded-md border p-5">
              <SelectField name="product-category" label="Category" required value={form.category} onChange={set("category")} options={categories} />
              <TextField name="product-brand" label="Brand" value={form.brand} onChange={set("brand")} />
              <TextField name="product-tags" label="Tags" value={form.tags} onChange={set("tags")} hint="Separate with commas, e.g. ankara, summer." />
            </section>
          </fieldset>
        </aside>
      </div>
    </div>
  );
}
