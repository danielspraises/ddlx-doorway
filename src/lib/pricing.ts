// Sale prices only apply inside their date window, exactly like WooCommerce scheduled sales.
type Priced = { regular_price: number | null; sale_price: number | null; sale_starts_at: string | null; sale_ends_at: string | null };

export function saleActive(item: Priced, now: Date = new Date()): boolean {
  if (item.sale_price === null || item.regular_price === null) return false;
  if (item.sale_starts_at && new Date(item.sale_starts_at) > now) return false;
  if (item.sale_ends_at && new Date(item.sale_ends_at) <= now) return false;
  return true;
}

export function effectivePrice(item: Priced, now: Date = new Date()): number | null {
  return saleActive(item, now) ? item.sale_price : item.regular_price;
}
