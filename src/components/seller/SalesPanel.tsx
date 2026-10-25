import { useSuspenseQuery } from "@tanstack/react-query";
import { Badge } from "@/components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { formatPrice } from "@/lib/products";
import { salesOverviewQuery, salesQuery } from "@/lib/sales";

const date = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });

// Balances and every sale the marketplace has reported. New sales appear on their own.
export function Money() {
  const { data } = useSuspenseQuery(salesOverviewQuery);
  const currencies = [...new Set([...data.balances.map((b) => b.currency), ...data.sales_30d.map((s) => s.currency)])];
  if (currencies.length === 0) {
    return <p className="mt-8 rounded-md border p-6 text-muted-foreground">No sales yet. When a customer buys one of your products on the marketplace, it shows up here at once.</p>;
  }
  return (
    <div className="mt-8 space-y-4">
      {currencies.map((currency) => {
        const balance = data.balances.find((b) => b.currency === currency);
        const month = data.sales_30d.find((s) => s.currency === currency);
        return (
          <div key={currency} className="grid gap-4 sm:grid-cols-3">
            <div className="rounded-md border p-5"><p className="text-sm text-muted-foreground">Available to withdraw</p><p className="mt-3 text-3xl font-semibold">{formatPrice(balance?.available ?? 0, currency)}</p></div>
            <div className="rounded-md border p-5"><p className="text-sm text-muted-foreground">Pending ({data.hold_days}-day hold)</p><p className="mt-3 text-3xl font-semibold">{formatPrice(balance?.pending ?? 0, currency)}</p></div>
            <div className="rounded-md border p-5"><p className="text-sm text-muted-foreground">Last 30 days</p><p className="mt-3 text-3xl font-semibold">{formatPrice(month?.net ?? 0, currency)}</p><p className="mt-1 text-xs text-muted-foreground">{month?.units ?? 0} units · {formatPrice(month?.gross ?? 0, currency)} sold</p></div>
          </div>
        );
      })}
      <p className="text-xs text-muted-foreground">Your earnings are each sale minus the {data.commission_percent}% marketplace commission. Sales become available to withdraw after {data.hold_days} days.</p>
    </div>
  );
}

export function SalesPanel() {
  const { data: sales } = useSuspenseQuery(salesQuery);
  return (
    <div>
      <Money />
      <h2 className="mt-10 text-xl font-semibold">Recent sales</h2>
      <div className="mt-4 overflow-x-auto rounded-md border">
        <Table>
          <TableHeader><TableRow><TableHead>Order</TableHead><TableHead>Item</TableHead><TableHead className="text-right">Qty</TableHead><TableHead className="text-right">Sold for</TableHead><TableHead className="text-right">You earn</TableHead><TableHead>Customer</TableHead></TableRow></TableHeader>
          <TableBody>
            {sales.map((s) => (
              <TableRow key={s.id}>
                <TableCell className="whitespace-nowrap"><p className="font-medium">{s.order_number ? `#${s.order_number}` : "Order"}</p><p className="text-xs text-muted-foreground">{date(s.placed_at)}</p></TableCell>
                <TableCell><p className="font-medium">{s.name}</p><p className="text-xs text-muted-foreground">{s.sku}{Object.keys(s.options).length ? ` · ${Object.values(s.options).join(" / ")}` : ""}</p></TableCell>
                <TableCell className="text-right">{s.quantity}</TableCell>
                <TableCell className="whitespace-nowrap text-right">{formatPrice(s.line_total, s.currency)}</TableCell>
                <TableCell className="whitespace-nowrap text-right">{s.status === "refunded" ? <Badge variant="outline">Refunded</Badge> : formatPrice(s.net, s.currency)}</TableCell>
                <TableCell className="text-sm">{s.customer_name || "—"}<p className="text-xs text-muted-foreground">{[s.ship_city, s.ship_state].filter(Boolean).join(", ")}</p></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {!sales.length && <p className="py-12 text-center text-muted-foreground">Sales will be listed here.</p>}
      </div>
    </div>
  );
}
