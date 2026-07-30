<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `cost_amount` = the monetary VALUE of the stock movement, at cost.
     *
     * Every existing amount column (line_amount / net_amount / grand_total_amount)
     * is price/sale-based and its sign flips meaning per feature (negative on a
     * purchase = payable, positive on an adjustment = cost in…), which is exactly
     * why the ledger was "hard to decide" from. cost_amount fixes that with ONE
     * rule for every feature:
     *
     *     cost_amount = (entry_type = positive ? +1 : -1) * |quantity| * unit_cost
     *
     * The sign comes from entry_type, NOT the quantity column — quantity's sign is
     * inconsistent across features (sales store it negative, Recipe Consumption
     * stores it positive with entry_type = negative). So:
     *
     *   +  stock IN  (value added):  Purchase, Purchase (kitchen), Stock Sync,
     *                                Sale Return, Transfer Receipt, Kitchen
     *                                Production, Adjustment (+)
     *   -  stock OUT (value removed): Sale, Recipe Consumption, Purchase Return,
     *                                 Transfer Shipment, Adjustment (-)
     *
     * Each row already carries warehouse_id + bin_id, so SUM(cost_amount) grouped
     * by warehouse/bin gives inventory value per location; the negative rows sum to
     * COGS. remaining_quantity is untouched — its FIFO logic stays exactly as-is.
     */
    public function up(): void
    {
        Schema::table('item_ledger_entries', function (Blueprint $table) {
            $table->decimal('cost_amount', 18, 6)->default(0)->after('unit_cost');
        });

        // Backfill history with the same rule the model now applies on save.
        DB::statement("
            UPDATE item_ledger_entries
            SET cost_amount = ROUND(
                (CASE WHEN entry_type = 'negative' THEN -1 ELSE 1 END)
                * ABS(quantity) * unit_cost,
                6
            )
        ");
    }

    public function down(): void
    {
        Schema::table('item_ledger_entries', function (Blueprint $table) {
            $table->dropColumn('cost_amount');
        });
    }
};
