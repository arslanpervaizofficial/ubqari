# Update 21 — Billing stock warning, Capital Report rework, Thermal print fix

Drop these 7 files into your project at the same relative paths
(overwriting the existing ones). No new migrations, no `composer install` /
`npm install` needed.

- app/Http/Controllers/POSController.php
- app/Http/Controllers/ReportController.php
- app/Http/Controllers/CashManagementController.php
- routes/web.php
- resources/views/reports/capital.blade.php
- resources/views/pos/index.blade.php
- resources/views/pos/invoice.blade.php

## 1. Billing: stock warning now fires every time, not just once

Root cause: when a quantity typed in Billing exceeded available stock,
the server rejected the request outright (a one-off popup), and the
quantity-input's own change handler never called the function that shows
the "Tip" banner at all (only the discount field did). So after the
first popup, later attempts looked like they were silently accepted.

Now: exceeding stock no longer errors — it clamps the quantity to
whatever's actually available and returns a warning, and the quantity
field's change handler shows that warning every time, exactly like the
existing "discount exceeds max" warning already did. Same fix applied to
adding a product from search (incrementing an already-in-cart item past
stock now warns instead of erroring).

## 2. Total Capital Report — Profit & Loss removed, Standard Margin only

The "Profit & Loss" card (supplier-discount minus customer-discount
formula) is gone. Standard Margin (Revenue − Est. Cost of Goods Sold) is
now the only profit figure on the report. Monthly Breakdown's columns
changed to match: Cash In, Cash Out, Revenue, Est. COGS, Gross Margin,
Net Profit (previously Supplier/Customer Discounts + Discount
Differential).

## 3. Total Revenue — now calculated from Products, not Purchase Orders

Total Revenue (and the COGS it's compared against) is now calculated
from each sold OrderItem's linked Product row — quantity × the
product's CURRENT sale_price for revenue, quantity × CURRENT
purchase_price for COGS — instead of the order's stored total. This
keeps both figures on the same footing and means neither is sourced
from Purchase Orders. It's a live query with nothing cached, so it's
automatically correct on every page load: right after a new sale is
billed, right after new stock is received, and right after a product's
price is edited on the Products page.

## 4. Liabilities — "+ Add Capital" and "+ Add Liability" on the report

The Capital Report page previously only *displayed* the Total
Liabilities number with a link elsewhere. It now has two small forms
directly on the page:
- **+ Add Capital** — records a "Cash In" entry (same as the Expenses
  page), the standard way to inject more money into the business.
- **+ Add Liability** — records money owed to a person: pick an
  existing person from Cash Management, or type a new name to add them
  on the spot, then enter the amount. Same effect as adding a "Borrow"
  entry on the Cash Management page, just reachable from the report.

(New route: `POST cash-management/quick-add`, admin/manager only, same
as the rest of this page.)

## 5. Thermal print — resized for an actual 3-inch (76mm) roll

Previous CSS targeted 80mm at 11px, which is wider than a real 3"
roll's printable area — that's what was cutting off the right edge of
each line. Now: `@page` size is explicitly set to 76mm, the receipt
card is kept to 72mm (a small safety margin inside the physical 76mm),
and font sizes are reduced (9px body / 8px table / 12px title) with
tighter padding so a normal item + totals list fits cleanly on the
roll. The A4 print button/layout is untouched.
