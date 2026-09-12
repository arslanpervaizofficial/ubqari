# Update 20 — full resync (please read this one)

## Why this update looks different

Digging into today's 3 reports, two of them turned out to already be built and sitting in the codebase — just not actually live on your server:

- The Walk-in vs Registered Customer Report split (#1) — already fully built: a separate "Walk-in Customers" section with its own report page, Orders/Returns tables, and Admin-only delete on both.
- The `.closest()` click-fix for the Billing "✕" remove-item button (#3) — already in the code.
- The custom "Access Restricted" 403 page (#2) — already existed as its own file. Your screenshot shows a completely raw, unstyled 403 that isn't a Laravel page at all — styled or not — which is a strong sign it never actually reached your server.

This is the second time this's happened — the "Choose File" button issue a few updates back was the same story. Small diff-only zips are easy to apply out of order or skip by accident, and once one file falls behind, a fix that's actually already written can look like a brand new bug. So **this update ships the entire project**, not just the changed files — one clean folder replace, so your server is guaranteed to match everything built so far (Updates 1 through 20), instead of chasing individual files again.

**Please replace the whole `ubqari-pos` folder this time** (back up the old one first, and keep your real `.env` — the one in this zip is just a template) rather than extracting on top of it file-by-file.

## What's in this update

1. **Customer Report has two sections: Walk-in and Registered Customers.**
   Reports → Customers now shows a **Walk-in Customers** table at the top (orders count + total purchased) with its own **View Report**, listing every walk-in order and walk-in customer return — Admin gets Delete there too, same as a registered customer's page.

2. **Fixed: clicking ✕ to remove a Billing cart item didn't work.**
   The click handler was checking for a click on the button itself, but the ✕ is an icon *inside* the button, so almost every click landed on the icon, not the button, and did nothing. It now finds the nearest `.remove-item` button around wherever you actually clicked. On top of that, if removing an item ever fails for some other reason (session timeout, etc.), it now shows an error message instead of silently doing nothing — so a real problem is visible instead of just looking broken.

3. **Fixed: a permission-denied page showed the raw browser-style "403 Forbidden" screen.**
   Now shows a branded "Access Restricted" page with a plain-language explanation and a button back to Billing/Dashboard — everywhere in the app, not just one spot.

4. **New: Dashboard's Low Stock / Out of Stock reorder links are now Admin/Manager-only.**
   A Cashier now sees low-stock items as plain text, not a link into a page they can't open — and the Out-of-Stock popup no longer appears for them at all. This stops the 403 in your screenshot from happening in the first place, on top of #3 making it look better if it ever does happen somewhere else.

## How to update (please follow this exactly)
1. **Back up** your current `ubqari-pos` folder (rename it, e.g. `ubqari-pos-old`) — don't skip this.
2. **Copy your real `.env` file** somewhere safe first.
3. Extract this zip as the new `ubqari-pos` folder into `C:\xampp\htdocs\`.
4. **Put your real `.env` back** into the new folder (overwrite the placeholder one, and make sure it's named exactly `.env`, not `.env.example`).
5. From inside the folder, run:
   ```
   php artisan migrate
   php artisan view:clear
   php artisan route:clear
   php artisan config:clear
   php artisan cache:clear
   ```
6. **Ctrl+Shift+R** in the browser (not just a normal refresh).
7. Test:
   - Reports → Customers → two sections: "Walk-in Customers" and "Registered Customers", each with a working "View Report".
   - Log in as your cashier account → Dashboard → low-stock items are plain text (not clickable), no Out-of-Stock popup.
   - Billing → add an item → click ✕ → it's removed.
   - As a Cashier, open an Admin/Manager-only URL directly (e.g. `/purchase-orders/create`) → see the branded "Access Restricted" page, not a raw 403.

If any of this still doesn't show up after a full folder replace + hard refresh, that points to something on the server side — e.g. Apache/XAMPP actually serving a different folder than the one you're editing — worth double-checking the vhost/document root in that case.
