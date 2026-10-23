# Ddlx Doorway

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

Requirements: Node.js 20 or newer, and either Docker Desktop or any PostgreSQL 14+ server.

```powershell
# 1. Install packages
npm install

# 2. Start a local database (skip if you already have Postgres)
docker compose up -d

# 3. Create your settings file, then edit DATABASE_URL if you use your own Postgres
Copy-Item .env.example .env

# 4. Create the tables
npm run db:migrate

# 5. Create the admin account (pick your own password)
npm run db:admin -- admin@ddlxapparel.com "YourStrongPassword1!"

# 6. Run the app
npm run dev
```

Open the address that `npm run dev` prints, then sign in at `/auth`.

### Everyday commands

| Task | Command |
| --- | --- |
| Reset any user's password | `npm run db:password -- user@example.com "NewPassword1!"` |
| Make another admin | `npm run db:admin -- other@example.com "Password1!"` |
| Apply new database changes | `npm run db:migrate` |
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
