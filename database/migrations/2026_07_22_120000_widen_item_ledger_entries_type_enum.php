<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * item_ledger_entries.type was a DB enum limited to ('product','service') —
     * every purchase/sale ledger entry for an 'expence', 'raw_material',
     * 'cooking_product', or 'packaging_material' item was silently stored as
     * type='' (MySQL isn't in strict mode here, so it coerces instead of
     * erroring). Widen it to match product.type exactly.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE item_ledger_entries MODIFY type ENUM('service', 'product', 'expence', 'raw_material', 'cooking_product', 'packaging_material') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE item_ledger_entries MODIFY type ENUM('product', 'service') NULL");
    }
};
