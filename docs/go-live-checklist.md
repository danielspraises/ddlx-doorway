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
