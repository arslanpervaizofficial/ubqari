<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Imports products from database/seeders/data/price_list.csv
     * (exported from the user's uploaded price_list.xlsx).
     *
     * Auto-generates:
     *  - SKU: first 3 letters of category + zero-padded row index, e.g. TAB-0001
     *  - Barcode: random unique 13-digit number (checked against existing rows
     *    in this run AND the database, so re-running the seeder never collides)
     *
     * purchase_price is left at 0 since the price list only gives sale rates —
     * update actual cost prices later via Products > Edit for accurate profit reports.
     */
    public function run(): void
    {
        $path = database_path('seeders/data/price_list.csv');

        if (!file_exists($path)) {
            $this->command->warn("price_list.csv not found at {$path} — skipping product import.");
            return;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle); // Index, Category, Product Name, Rate

        $usedBarcodes = [];
        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            [$index, $category, $name, $rate] = $row;

            $skuPrefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $category), 0, 3));
            $skuPrefix = $skuPrefix ?: 'PRD';
            $sku = $skuPrefix . '-' . str_pad($index, 4, '0', STR_PAD_LEFT);

            // Skip if this exact SKU already exists (safe to re-run the seeder)
            if (Product::where('sku', $sku)->exists()) {
                $skipped++;
                continue;
            }

            do {
                $barcode = (string) random_int(1000000000000, 9999999999999);
            } while (isset($usedBarcodes[$barcode]) || Product::where('barcode', $barcode)->exists());
            $usedBarcodes[$barcode] = true;

            Product::create([
                'name' => trim($name),
                'sku' => $sku,
                'barcode' => $barcode,
                'category' => trim($category),
                'unit' => 'piece',
                'purchase_price' => 0,
                'sale_price' => (float) $rate,
                'max_discount_percent' => 0,
                'stock' => 0,
                'min_stock' => null,
                'max_stock' => null,
            ]);
            $imported++;
        }

        fclose($handle);

        $this->command->info("Products imported: {$imported}, skipped (already existed): {$skipped}");
    }
}
