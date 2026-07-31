<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * The app assumes two currencies always exist: a default (USD, factor 1) and
     * Riel (៛) for the receipt's "Total (៛)" line. Without them the POS/purchasing
     * screens used to 404 (Currency::firstOrFail on the Riel row). Idempotent —
     * keyed on `code`, so re-running never duplicates. `is_default` is set directly
     * because it isn't in the model's $fillable.
     */
    public function run(): void
    {
        $usd = Currency::updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'factor' => 1]
        );
        $usd->is_default = 1;
        $usd->save();

        $riel = Currency::updateOrCreate(
            ['code' => '៛'],
            ['name' => 'Riel', 'factor' => 4100]   // USD → ៛; adjust in Manage Currency
        );
        // Only USD is the default; don't flip an existing choice, just ensure Riel isn't it.
        $riel->is_default = 0;
        $riel->save();
    }
}
