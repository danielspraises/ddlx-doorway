# Go-live checklist (Namecheap + WooCommerce)

Do these in order. After each stage, open **Admin → Settings → Run system check**; anything marked "Fix" must be cleared before moving on.

## 1. Install Vendor Hub (about 20 minutes)
1. cPanel → Select PHP Version: PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `gd`, `openssl`. In Options, set `upload_max_filesize` and `post_max_size` to 16M.
2. Create the subdomain `vendor.ddlx.shop`, turn on AutoSSL, create the MySQL database and user.
3. Upload `vendorhub/` (not `tests/` or `dev/`), open `https://vendor.ddlx.shop/install`, fill the form, save the API key and webhook secret.
4. Sign in as admin → Settings: set Vendor Hub address, Marketplace address (`https://ddlx.shop`), email sender, commission, payout hold.
5. Run the system check. Clear every "Fix".

## 2. Connect WooCommerce
1. Upload and activate `wordpress-plugin/verola-connector` on ddlx.shop.
2. WooCommerce → Vendor Hub: paste the address, API key and webhook secret.
3. Run the system check again: "Marketplace connection" must say Connected.
4. Take a database backup of the WordPress site first (the plugin adds custom order statuses).

## 3. Rehearsal with one test seller
| Step | You should see |
|---|---|
| Register a seller, finish the application and verification | Admin gets notifications; approve both |
| Add a product with a photo, submit it | Appears in Admin → Products; approve it |
| Check ddlx.shop | The product is listed; its SKU is unique |
| Buy it on ddlx.shop with a small real or test payment | Order appears in the seller's Orders tab within ~15 s; stock drops |
| Seller sets Processing, then Ready for shipping | WooCommerce order shows the same status |
| Admin marks Shipped, then Delivered (try once from Vendor Hub, once from WooCommerce) | Both sides agree; seller earnings move from pending to available after the payout hold |
| Seller requests a withdrawal; admin marks it paid | Wallet drops; seller is notified |
| Refund the order in WooCommerce | Seller earnings reversed; stock restored if chosen. Return the customer's money in the payment gateway yourself |
| Import 3 products by CSV | Created as drafts |

If a step fails, note the exact message and Admin → System check output; the logs are in `storage/logs`.

## 4. Before real sellers arrive
- Back up the database in cPanel and download `storage/`; repeat weekly.
- Change the admin password, keep a second admin or staff account.
- Delete the test seller and test orders (or leave them if the marketplace needs them for reconciliation).
- Confirm email delivery: send a password reset to yourself. If it lands in spam, add SPF/DKIM for ddlx.shop in cPanel → Email Deliverability.

## 5. New in this release: check these too
- **Plugin 1.2.0** (upload and activate again): after the first sync, open a product on ddlx.shop and look for the level badge, and check WooCommerce → Marketing → Coupons for seller codes.
- Create a code in a test seller's Promotions tab, wait a minute, use it at checkout, and confirm the order shows in Vendor Hub with "Used 1".
- Leave a review on a test product and confirm the seller overview shows the rating within a couple of minutes.
- Admin → Settings: add the Anthropic key if you want the AI assistant; set the Seller levels table (perks start at 0).

## 6. Paystack (do in test mode first)
1. Paystack dashboard → Settings → API Keys & Webhooks: copy the **test secret key**. Set the **test webhook URL** to `https://vendor.ddlx.shop/api/paystack/webhook`.
2. Settings → Preferences: turn **off** "Confirm transfers before sending" (otherwise every transfer waits for an OTP).
3. Vendor Hub → Admin → Settings → Paystack: paste the key, press **Test connection**. It should show your test balance.
4. Upload plugin **1.3.0**. Place a test order paid through Paystack; the order in Vendor Hub should show a Paystack payment reference under Admin → Refunds once refunded.
5. Payout test: a seller requests a withdrawal (add the bank account choosing the bank from the list), admin presses **Send with Paystack**. Status goes Sending → Paid when Paystack confirms. Test a failure by using Paystack's test failure account numbers: the money must return to the seller's wallet.
6. Refund test: refund a test order in Vendor Hub or WooCommerce, open Admin → Refunds, press **Refund with Paystack**. It should show Sending, then Refunded.
7. When all pass: replace the test key and webhook URL with the **live** ones, fund your Paystack balance, and repeat one small real withdrawal.

Rules the app enforces: transfers and refunds only start from an admin click, a second click cannot send twice, Paystack is used only for NGN, and a manual "Mark paid / Mark done" is still available if you prefer your bank.

## 7. Installable app (PWA)
Vendor Hub installs like an app on phones and computers. It needs HTTPS (your live domain will have it).
- **Android / Chrome / Edge (phone or desktop):** open the site, then use **Install app** in the account menu (or the address bar install icon).
- **iPhone / iPad:** open the site in Safari, tap Share, then **Add to Home Screen**.
- The app stores only its look (CSS, JS, icons) for speed, never pages or account data. Offline it shows a "You are offline" page.
- After you change `sw.js`, bump `VERSION` inside it so phones pick up the new files.
- For Play Store / App Store listings later, wrap this same site with PWABuilder or Capacitor.

## 8. Email (SMTP)
Admin → Settings → Email sending. Easiest: a free Brevo account (SMTP & API → SMTP): server `smtp-relay.brevo.com`, port 587, TLS, your SMTP login and key. Set the sender address (same section above) to one Brevo has verified, press **Send me a test email**, and check your inbox and spam. If SMTP fails, Vendor Hub falls back to the host's built-in mail automatically.

## 9. Link existing marketplace sellers (do this before opening to everyone)
Upload plugin **1.4.0** first. For each seller who already sells on ddlx.shop:
1. The seller registers on Vendor Hub, finishes the application and verification, and you approve both.
2. Admin → Sellers → **Link to marketplace**. The list shows their store with a "Same email" or "Same store name" hint; press **Link** on the right one. (Wrong one? **Remove link** works until something has been imported.)
3. **Import products**: their existing products (photos, price, stock, variations) are copied into Vendor Hub and tied to the same product on the store, so nothing duplicates. Products whose SKU already exists in Vendor Hub, or of unsupported types (grouped, external), are listed and left on the store.
4. **Import past orders**: sales history appears in their reports and counts towards their level. Finished orders are history only and add nothing to the wallet. Orders still on their way are paid out normally after delivery. Money Dokan already owes them stays in Dokan; settle it there.
5. From then on, edits, stock and prices are made in Vendor Hub and follow to the store within minutes; new orders arrive as usual. Their store page and address on ddlx.shop are not changed.

Tip: link one seller first and check one product and one order on both sides before doing the rest. Do not edit that seller's products in Dokan afterwards; Vendor Hub will overwrite them.
