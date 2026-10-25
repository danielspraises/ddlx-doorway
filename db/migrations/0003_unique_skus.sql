-- SKUs must be unique across every seller (the marketplace store needs that), not only per seller.
-- The app checks this before saving; these indexes make sure two sellers saving at the same moment cannot both win.
-- If this fails, two products already share a SKU: change one of them, then run `npm run db:migrate` again.
CREATE UNIQUE INDEX products_sku_unique_global ON products (lower(sku));
CREATE UNIQUE INDEX product_variations_sku_unique_global ON product_variations (lower(sku));
