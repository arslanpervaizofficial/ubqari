# Update 19

## What changed

Nothing new was broken here — this re-sends the **exact same "Choose File" button fix from Update 16**. Looking at your screenshot, it's still showing the plain unstyled browser file input (`Choose File | No fi...osen` as one grey box, with "Import" as plain text instead of a button) — that's exactly what it looked like *before* Update 16's fix, so it seems that specific update didn't end up applied on the server (easy to miss since Update 17 and 18 only touched controller files, not this view — if Update 16 got skipped anywhere in the sequence, this page alone would still look old while everything else caught up).

The fix itself: the real `<input type="file">` is hidden (`class="hidden"`), and a normal styled button ("Choose File") plus a small filename label sit in its place — clicking that button opens the OS file picker, same as before, it just doesn't show the ugly native control anymore. Same pattern the Backup & Restore page already uses.

## How to update
1. Extract into `C:\xampp\htdocs\ubqari-pos`, overwrite when prompted — this zip only has one file, `resources/views/products/index.blade.php`.
2. Run:
   ```
   php artisan view:clear
   ```
3. **Hard refresh with cache clear** — Ctrl+Shift+R (or Ctrl+F5), not just Ctrl+F5, in case the browser cached the old page. If it still looks the same after that, let me know and I'll check for anything else that could be overriding it.
4. Confirm: Products page → the file-picker area should now show a proper dark-bordered "Choose File" button + "No file chosen" label next to it, and "Import" should be a solid button — not plain text.
