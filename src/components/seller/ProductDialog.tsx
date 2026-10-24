import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { ImagePlus, Loader2, X } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { SelectField, TextField } from "@/components/FormFields";
import { ProductImage } from "@/components/seller/ProductImage";
import { CATEGORIES } from "@/lib/application";
import { fileToBase64 } from "@/lib/files";
import { CURRENCIES, MAX_PHOTOS, PRODUCT_STATUSES, PRODUCT_STATUS_LABEL } from "@/lib/products";
import { removeProductImage, saveProduct, uploadProductImage } from "@/lib/products.functions";
import type { Product } from "@/lib/types";

const PHOTO_TYPES = ["image/jpeg", "image/png", "image/webp"];
const STATUS_OPTIONS = PRODUCT_STATUSES.map((status) => PRODUCT_STATUS_LABEL[status]);

type Form = { name: string; description: string; sku: string; category: string; price: string; currency: string; stock: string; threshold: string; status: string };
type Props = { open: boolean; onOpenChange: (open: boolean) => void; product: Product | null };

// Create or edit a product. After the first save the same window stays open so photos can be added.
export function ProductDialog({ open, onOpenChange, product }: Props) {
  const queryClient = useQueryClient();
  const [saved, setSaved] = useState<Product | null>(product);
  const [form, setForm] = useState<Form>({
    name: product?.name ?? "",
    description: product?.description ?? "",
    sku: product?.sku ?? "",
    category: product?.category ?? CATEGORIES[0] ?? "Clothing",
    price: product ? String(product.price) : "",
    currency: product?.currency ?? "NGN",
    stock: String(product?.stock ?? 0),
    threshold: String(product?.low_stock_threshold ?? 5),
    status: PRODUCT_STATUS_LABEL[product?.status ?? "draft"],
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [uploading, setUploading] = useState(false);

  const set = (key: keyof Form) => (value: string) => {
    setForm((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => ({ ...prev, [key]: "" }));
  };
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["products"] });

  const validate = () => {
    const found: Record<string, string> = {};
    if (!form.name.trim()) found["name"] = "Product name is required.";
    if (!form.sku.trim()) found["sku"] = "SKU is required.";
    const price = Number(form.price);
    if (form.price.trim() === "" || !Number.isFinite(price) || price < 0) found["price"] = "Enter a price of 0 or more.";
    if (!Number.isInteger(Number(form.stock)) || Number(form.stock) < 0) found["stock"] = "Use a whole number, 0 or more.";
    if (!Number.isInteger(Number(form.threshold)) || Number(form.threshold) < 0) found["threshold"] = "Use a whole number, 0 or more.";
    setErrors(found);
    return Object.keys(found).length === 0;
  };

  const save = async () => {
    if (busy || !validate()) return;
    setBusy(true);
    try {
      const status = PRODUCT_STATUSES.find((value) => PRODUCT_STATUS_LABEL[value] === form.status) ?? "draft";
      const result = await saveProduct({
        data: {
          id: saved?.id ?? null,
          name: form.name,
          description: form.description,
          sku: form.sku,
          category: form.category,
          price: Number(form.price),
          currency: form.currency as Product["currency"],
          stock: Number(form.stock),
          low_stock_threshold: Number(form.threshold),
          status,
        },
      });
      if (!result.ok) { toast.error(result.error); return; }
      toast.success(saved ? "Product updated." : "Product created. You can add photos now.");
      setSaved(result.product);
      await refresh();
    } catch { toast.error("Could not save the product. Please try again."); }
    finally { setBusy(false); }
  };

  const addPhoto = async (file: File | undefined) => {
    if (!file || !saved || uploading) return;
    if (!PHOTO_TYPES.includes(file.type) || file.size > 5 * 1024 * 1024) { toast.error("Choose a JPG, PNG or WebP photo up to 5 MB."); return; }
    setUploading(true);
    try {
      const result = await uploadProductImage({ data: { product_id: saved.id, content_type: file.type, data_base64: await fileToBase64(file) } });
      if (!result.ok) { toast.error(result.error); return; }
      setSaved(result.product);
      await refresh();
    } catch { toast.error("Could not upload the photo. Please try again."); }
    finally { setUploading(false); }
  };

  const removePhoto = async (path: string) => {
    if (!saved || uploading) return;
    setUploading(true);
    try {
      const result = await removeProductImage({ data: { product_id: saved.id, path } });
      if (!result.ok) { toast.error(result.error); return; }
      setSaved(result.product);
      await refresh();
    } catch { toast.error("Could not remove the photo. Please try again."); }
    finally { setUploading(false); }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader className="pr-8">
          <DialogTitle>{saved ? "Edit product" : "Add product"}</DialogTitle>
          <DialogDescription>{saved ? "Changes are saved when you press Save." : "Add the details first, then photos."}</DialogDescription>
        </DialogHeader>

        <div className="grid gap-4 sm:grid-cols-2">
          <TextField className="sm:col-span-2" name="product-name" label="Product name" required value={form.name} onChange={set("name")} error={errors["name"]} />
          <TextField name="product-sku" label="SKU" required value={form.sku} onChange={set("sku")} error={errors["sku"]} hint="Your own unique code for this product." />
          <SelectField name="product-category" label="Category" required value={form.category} onChange={set("category")} options={CATEGORIES} />
          <TextField name="product-price" label="Price" required type="number" value={form.price} onChange={set("price")} error={errors["price"]} />
          <SelectField name="product-currency" label="Currency" required value={form.currency} onChange={set("currency")} options={CURRENCIES} />
          <TextField name="product-stock" label="Units in stock" required type="number" value={form.stock} onChange={set("stock")} error={errors["stock"]} />
          <TextField name="product-threshold" label="Low-stock warning at" required type="number" value={form.threshold} onChange={set("threshold")} error={errors["threshold"]} hint="You are warned when stock falls to this number." />
          <SelectField className="sm:col-span-2" name="product-status" label="Status" required value={form.status} onChange={set("status")} options={STATUS_OPTIONS} />
          <TextField className="sm:col-span-2" name="product-description" label="Description" multiline value={form.description} onChange={set("description")} />
        </div>

        <section className="border-t pt-5">
          <h3 className="font-semibold">Photos</h3>
          {saved ? (
            <>
              <p className="mt-1 text-sm text-muted-foreground">Up to {MAX_PHOTOS} photos. JPG, PNG or WebP, 5 MB each.</p>
              <ul className="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4">
                {saved.images.map((path, index) => (
                  <li key={path} className="relative">
                    <ProductImage path={path} alt={`${saved.name} photo ${index + 1}`} className="aspect-square w-full rounded-md border" />
                    <button type="button" disabled={uploading} onClick={() => removePhoto(path)} aria-label={`Remove photo ${index + 1}`} className="absolute right-1 top-1 grid size-6 place-items-center rounded-full bg-background/90 shadow hover:bg-background"><X className="size-3.5" /></button>
                  </li>
                ))}
                {saved.images.length < MAX_PHOTOS && (
                  <li>
                    <label className="grid aspect-square cursor-pointer place-items-center rounded-md border border-dashed text-muted-foreground hover:bg-secondary">
                      {uploading ? <Loader2 className="size-5 animate-spin" /> : <span className="grid place-items-center gap-1 text-xs"><ImagePlus className="size-5" />Add photo</span>}
                      <input type="file" className="sr-only" accept="image/jpeg,image/png,image/webp" disabled={uploading} onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ""; void addPhoto(file); }} />
                    </label>
                  </li>
                )}
              </ul>
            </>
          ) : (
            <p className="mt-1 text-sm text-muted-foreground">Save the product first, then you can add photos.</p>
          )}
        </section>

        <DialogFooter className="gap-2 sm:gap-0">
          <Button variant="outline" onClick={() => onOpenChange(false)}>{saved ? "Done" : "Cancel"}</Button>
          <Button onClick={save} disabled={busy}>{busy ? "Saving…" : saved ? "Save changes" : "Create product"}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
