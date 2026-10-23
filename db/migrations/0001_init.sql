-- DDLX Vendor: standalone PostgreSQL schema (no Supabase / auth.users dependency).
-- Access rules that used to live in row-level-security policies and triggers are
-- enforced in the server functions under src/lib/*.functions.ts.

CREATE TYPE app_role AS ENUM ('admin', 'user');
CREATE TYPE application_status AS ENUM ('draft', 'submitted', 'needs_corrections', 'approved', 'rejected');

CREATE TABLE users (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  email text NOT NULL,
  password_hash text NOT NULL,
  email_confirmed_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX users_email_unique ON users (lower(email));

CREATE TABLE sessions (
  token_hash text PRIMARY KEY,
  user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires_at timestamptz NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX sessions_user_idx ON sessions (user_id);

CREATE TABLE password_resets (
  token_hash text PRIMARY KEY,
  user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires_at timestamptz NOT NULL,
  used_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX password_resets_user_idx ON password_resets (user_id);

CREATE TABLE user_roles (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role app_role NOT NULL,
  UNIQUE (user_id, role)
);

-- updated_at is stored at millisecond precision so the value that travels to the
-- browser (a JS Date/ISO string) compares equal in optimistic-concurrency checks.
CREATE TABLE seller_applications (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
  status application_status NOT NULL DEFAULT 'draft',
  current_step int NOT NULL DEFAULT 1,
  personal jsonb NOT NULL DEFAULT '{}'::jsonb,
  business jsonb NOT NULL DEFAULT '{}'::jsonb,
  brand jsonb NOT NULL DEFAULT '{}'::jsonb,
  registration jsonb NOT NULL DEFAULT '{}'::jsonb,
  declaration jsonb NOT NULL DEFAULT '{}'::jsonb,
  applicant_email text,
  admin_comment text,
  reviewed_by uuid,
  reviewed_at timestamptz,
  submitted_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT date_trunc('milliseconds', now()),
  updated_at timestamptz NOT NULL DEFAULT date_trunc('milliseconds', now())
);
CREATE UNIQUE INDEX seller_applications_email_unique ON seller_applications (lower(applicant_email))
  WHERE applicant_email IS NOT NULL AND status <> 'rejected';

CREATE TABLE seller_verifications (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  application_id uuid NOT NULL UNIQUE REFERENCES seller_applications(id),
  user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  status application_status NOT NULL DEFAULT 'draft',
  current_step integer NOT NULL DEFAULT 1 CHECK (current_step BETWEEN 1 AND 8),
  details jsonb NOT NULL DEFAULT '{}'::jsonb,
  documents jsonb NOT NULL DEFAULT '{}'::jsonb,
  admin_comment text,
  reviewed_by uuid,
  reviewed_at timestamptz,
  submitted_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT date_trunc('milliseconds', now()),
  updated_at timestamptz NOT NULL DEFAULT date_trunc('milliseconds', now())
);

CREATE TABLE seller_products (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 200),
  description text NOT NULL DEFAULT '',
  sku text NOT NULL CHECK (length(trim(sku)) BETWEEN 1 AND 100),
  category text NOT NULL DEFAULT 'Clothing',
  price numeric(12,2) NOT NULL DEFAULT 0 CHECK (price >= 0),
  currency text NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN','USD','GBP','EUR')),
  stock integer NOT NULL DEFAULT 0 CHECK (stock >= 0),
  low_stock_threshold integer NOT NULL DEFAULT 5 CHECK (low_stock_threshold >= 0),
  status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','ready','archived')),
  images text[] NOT NULL DEFAULT '{}' CHECK (cardinality(images) <= 6),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (user_id, sku)
);
