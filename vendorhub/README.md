# DDLX Vendor Hub (PHP edition)

The seller hub for the DDLX marketplace (WooCommerce + Dokan at ddlx.shop). Plain PHP 8.1+ and MySQL/MariaDB, built for **Namecheap Stellar Plus** shared hosting: no Node, no Postgres, no VPS, no cron, no Composer.

It covers seller application, verification, product management with admin approval, categories (up to 4 levels), inventory, sales, wallet and withdrawals, store profile, staff accounts, settings, and the marketplace feed and order webhook.

## Deploy on Namecheap Stellar Plus

1. **cPanel → Select PHP Version**: choose PHP 8.1 or newer. Make sure `pdo_mysql`, `gd`, `curl`, `mbstring` and `fileinfo` are ticked.
2. **cPanel → Domains/Subdomains**: create `vendor.ddlx.shop`. Set its document root to `vendorhub/public` if cPanel lets you; otherwise point it at `vendorhub` (the included `.htaccess` blocks private folders and routes to `public/`).
3. **cPanel → MySQL Databases**: create a database and a user, give the user all privileges, and note the full names (cPanel adds a prefix).
4. **Upload** the contents of this `vendorhub/` folder (File Manager or FTP). Do not upload `tests/`.
5. Make sure `config/` and `storage/` are writable (permissions 755 normally work; use 775 if the installer complains).
6. Turn on **AutoSSL** for the subdomain, then open `https://vendor.ddlx.shop/install`. Enter the database details, the admin email and password, and the addresses. Save the **API key** and **webhook secret** it shows (they are always in Admin → Settings).
7. The installer refuses to run again once `config/config.php` exists.

Backups: use cPanel → Backup (database) and download `storage/` occasionally; it holds uploaded images and verification documents.

## Connect WooCommerce

Install `wordpress-plugin/verola-connector` on ddlx.shop (WooCommerce → Vendor Hub). Paste the Vendor Hub address, API key and webhook secret. Details: `../docs/marketplace-api.md`.

- Feed: `GET /api/marketplace/products` with `Authorization: Bearer <key>`.
- Orders: `POST /api/marketplace/orders`, signed HMAC-SHA256 over `"<timestamp>.<body>"`, headers `X-Verola-Timestamp` and `X-Verola-Signature` (5 minute window). Sending the same order twice is safe.

## How live updates work

Shared hosting cannot keep connections open, so pages poll `/api/pulse` every 12 seconds and refresh themselves when something changed.

## Tests

`php tests/run.php` runs a self-test on SQLite (no MySQL needed). It was developed and smoke-tested against SQLite only; the SQL is written to be portable but run the install on a staging copy first.
