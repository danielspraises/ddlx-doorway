-- Staff accounts, marketplace-ready products (approval, variations, sale prices),
-- sales received from the marketplace, and the seller wallet ledger.
--
-- The simple seller_products table from 0001 was only a day old and is replaced by `products`.

-- Staff are created by an admin and get only the permissions listed here.
-- 'products' = review, approve and publish seller products on the marketplace.
CREATE TABLE staff_members (
  user_id uuid PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  permissions text[] NOT NULL DEFAULT '{}' CHECK (permissions <@ ARRAY['products']::text[]),
  active boolean NOT NULL DEFAULT true,
  created_by uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);

DROP TABLE IF EXISTS seller_products;

-- status is the review state of the seller's latest version.
-- live says whether the marketplace is currently showing the approved version (published_snapshot).
-- Editing a live product never changes what customers see until a reviewer approves the new version.
CREATE TABLE products (
  id uuid PRIMARY KEY,
  seller_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 200),
  slug text NOT NULL,
  product_type text NOT NULL DEFAULT 'simple' CHECK (product_type IN ('simple', 'variable')),
  sku text NOT NULL CHECK (length(trim(sku)) BETWEEN 1 AND 100),
  brand text NOT NULL DEFAULT '' CHECK (length(brand) <= 100),
  category text NOT NULL DEFAULT '' CHECK (length(category) <= 80),
  tags text[] NOT NULL DEFAULT '{}' CHECK (cardinality(tags) <= 20),
  short_description text NOT NULL DEFAULT '' CHECK (length(short_description) <= 500),
  description text NOT NULL DEFAULT '' CHECK (length(description) <= 10000),
  currency text NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN', 'USD', 'GBP', 'EUR')),
  regular_price numeric(12,2) CHECK (regular_price >= 0),
  sale_price numeric(12,2) CHECK (sale_price >= 0),
  sale_starts_at timestamptz,
  sale_ends_at timestamptz,
  manage_stock boolean NOT NULL DEFAULT true,
  stock integer NOT NULL DEFAULT 0 CHECK (stock BETWEEN 0 AND 1000000),
  low_stock_threshold integer NOT NULL DEFAULT 5 CHECK (low_stock_threshold BETWEEN 0 AND 1000000),
  allow_backorders boolean NOT NULL DEFAULT false,
  weight_kg numeric(8,3) CHECK (weight_kg >= 0),
  length_cm numeric(8,1) CHECK (length_cm >= 0),
  width_cm numeric(8,1) CHECK (width_cm >= 0),
  height_cm numeric(8,1) CHECK (height_cm >= 0),
  attributes jsonb NOT NULL DEFAULT '[]'::jsonb,
  featured_image text,
  gallery text[] NOT NULL DEFAULT '{}' CHECK (cardinality(gallery) <= 8),
  status text NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft', 'pending_review', 'changes_requested', 'rejected', 'approved')),
  live boolean NOT NULL DEFAULT false,
  published_snapshot jsonb,
  admin_comment text,
  submitted_at timestamptz,
  reviewed_by uuid REFERENCES users(id) ON DELETE SET NULL,
  reviewed_at timestamptz,
  published_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT date_trunc('milliseconds', now()),
  updated_at timestamptz NOT NULL DEFAULT date_trunc('milliseconds', now()),
  UNIQUE (seller_id, sku),
  CHECK (sale_price IS NULL OR regular_price IS NULL OR sale_price < regular_price),
  CHECK (sale_starts_at IS NULL OR sale_ends_at IS NULL OR sale_ends_at > sale_starts_at),
  CHECK (NOT live OR published_snapshot IS NOT NULL)
);
CREATE UNIQUE INDEX products_slug_unique ON products (slug);
CREATE INDEX products_seller_idx ON products (seller_id, updated_at DESC);
CREATE INDEX products_review_idx ON products (status) WHERE status = 'pending_review';
CREATE INDEX products_live_idx ON products (updated_at) WHERE published_snapshot IS NOT NULL;

CREATE TABLE product_variations (
  id uuid PRIMARY KEY,
  product_id uuid NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  sku text NOT NULL CHECK (length(trim(sku)) BETWEEN 1 AND 100),
  options jsonb NOT NULL DEFAULT '{}'::jsonb,
  regular_price numeric(12,2) CHECK (regular_price >= 0),
  sale_price numeric(12,2) CHECK (sale_price >= 0),
  sale_starts_at timestamptz,
  sale_ends_at timestamptz,
  stock integer NOT NULL DEFAULT 0 CHECK (stock BETWEEN 0 AND 1000000),
  image text,
  enabled boolean NOT NULL DEFAULT true,
  position integer NOT NULL DEFAULT 0,
  UNIQUE (product_id, sku),
  CHECK (sale_price IS NULL OR regular_price IS NULL OR sale_price < regular_price),
  CHECK (sale_starts_at IS NULL OR sale_ends_at IS NULL OR sale_ends_at > sale_starts_at)
);
CREATE INDEX product_variations_product_idx ON product_variations (product_id, position);

-- Sales are reported by the marketplace (signed webhook). One row per marketplace order.
CREATE TABLE orders (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  marketplace_order_id text NOT NULL UNIQUE,
  number text NOT NULL DEFAULT '',
  currency text NOT NULL CHECK (currency IN ('NGN', 'USD', 'GBP', 'EUR')),
  customer_name text NOT NULL DEFAULT '',
  ship_city text NOT NULL DEFAULT '',
  ship_state text NOT NULL DEFAULT '',
  placed_at timestamptz NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);

-- One row per product line, owned by the seller who sold it.
CREATE TABLE order_items (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  seller_id uuid NOT NULL REFERENCES users(id),
  product_id uuid REFERENCES products(id) ON DELETE SET NULL,
  variation_id uuid REFERENCES product_variations(id) ON DELETE SET NULL,
  name text NOT NULL,
  sku text NOT NULL DEFAULT '',
  options jsonb NOT NULL DEFAULT '{}'::jsonb,
  quantity integer NOT NULL CHECK (quantity > 0),
  unit_price numeric(12,2) NOT NULL CHECK (unit_price >= 0),
  line_total numeric(12,2) NOT NULL CHECK (line_total >= 0),
  commission_rate numeric(5,2) NOT NULL CHECK (commission_rate BETWEEN 0 AND 100),
  commission numeric(12,2) NOT NULL CHECK (commission >= 0),
  net numeric(12,2) NOT NULL,
  status text NOT NULL DEFAULT 'paid' CHECK (status IN ('paid', 'refunded')),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX order_items_seller_idx ON order_items (seller_id, created_at DESC);
CREATE INDEX order_items_order_idx ON order_items (order_id);

-- The seller's money. Positive = money in, negative = money out. Balance = sum of entries.
-- A sale becomes withdrawable at available_at (after the payout hold period).
CREATE TABLE wallet_entries (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  seller_id uuid NOT NULL REFERENCES users(id),
  kind text NOT NULL CHECK (kind IN ('sale', 'refund', 'withdrawal', 'adjustment')),
  amount numeric(12,2) NOT NULL,
  currency text NOT NULL CHECK (currency IN ('NGN', 'USD', 'GBP', 'EUR')),
  order_item_id uuid REFERENCES order_items(id) ON DELETE SET NULL,
  note text NOT NULL DEFAULT '',
  available_at timestamptz NOT NULL DEFAULT now(),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX wallet_entries_seller_idx ON wallet_entries (seller_id, created_at DESC);
-- A marketplace retry can never credit or reverse the same sale line twice.
CREATE UNIQUE INDEX wallet_entries_item_kind_unique ON wallet_entries (order_item_id, kind) WHERE order_item_id IS NOT NULL;
