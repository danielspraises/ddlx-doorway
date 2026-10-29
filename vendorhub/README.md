# DDLX Vendor Hub (PHP edition)

The seller hub for the DDLX marketplace (WooCommerce + Dokan at ddlx.shop). Plain PHP 8.1+ and MySQL/MariaDB, built for **Namecheap Stellar Plus** shared hosting: no Node, no Postgres, no VPS, no cron, no Composer.

It covers seller application, verification, product management with admin approval, categories (up to 4 levels), inventory, sales, order status shared with WooCommerce, wallet and withdrawals (released after delivery), store profile, staff accounts, settings, the marketplace feed and order webhook, a notifications inbox with optional email alerts, CSV downloads, an "Ask DDLX" help assistant (AI with built-in fallback), messaging between sellers and the DDLX team (direct, selected, announcements), two-step sign-in (authenticator app), bulk product upload by CSV, and sales dashboards for sellers and the admin.

## Deploy on Namecheap Stellar Plus

1. **cPanel → Select PHP Version**: choose PHP 8.1 or newer. Make sure `pdo_mysql`, `gd`, `curl`, `mbstring` and `fileinfo` are ticked.
2. **cPanel → Domains/Subdomains**: create `vendor.ddlx.shop`. Set its document root to `vendorhub/public` if cPanel lets you; otherwise point it at `vendorhub` (the included `.htaccess` blocks private folders and routes to `public/`).
3. **cPanel → MySQL Databases**: create a database and a user, give the user all privileges, and note the full names (cPanel adds a prefix).
4. **Upload** the contents of this `vendorhub/` folder (File Manager or FTP). Do not upload `tests/` or `dev/`.
5. Make sure `config/` and `storage/` are writable (permissions 755 normally work; use 775 if the installer complains).
6. Turn on **AutoSSL** for the subdomain, then open `https://vendor.ddlx.shop/install`. Enter the database details, the admin email and password, and the addresses. Save the **API key** and **webhook secret** it shows (they are always in Admin → Settings).
7. The installer refuses to run again once `config/config.php` exists.

Go-live checklist: `../docs/go-live-checklist.md`. After installing, run **Admin → Settings → Run system check**.

Backups: use cPanel → Backup (database) and download `storage/` occasionally; it holds uploaded images and verification documents.

## Test on your own computer (Windows)

You need PHP only. No MySQL, no XAMPP, no internet hosting. Local testing uses a throwaway SQLite database.

1. Install PHP: in PowerShell run `winget install PHP.PHP.8.3`, then close and reopen PowerShell and check `php -v`.
   (No winget? Download PHP 8.3 "Thread Safe" zip from windows.php.net, unzip to `C:\php`, and add it to PATH.)
2. Turn on the extensions: find your `php.ini` (`php --ini`; if there is none, copy `php.ini-development` to `php.ini` in the PHP folder). In it, remove the leading `;` from these lines: `extension=pdo_sqlite`, `extension=gd`, `extension=mbstring`, `extension=fileinfo`, `extension=curl`, `extension=openssl`.
3. In PowerShell, from the repo folder:

```powershell
cd vendorhub
php dev/setup.php          # creates the local database and the admin
php dev/demo.php           # optional: a verified demo seller with one live product
php -S 127.0.0.1:8000 -t public
```

4. Open http://127.0.0.1:8000. Admin: `admin@ddlxapparel.com` / `password123`. Demo seller: `seller@demo.test` / `password123`.
   Sign up another account to try the full application and verification flow yourself.
5. Pretend to be WooCommerce (open a second PowerShell window in `vendorhub`):

```powershell
php dev/market.php list                                  # live products and their ids
php dev/market.php paid <product_id> 2                   # a customer pays for 2 units
php dev/market.php status <order_id> shipped DHL TRK123  # or delivered, completed
php dev/market.php refund <order_id>
```

Then watch the seller's Sales tab and the admin's Orders tab update. The marketplace address is empty locally, so Vendor Hub does not try to call a real WooCommerce store. Emails are not sent locally.

To start again from nothing: `php dev/setup.php --reset`. Do not upload the `dev/` folder to your host.

## Connect WooCommerce

Install `wordpress-plugin/verola-connector` on ddlx.shop (WooCommerce → Vendor Hub). Paste the Vendor Hub address, API key and webhook secret. Details: `../docs/marketplace-api.md`.

- Feed: `GET /api/marketplace/products` with `Authorization: Bearer <key>`.
- Orders: `POST /api/marketplace/orders`, signed HMAC-SHA256 over `"<timestamp>.<body>"`, headers `X-Verola-Timestamp` and `X-Verola-Signature` (5 minute window). Sending the same order twice is safe.

## How live updates work

Shared hosting cannot keep connections open, so pages poll `/api/pulse` every 12 seconds and refresh themselves when something changed.

## Tests

`php tests/run.php` runs a self-test on SQLite (no MySQL needed). It was developed and smoke-tested against SQLite only; the SQL is written to be portable but run the install on a staging copy first.
