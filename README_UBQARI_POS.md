# Ubqari POS — Complete Build (Phases 0–8 core)

## ⚠️ Read this first
This code was written **without being able to run PHP/Composer/MySQL** in the environment that generated it. It follows Laravel conventions carefully and the logic has been reasoned through line by line, but **it has not been executed even once**. Treat this as a strong first draft, not a tested product. Go through the Setup steps below, then work through the Test Checklists from the original phase plan — especially **Phase 4 (hold/resume logic)**, which is the riskiest part of any POS system.

## What's included
| Phase | Status |
|---|---|
| 0 — Project setup | You run `composer create-project`, then overlay these files |
| 1 — Auth & roles (admin/manager/cashier) + auto-logout | ✅ Implemented |
| 2 — Products & inventory + price history log | ✅ Implemented |
| 3 — Wholesale customers + ledger | ✅ Implemented |
| 4 — Core POS billing, live stock, hold/resume | ✅ Implemented (test thoroughly!) |
| 5 — Payments (cash/bank/split), invoice print, quotation mode, re-order | ✅ Implemented |
| 6 — Low-stock alerts, out-of-stock report | ✅ Dashboard alert implemented. SMS/WhatsApp alert and a dedicated wastage-entry screen are **not** included — only the `stock_movements` table (type=wastage) is ready for it. |
| 7 — Suppliers & Purchase Orders, batch cost tracking | ✅ Implemented (batch tracking is simplified: latest cost overwrites `purchase_price`, not a full batch ledger) |
| 8 — Reports & analytics | ✅ Core reports (sales, best-selling, cashier-wise, cash reconciliation) implemented. PDF/Excel export, Chart.js dashboard graphs **not** included. |
| 9 — Loyalty points, WhatsApp invoice send, full audit trail, multi-branch | ❌ Not built — these are genuinely separate subsystems (payment gateway/API integrations) best tackled one at a time once the core is proven stable |
| 10 — Shared hosting deployment | Guide provided separately, unchanged from before |

## Setup
1. Install Laragon/XAMPP with PHP 8.1+, MySQL, and Composer.
2. `composer create-project laravel/laravel ubqari-pos`
3. Extract this zip's contents **into** the `ubqari-pos` folder, overwriting:
   - `app/` (adds Models, Controllers, Middleware)
   - `database/migrations/` and `database/seeders/DatabaseSeeder.php`
   - `routes/web.php`
   - `resources/views/`
   - `bootstrap/app.php` **— only if your Laravel version is 11+ (uses this new bootstrap style). If `composer create-project` gave you Laravel 10 or older (has `app/Http/Kernel.php`), don't overwrite bootstrap/app.php — instead open `app/Http/Kernel.php` and add `'role' => \App\Http\Middleware\RoleMiddleware::class,` to the `$middlewareAliases` array.**
4. Copy `.env.example` values into your `.env`, set your DB credentials, run:
   ```
   php artisan key:generate
   php artisan migrate --seed
   php artisan serve
   ```
5. Log in at `http://127.0.0.1:8000/login` with:
   - Email: `admin@ubqari.pos`
   - Password: `password`
   **Change this immediately** via Users → Edit once logged in.

## Test order (recommended)
Go phase by phase using the checklists in the original plan, in this order, since each depends on the last having data:
1. Login as admin, create a Manager and Cashier user, confirm role restrictions.
2. Add a few products with stock and min_stock thresholds.
3. Add a wholesale customer with a discount %.
4. **Go to the POS screen and stress-test the hold/resume flow**: start an order, add items, click "+ New Order" mid-way, confirm the first order appears in "Held Orders," resume it, confirm items are intact, complete it, confirm stock actually decreased in Products.
5. Try a split payment and check the invoice.
6. Create a Purchase Order, mark it received, confirm stock increased.
7. Check Reports for the date range you just tested.

## Known simplifications / things to double check
- **Session-scoped "current order":** each cashier's active order is tracked via their browser session, not a global variable — this means two cashiers on two different computers/browsers won't collide, but if a cashier clears cookies mid-order, that in-progress order becomes an orphan (it'll still exist in the DB as `in_progress` but won't show in Held Orders since it's neither `hold` nor tied to a live session). You may want a scheduled command later that auto-holds any `in_progress` order untouched for X minutes.
- **Discount is customer-level only** (no per-item discount) — matches your spec (Phase 3).
- **Auto-logout** is done client-side (JS timer) for simplicity; combine with Laravel's `SESSION_LIFETIME` (already set to 15 in `.env.example`) for a server-side backstop too.
- **Batch/lot cost tracking** (Phase 7) is simplified — it updates `purchase_price` to the latest PO cost rather than keeping a full historical batch ledger with per-batch COGS. If you need accurate profit-per-batch, this needs to be extended.
- No automated tests were written — given the complexity of Phase 4 in particular, I'd strongly recommend writing a few PHPUnit feature tests for the hold/resume/complete flow once it's running, so future changes don't silently break it.

## Deployment
Use the Phase 10 deployment guide already shared earlier in this conversation — nothing about shared-hosting deployment changes with this build.
