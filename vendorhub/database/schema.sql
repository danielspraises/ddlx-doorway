CREATE TABLE users (
  id CHAR(36) NOT NULL PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX users_email_unique ON users (email);

CREATE TABLE sessions (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  csrf CHAR(40) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  CONSTRAINT sessions_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX sessions_user_idx ON sessions (user_id);

CREATE TABLE password_resets (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  used_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  CONSTRAINT resets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  ip VARCHAR(64) NOT NULL,
  created_at DATETIME(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX login_attempts_idx ON login_attempts (email, created_at);

CREATE TABLE user_roles (
  user_id CHAR(36) NOT NULL,
  role VARCHAR(20) NOT NULL,
  PRIMARY KEY (user_id, role),
  CONSTRAINT roles_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_members (
  user_id CHAR(36) NOT NULL PRIMARY KEY,
  permissions VARCHAR(255) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(36) NULL,
  created_at DATETIME(3) NOT NULL,
  CONSTRAINT staff_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seller_applications (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  current_step INT NOT NULL DEFAULT 1,
  personal LONGTEXT NOT NULL,
  business LONGTEXT NOT NULL,
  brand LONGTEXT NOT NULL,
  registration LONGTEXT NOT NULL,
  declaration LONGTEXT NOT NULL,
  applicant_email VARCHAR(255) NULL,
  admin_comment TEXT NULL,
  reviewed_by CHAR(36) NULL,
  reviewed_at DATETIME(3) NULL,
  submitted_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  CONSTRAINT app_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX seller_applications_user_unique ON seller_applications (user_id);
CREATE INDEX seller_applications_status_idx ON seller_applications (status, updated_at);
CREATE INDEX seller_applications_email_idx ON seller_applications (applicant_email);

CREATE TABLE seller_verifications (
  id CHAR(36) NOT NULL PRIMARY KEY,
  application_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  current_step INT NOT NULL DEFAULT 1,
  details LONGTEXT NOT NULL,
  documents LONGTEXT NOT NULL,
  admin_comment TEXT NULL,
  reviewed_by CHAR(36) NULL,
  reviewed_at DATETIME(3) NULL,
  submitted_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  CONSTRAINT ver_app_fk FOREIGN KEY (application_id) REFERENCES seller_applications(id),
  CONSTRAINT ver_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX seller_verifications_app_unique ON seller_verifications (application_id);
CREATE UNIQUE INDEX seller_verifications_user_unique ON seller_verifications (user_id);
CREATE INDEX seller_verifications_status_idx ON seller_verifications (status, updated_at);

CREATE TABLE categories (
  id CHAR(36) NOT NULL PRIMARY KEY,
  parent_id CHAR(36) NULL,
  name VARCHAR(80) NOT NULL,
  position INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL,
  CONSTRAINT cat_parent_fk FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX categories_parent_idx ON categories (parent_id, position);

CREATE TABLE products (
  id CHAR(36) NOT NULL PRIMARY KEY,
  seller_id CHAR(36) NOT NULL,
  name VARCHAR(200) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  product_type VARCHAR(10) NOT NULL DEFAULT 'simple',
  sku VARCHAR(100) NOT NULL,
  brand VARCHAR(100) NOT NULL DEFAULT '',
  category VARCHAR(80) NOT NULL DEFAULT '',
  category_id CHAR(36) NULL,
  tags TEXT NOT NULL,
  short_description VARCHAR(500) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  regular_price DECIMAL(12,2) NULL,
  sale_price DECIMAL(12,2) NULL,
  sale_starts_at DATETIME(3) NULL,
  sale_ends_at DATETIME(3) NULL,
  manage_stock TINYINT(1) NOT NULL DEFAULT 1,
  stock INT NOT NULL DEFAULT 0,
  low_stock_threshold INT NOT NULL DEFAULT 5,
  allow_backorders TINYINT(1) NOT NULL DEFAULT 0,
  weight_kg DECIMAL(8,3) NULL,
  length_cm DECIMAL(8,1) NULL,
  width_cm DECIMAL(8,1) NULL,
  height_cm DECIMAL(8,1) NULL,
  attributes TEXT NOT NULL,
  featured_image VARCHAR(200) NULL,
  gallery TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  live TINYINT(1) NOT NULL DEFAULT 0,
  published_snapshot LONGTEXT NULL,
  admin_comment TEXT NULL,
  submitted_at DATETIME(3) NULL,
  reviewed_by CHAR(36) NULL,
  reviewed_at DATETIME(3) NULL,
  published_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  CONSTRAINT prod_seller_fk FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT prod_cat_fk FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX products_slug_unique ON products (slug);
CREATE UNIQUE INDEX products_sku_unique ON products (sku);
CREATE INDEX products_seller_idx ON products (seller_id, updated_at);
CREATE INDEX products_status_idx ON products (status);
CREATE INDEX products_updated_idx ON products (updated_at);
CREATE INDEX products_category_idx ON products (category_id);

CREATE TABLE product_variations (
  id CHAR(36) NOT NULL PRIMARY KEY,
  product_id CHAR(36) NOT NULL,
  sku VARCHAR(100) NOT NULL,
  options TEXT NOT NULL,
  regular_price DECIMAL(12,2) NULL,
  sale_price DECIMAL(12,2) NULL,
  sale_starts_at DATETIME(3) NULL,
  sale_ends_at DATETIME(3) NULL,
  stock INT NOT NULL DEFAULT 0,
  image VARCHAR(200) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  position INT NOT NULL DEFAULT 0,
  CONSTRAINT var_prod_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX product_variations_sku_unique ON product_variations (sku);
CREATE INDEX product_variations_product_idx ON product_variations (product_id, position);

CREATE TABLE orders (
  id CHAR(36) NOT NULL PRIMARY KEY,
  marketplace_order_id VARCHAR(100) NOT NULL,
  number VARCHAR(100) NOT NULL DEFAULT '',
  currency CHAR(3) NOT NULL,
  customer_name VARCHAR(200) NOT NULL DEFAULT '',
  ship_city VARCHAR(100) NOT NULL DEFAULT '',
  ship_state VARCHAR(100) NOT NULL DEFAULT '',
  placed_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX orders_market_unique ON orders (marketplace_order_id);

CREATE TABLE order_items (
  id CHAR(36) NOT NULL PRIMARY KEY,
  order_id CHAR(36) NOT NULL,
  seller_id CHAR(36) NOT NULL,
  product_id CHAR(36) NULL,
  variation_id CHAR(36) NULL,
  name VARCHAR(200) NOT NULL,
  sku VARCHAR(100) NOT NULL DEFAULT '',
  options TEXT NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  commission_rate DECIMAL(5,2) NOT NULL,
  commission DECIMAL(12,2) NOT NULL,
  net DECIMAL(12,2) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'paid',
  fulfilment VARCHAR(20) NOT NULL DEFAULT 'placed',
  carrier VARCHAR(80) NOT NULL DEFAULT '',
  tracking_number VARCHAR(100) NOT NULL DEFAULT '',
  created_at DATETIME(3) NOT NULL,
  CONSTRAINT oi_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT oi_seller_fk FOREIGN KEY (seller_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX order_items_seller_idx ON order_items (seller_id, created_at);
CREATE INDEX order_items_order_idx ON order_items (order_id);

CREATE TABLE order_item_log (
  id CHAR(36) NOT NULL PRIMARY KEY,
  order_item_id CHAR(36) NOT NULL,
  status VARCHAR(20) NOT NULL,
  source VARCHAR(12) NOT NULL,
  note VARCHAR(250) NOT NULL DEFAULT '',
  created_at DATETIME(3) NOT NULL,
  CONSTRAINT oil_item_fk FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX order_item_log_idx ON order_item_log (order_item_id, created_at);

CREATE TABLE bank_accounts (
  id CHAR(36) NOT NULL PRIMARY KEY,
  seller_id CHAR(36) NOT NULL,
  bank_name VARCHAR(100) NOT NULL,
  account_number CHAR(10) NOT NULL,
  account_name VARCHAR(120) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  CONSTRAINT bank_seller_fk FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX bank_accounts_unique ON bank_accounts (seller_id, bank_name, account_number);

CREATE TABLE withdrawals (
  id CHAR(36) NOT NULL PRIMARY KEY,
  seller_id CHAR(36) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL,
  bank_name VARCHAR(100) NOT NULL,
  account_number CHAR(10) NOT NULL,
  account_name VARCHAR(120) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'requested',
  admin_note VARCHAR(500) NULL,
  reference VARCHAR(120) NULL,
  requested_at DATETIME(3) NOT NULL,
  decided_by CHAR(36) NULL,
  decided_at DATETIME(3) NULL,
  CONSTRAINT wd_seller_fk FOREIGN KEY (seller_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX withdrawals_seller_idx ON withdrawals (seller_id, requested_at);
CREATE INDEX withdrawals_status_idx ON withdrawals (status, requested_at);

CREATE TABLE wallet_entries (
  id CHAR(36) NOT NULL PRIMARY KEY,
  seller_id CHAR(36) NOT NULL,
  kind VARCHAR(12) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  order_item_id CHAR(36) NULL,
  withdrawal_id CHAR(36) NULL,
  note VARCHAR(200) NOT NULL DEFAULT '',
  available_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  CONSTRAINT wal_seller_fk FOREIGN KEY (seller_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX wallet_entries_seller_idx ON wallet_entries (seller_id, created_at);
CREATE UNIQUE INDEX wallet_item_kind_unique ON wallet_entries (order_item_id, kind);
CREATE UNIQUE INDEX wallet_withdrawal_kind_unique ON wallet_entries (withdrawal_id, kind);

CREATE TABLE store_profiles (
  seller_id CHAR(36) NOT NULL PRIMARY KEY,
  tagline VARCHAR(160) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  phone VARCHAR(30) NOT NULL DEFAULT '',
  support_email VARCHAR(255) NOT NULL DEFAULT '',
  return_policy TEXT NOT NULL,
  shipping_policy TEXT NOT NULL,
  logo VARCHAR(200) NULL,
  banner VARCHAR(200) NULL,
  vacation TINYINT(1) NOT NULL DEFAULT 0,
  vacation_message VARCHAR(300) NOT NULL DEFAULT '',
  updated_at DATETIME(3) NOT NULL,
  CONSTRAINT store_seller_fk FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  audience VARCHAR(12) NOT NULL,
  seller_id CHAR(36) NULL,
  type VARCHAR(20) NOT NULL,
  message VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX events_created_idx ON events (created_at);

CREATE TABLE settings (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
