# Vendor Hub

> **Hosting on Namecheap Stellar Plus?** Use the PHP edition in [`vendorhub/`](vendorhub/README.md). It needs only PHP and MySQL. The TypeScript/Postgres app below is kept for reference.


You are a programmer, developer, web designer.

Here is my blueprint

                    ┌─────────────────────┐
                    │  Become a DDLX      │
                    │      Seller         │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │     STAGE 1         │
                    │ Seller Application  │
                    └──────────┬──────────┘
                               │
                Personal → Business → Brand
                               │
                       → Registration
                               │
                       → Review & Submit
                               │
                               ▼
                    ┌─────────────────────┐
                    │   UNDER REVIEW      │
                    └──────────┬──────────┘
                               │
                    ┌──────────┴──────────┐
                    │                     │
              Correction              Approval
                    │                     │
                    ▼                     ▼
          ┌─────────────────┐    ┌──────────────────┐
          │   CORRECTION    │    │     STAGE 2      │
          │    REQUIRED     │    │   VERIFICATION   │
          └────────┬────────┘    └────────┬─────────┘
                   │                      │
                   ▼                      │
             Applicant fixes              │
                   │                      │
                   └──────► Review ◄──────┘
                                          │
                                          ▼
                                Identity Verification
                                          │
                                Passport Photograph
                                          │
                                Business Registration
                                          │
                                    Social Media
                                          │
                                   Address Details
                                          │
                                   Proof of Address
                                          │
                                  Business Presence
                                          │
                                    Declaration
                                          │
                                          ▼
                                ┌──────────────────┐
                                │   VERIFICATION   │
                                │    UNDER REVIEW  │
                                └────────┬─────────┘
                                         │
                          ┌──────────────┼──────────────┐
                          │              │              │
                     Correction       Approved       Rejected
                          │              │              │
                          ▼              ▼              ▼
                     Resubmit      SELLER APPROVED   CLOSED
                                         │
                                         ▼
                                ┌──────────────────┐
                                │  DDLX SELLER     │
                                │     CENTER       │
                                └──────────────────┘



I want each step to be saved

save and continue buttons


Access WordPress, see what you need so we can kick of the building

Keep the UI clean and modern

## Local setup (Windows PowerShell)

The app uses its own PostgreSQL database, email/password sign-in with server-side sessions,
and a private folder on disk for verification documents. There is no external backend.

Requirements: Node.js 20 or newer, and a PostgreSQL 14+ server (the Windows installer from
postgresql.org works; remember the password you set for the `postgres` user). Docker is optional.

```powershell
# 1. Install packages
npm install

# 2. Create the database (named `verola`) and user on your PostgreSQL, and write .env for you
#    (use the password you chose when installing PostgreSQL)
npm run db:setup -- "YourPostgresPassword"

# 3. Create the tables
npm run db:migrate

# 4. Create the admin account (pick your own password)
npm run db:admin -- admin@ddlxapparel.com "YourStrongPassword1!"

# 5. Check everything, then run the app
npm run db:check
npm run dev
```

Prefer Docker? Run `docker compose up -d` instead of step 2, copy `.env.example` to `.env`, and use the
port 5433 `DATABASE_URL` line from it.

Open the address that `npm run dev` prints, then sign in at `/auth`.

### Everyday commands

| Task | Command |
| --- | --- |
| Reset any user's password | `npm run db:password -- user@example.com "NewPassword1!"` |
| Make another admin | `npm run db:admin -- other@example.com "Password1!"` |
| Apply new database changes | `npm run db:migrate` |
| Diagnose database problems | `npm run db:check` |
| Run tests | `npm test` |

Passwords typed on the command line stay in your shell history; clear it if that matters.

### Notes

- Uploaded documents are saved in `STORAGE_DIR` (default `./storage`, ignored by git) and are only
  served to the owning seller and to admins.
- Email is not configured. "Forgot password?" prints a reset link in the terminal that runs
  `npm run dev`; use `npm run db:password` to reset an account directly.
- Public sign-up cannot use `ADMIN_EMAIL`; that account is created only with `npm run db:admin`.
- Google sign-in has been removed for now and can be added back with your own Google OAuth keys.
- Before deploying, build for a Node server target (the default build target of the Vite config
  is Cloudflare, which cannot open Postgres connections or write to disk).

## How people move through Vendor Hub

After sign-in everyone is sent to the right place for their role and progress (`src/lib/stage.ts`).
The header menu shows only the links that apply to them.

| Who | Lands on | Menu |
| --- | --- | --- |
| Admin | `/admin` | Applications, Verifications, Products and Staff, with pending-count badges |
| Staff (product reviewers) | `/admin?tab=products` | Product review |
| New seller or one needing corrections | `/apply` | My application |
| Application approved | `/verification` | My application, Verification |
| Verified seller | `/seller` | Seller Center, Products, Inventory, Sales |

Actions that change a stage (submitting an application, approving it, verifying, rejecting) refresh
the menu straight away. When an admin reviews an item the list returns to the next one waiting.

### Seller Center (`/seller`)

- **Overview**: balances, product counts, stock warnings and things that need attention.
- **Products**: a WooCommerce-style editor (`/product-editor`) with simple and variable products, sizes and colours
  as variations, featured image, gallery, category, brand, tags, regular and sale prices with start/end dates,
  stock, shipping details and per-variation photos.
- **Inventory**: filter by low or out of stock and adjust units per product or variation. Stock changes reach the
  marketplace straight away, with no review.
- **Sales**: every marketplace sale appears here live, with your earnings after commission, a pending balance
  during the payout hold, and what is available to withdraw.

### Publishing needs approval

Sellers submit a product for review. It reaches the marketplace only when an admin, or a staff account created by an
admin with the "review and publish products" permission, approves it. Editing a live product never changes what
customers see until the new version is approved. Admins create staff accounts under **Admin > Staff**.

### Marketplace connection

The marketplace (ddlx.shop, or ddlxapparel.com while testing) reads approved products and reports sales to Vendor Hub. Vendor Hub itself is served at vendor.ddlx.shop.
Set `MARKETPLACE_API_KEY` and `MARKETPLACE_WEBHOOK_SECRET` in `.env` and give the marketplace side the contract in
[docs/marketplace-api.md](docs/marketplace-api.md).

### Roadmap

Withdrawals (bank accounts and payout requests), store settings and profile, fulfilment status on orders,
the marketplace-side connector (WooCommerce plugin), Google sign-in, email delivery, login rate limiting.

## Categories, payouts and store profile

- **Categories**: a tree up to 4 levels deep (seeded in migration 0004). Admins manage it under Admin > Categories
  (add, rename, move, hide, delete); sellers pick the most specific one. The marketplace receives `category_path`
  and the connector builds the same nested WooCommerce categories.
- **Payouts**: sellers save up to 3 bank accounts and request withdrawals from available balance. The amount leaves the
  wallet at once; admins pay manually and record the transfer reference under Admin > Payouts, or reject (money returns).
  Set `MIN_WITHDRAWAL` and `WITHDRAWAL_FEE`.
- **Store**: logo, banner, about, contact, policies and vacation mode, synced to the marketplace.
