-- Category tree, payouts (bank accounts + withdrawals) and store profiles.

-- ---------------------------------------------------------------- categories
-- A tree of any depth the app allows (the app limits it to 4 levels). Admins manage it; sellers pick a leaf.
CREATE TABLE categories (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  parent_id uuid REFERENCES categories(id) ON DELETE RESTRICT,
  name text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 80),
  position integer NOT NULL DEFAULT 0,
  active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now()
);
-- Two siblings cannot share a name (the same name is fine under different parents, e.g. "Clothing").
CREATE UNIQUE INDEX categories_sibling_name_unique
  ON categories (COALESCE(parent_id, '00000000-0000-0000-0000-000000000000'::uuid), lower(name));
CREATE INDEX categories_parent_idx ON categories (parent_id, position, name);

-- Names from the root down to the category, e.g. {Fashion,Men,Clothing}.
CREATE FUNCTION category_path(start uuid) RETURNS text[] LANGUAGE sql STABLE AS $$
  WITH RECURSIVE up AS (
    SELECT id, parent_id, name, 1 AS depth FROM categories WHERE id = start
    UNION ALL
    SELECT c.id, c.parent_id, c.name, up.depth + 1 FROM categories c JOIN up ON c.id = up.parent_id
  )
  SELECT COALESCE(array_agg(name ORDER BY depth DESC), '{}') FROM up
$$;

-- Creates every missing level of a path and returns the id of the last one.
CREATE FUNCTION vh_ensure_path(path text[]) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE
  parent uuid := NULL;
  found uuid;
  part text;
  n integer := 0;
BEGIN
  FOREACH part IN ARRAY path LOOP
    n := n + 1;
    SELECT id INTO found FROM categories
      WHERE parent_id IS NOT DISTINCT FROM parent AND lower(name) = lower(part);
    IF found IS NULL THEN
      INSERT INTO categories (parent_id, name, position) VALUES (parent, part, n) RETURNING id INTO found;
    END IF;
    parent := found;
  END LOOP;
  RETURN parent;
END $$;

DO $$
DECLARE
  entry text;
  paths text[] := ARRAY[
    'Fashion > Men > Clothing > T-shirts & Polos', 'Fashion > Men > Clothing > Shirts', 'Fashion > Men > Clothing > Trousers & Jeans',
    'Fashion > Men > Clothing > Native Wear', 'Fashion > Men > Clothing > Suits & Blazers', 'Fashion > Men > Clothing > Underwear & Sleepwear',
    'Fashion > Men > Clothing > Sportswear',
    'Fashion > Men > Footwear > Sneakers', 'Fashion > Men > Footwear > Formal Shoes', 'Fashion > Men > Footwear > Sandals & Slippers', 'Fashion > Men > Footwear > Boots',
    'Fashion > Men > Accessories > Watches', 'Fashion > Men > Accessories > Belts', 'Fashion > Men > Accessories > Hats & Caps', 'Fashion > Men > Accessories > Ties & Cufflinks',
    'Fashion > Women > Clothing > Dresses', 'Fashion > Women > Clothing > Tops & Blouses', 'Fashion > Women > Clothing > Skirts',
    'Fashion > Women > Clothing > Trousers & Jeans', 'Fashion > Women > Clothing > Native Wear', 'Fashion > Women > Clothing > Lingerie & Sleepwear',
    'Fashion > Women > Clothing > Sportswear',
    'Fashion > Women > Footwear > Heels', 'Fashion > Women > Footwear > Flats', 'Fashion > Women > Footwear > Sneakers', 'Fashion > Women > Footwear > Sandals',
    'Fashion > Women > Bags > Handbags', 'Fashion > Women > Bags > Clutches', 'Fashion > Women > Bags > Backpacks',
    'Fashion > Women > Jewellery > Necklaces', 'Fashion > Women > Jewellery > Earrings', 'Fashion > Women > Jewellery > Bracelets & Bangles', 'Fashion > Women > Jewellery > Rings',
    'Fashion > Women > Accessories > Scarves & Head Wraps', 'Fashion > Women > Accessories > Belts', 'Fashion > Women > Accessories > Sunglasses',
    'Fashion > Kids > Boys Clothing', 'Fashion > Kids > Girls Clothing', 'Fashion > Kids > Baby Clothing', 'Fashion > Kids > Kids Footwear', 'Fashion > Kids > School Uniforms',
    'Fashion > Fabrics & Tailoring > Ankara', 'Fashion > Fabrics & Tailoring > Lace', 'Fashion > Fabrics & Tailoring > Aso Oke', 'Fashion > Fabrics & Tailoring > Cotton & Linen', 'Fashion > Fabrics & Tailoring > Sewing Supplies',
    'Phones & Tablets > Smartphones', 'Phones & Tablets > Tablets', 'Phones & Tablets > Smart Watches',
    'Phones & Tablets > Accessories > Cases & Covers', 'Phones & Tablets > Accessories > Chargers & Cables', 'Phones & Tablets > Accessories > Power Banks', 'Phones & Tablets > Accessories > Screen Protectors',
    'Computers & Office > Laptops', 'Computers & Office > Desktops', 'Computers & Office > Monitors', 'Computers & Office > Networking',
    'Computers & Office > Accessories > Keyboards & Mice', 'Computers & Office > Accessories > Storage', 'Computers & Office > Accessories > Printers & Ink',
    'Computers & Office > Office Supplies',
    'Electronics > TV & Video', 'Electronics > Cameras', 'Electronics > Audio > Headphones', 'Electronics > Audio > Speakers', 'Electronics > Audio > Home Theatre',
    'Electronics > Gaming > Consoles', 'Electronics > Gaming > Games', 'Electronics > Gaming > Accessories',
    'Electronics > Power & Solar > Inverters', 'Electronics > Power & Solar > Solar Panels', 'Electronics > Power & Solar > Batteries', 'Electronics > Power & Solar > Generators',
    'Home & Kitchen > Furniture > Living Room', 'Home & Kitchen > Furniture > Bedroom', 'Home & Kitchen > Furniture > Office', 'Home & Kitchen > Furniture > Outdoor',
    'Home & Kitchen > Kitchen & Dining > Cookware', 'Home & Kitchen > Kitchen & Dining > Dinnerware', 'Home & Kitchen > Kitchen & Dining > Kitchen Tools',
    'Home & Kitchen > Bedding & Bath', 'Home & Kitchen > Home Decor', 'Home & Kitchen > Lighting', 'Home & Kitchen > Cleaning & Laundry',
    'Home & Kitchen > Garden & Outdoor', 'Home & Kitchen > Tools & Home Improvement',
    'Appliances > Refrigerators & Freezers', 'Appliances > Washing Machines', 'Appliances > Air Conditioners & Fans', 'Appliances > Kitchen Appliances', 'Appliances > Small Appliances',
    'Beauty & Personal Care > Skincare', 'Beauty & Personal Care > Hair Care', 'Beauty & Personal Care > Wigs & Extensions', 'Beauty & Personal Care > Makeup',
    'Beauty & Personal Care > Fragrances', 'Beauty & Personal Care > Bath & Body', 'Beauty & Personal Care > Men''s Grooming', 'Beauty & Personal Care > Tools & Accessories',
    'Health & Wellness > Vitamins & Supplements', 'Health & Wellness > Medical Supplies', 'Health & Wellness > Personal Care',
    'Groceries & Food > Food Staples', 'Groceries & Food > Snacks & Confectionery', 'Groceries & Food > Beverages', 'Groceries & Food > Spices & Seasoning', 'Groceries & Food > Fresh & Frozen',
    'Baby & Toys > Baby Gear', 'Baby & Toys > Diapering', 'Baby & Toys > Feeding', 'Baby & Toys > Toys & Games', 'Baby & Toys > Nursery',
    'Sports & Outdoors > Exercise & Fitness', 'Sports & Outdoors > Team Sports', 'Sports & Outdoors > Camping & Hiking', 'Sports & Outdoors > Cycling',
    'Automotive > Car Parts > Engine', 'Automotive > Car Parts > Brakes', 'Automotive > Car Parts > Tyres & Wheels', 'Automotive > Car Parts > Batteries',
    'Automotive > Car Electronics', 'Automotive > Car Care & Accessories', 'Automotive > Motorcycles & Parts',
    'Books & Stationery > Books > Educational', 'Books & Stationery > Books > Fiction', 'Books & Stationery > Books > Religious',
    'Books & Stationery > Stationery', 'Books & Stationery > Art & Craft',
    'Pets > Pet Food', 'Pets > Pet Accessories',
    'Building & Industrial > Building Materials', 'Building & Industrial > Electrical', 'Building & Industrial > Plumbing', 'Building & Industrial > Safety & Security', 'Building & Industrial > Machinery',
    'Agriculture > Seeds & Plants', 'Agriculture > Farm Tools', 'Agriculture > Animal Feed',
    'Other > Everything Else'
  ];
BEGIN
  FOREACH entry IN ARRAY paths LOOP
    PERFORM vh_ensure_path(string_to_array(entry, ' > '));
  END LOOP;
END $$;

ALTER TABLE products ADD COLUMN category_id uuid REFERENCES categories(id) ON DELETE SET NULL;
CREATE INDEX products_category_idx ON products (category_id);

-- Products made before the tree existed used a short list of names; move them to the nearest leaf.
UPDATE products SET category_id = vh_ensure_path(CASE category
    WHEN 'Men''s clothing' THEN ARRAY['Fashion','Men','Clothing','T-shirts & Polos']
    WHEN 'Women''s clothing' THEN ARRAY['Fashion','Women','Clothing','Dresses']
    WHEN 'Kids'' clothing' THEN ARRAY['Fashion','Kids','Boys Clothing']
    WHEN 'Footwear' THEN ARRAY['Fashion','Men','Footwear','Sneakers']
    WHEN 'Bags' THEN ARRAY['Fashion','Women','Bags','Handbags']
    WHEN 'Accessories' THEN ARRAY['Fashion','Women','Accessories','Sunglasses']
    WHEN 'Jewellery' THEN ARRAY['Fashion','Women','Jewellery','Necklaces']
    WHEN 'Fabrics' THEN ARRAY['Fashion','Fabrics & Tailoring','Ankara']
    WHEN 'Others' THEN ARRAY['Other','Everything Else']
  END)
WHERE category IN ('Men''s clothing','Women''s clothing','Kids'' clothing','Footwear','Bags','Accessories','Jewellery','Fabrics','Others');

DROP FUNCTION vh_ensure_path(text[]);

-- ---------------------------------------------------------------- payouts
CREATE TABLE bank_accounts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  seller_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  bank_name text NOT NULL CHECK (length(trim(bank_name)) BETWEEN 2 AND 100),
  account_number text NOT NULL CHECK (account_number ~ '^[0-9]{10}$'),
  account_name text NOT NULL CHECK (length(trim(account_name)) BETWEEN 2 AND 120),
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (seller_id, bank_name, account_number)
);

-- The bank details are copied onto each request so a later edit never changes where money was sent.
CREATE TABLE withdrawals (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  seller_id uuid NOT NULL REFERENCES users(id),
  amount numeric(12,2) NOT NULL CHECK (amount > 0),
  fee numeric(12,2) NOT NULL DEFAULT 0 CHECK (fee >= 0),
  currency text NOT NULL CHECK (currency IN ('NGN', 'USD', 'GBP', 'EUR')),
  bank_name text NOT NULL,
  account_number text NOT NULL,
  account_name text NOT NULL,
  status text NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'paid', 'rejected', 'cancelled')),
  admin_note text,
  reference text,
  requested_at timestamptz NOT NULL DEFAULT now(),
  decided_by uuid REFERENCES users(id) ON DELETE SET NULL,
  decided_at timestamptz,
  CHECK (fee < amount)
);
CREATE INDEX withdrawals_seller_idx ON withdrawals (seller_id, requested_at DESC);
CREATE INDEX withdrawals_open_idx ON withdrawals (requested_at) WHERE status = 'requested';

-- A withdrawal takes its money out of the wallet at once; a rejection or cancellation puts it back.
ALTER TABLE wallet_entries ADD COLUMN withdrawal_id uuid REFERENCES withdrawals(id) ON DELETE SET NULL;
CREATE UNIQUE INDEX wallet_entries_withdrawal_kind_unique ON wallet_entries (withdrawal_id, kind) WHERE withdrawal_id IS NOT NULL;

-- ---------------------------------------------------------------- store profiles
CREATE TABLE store_profiles (
  seller_id uuid PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  tagline text NOT NULL DEFAULT '' CHECK (length(tagline) <= 160),
  description text NOT NULL DEFAULT '' CHECK (length(description) <= 3000),
  phone text NOT NULL DEFAULT '' CHECK (length(phone) <= 30),
  support_email text NOT NULL DEFAULT '' CHECK (length(support_email) <= 255),
  return_policy text NOT NULL DEFAULT '' CHECK (length(return_policy) <= 3000),
  shipping_policy text NOT NULL DEFAULT '' CHECK (length(shipping_policy) <= 3000),
  logo text,
  banner text,
  vacation boolean NOT NULL DEFAULT false,
  vacation_message text NOT NULL DEFAULT '' CHECK (length(vacation_message) <= 300),
  updated_at timestamptz NOT NULL DEFAULT date_trunc('milliseconds', now())
);
