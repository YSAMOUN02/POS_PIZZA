<?php

namespace Database\Seeders;

use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

class UnitOfMeasureSeeder extends Seeder
{
    /**
     * Standard catalog — idempotent (updateOrCreate), safe to re-run.
     * Weight/volume include a real base (g, ml) so kg/L can be converted through
     * product_unit_conversions. The "other"/count units are serving/packaging
     * units already used across the menu (Plate, Whole, Glass...) — each stands
     * alone; conversions between them are product-specific, not universal, so
     * they're added per-product via the Kitchen "Alternate Units" picker instead.
     */
    public function run(): void
    {
        $units = [
            // Weight
            ['code' => 'g',  'name' => 'Gram',      'category' => 'weight'],
            ['code' => 'kg', 'name' => 'Kilogram',  'category' => 'weight'],
            // Volume
            ['code' => 'ml', 'name' => 'Milliliter', 'category' => 'volume'],
            ['code' => 'l',  'name' => 'Liter',      'category' => 'volume'],
            // Count
            ['code' => 'pcs',   'name' => 'Piece',  'category' => 'count'],
            ['code' => 'box',   'name' => 'Box',    'category' => 'count'],
            ['code' => 'case',  'name' => 'Case',   'category' => 'count'],
            ['code' => 'dozen', 'name' => 'Dozen',  'category' => 'count'],
            // Serving units already in use across the current menu
            ['code' => 'plate',  'name' => 'Plate',  'category' => 'other'],
            ['code' => 'whole',  'name' => 'Whole',  'category' => 'other'],
            ['code' => 'glass',  'name' => 'Glass',  'category' => 'other'],
            ['code' => 'cup',    'name' => 'Cup',    'category' => 'other'],
            ['code' => 'bottle', 'name' => 'Bottle', 'category' => 'other'],
            ['code' => 'can',    'name' => 'Can',    'category' => 'other'],
        ];

        foreach ($units as $unit) {
            UnitOfMeasure::updateOrCreate(['code' => $unit['code']], $unit + ['created_by' => 'System Setup']);
        }
    }
}
