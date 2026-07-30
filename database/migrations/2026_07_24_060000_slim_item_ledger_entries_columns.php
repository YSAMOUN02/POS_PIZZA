<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slim the item ledger for long-run storage. Drop the denormalized copies that
     * can be re-derived by joining on the ids we keep (customer_id, vendor_id,
     * product_id): barcode + customer/vendor name/phone/address. Also drop
     * payment_method (a transaction attribute sourced from the invoice/purchase
     * header when a report needs it, not stored per ledger row).
     *
     * Applied to both the live table and its archive so the two stay identical.
     */
    private array $drop = [
        'barcode',
        'customer_name',
        'customer_phone',
        'customer_address',
        'vendor_name',
        'payment_method',
    ];

    public function up(): void
    {
        // DEFERRED — intentionally a no-op. Dropping these columns requires the
        // ledger reader refactor first (GainCost + the ledger writers still read
        // customer_name / vendor_name / payment_method etc.). Running the drop
        // before that breaks the reports, so the slim is postponed until the
        // reader work is done. The column list is kept in $this->drop and the real
        // drop lives in down()'s inverse for when we re-enable it deliberately.
        return;
    }

    public function down(): void
    {
        foreach (['item_ledger_entries', 'item_ledger_entries_archive'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'barcode')) $t->string('barcode')->nullable();
                if (!Schema::hasColumn($table, 'customer_name')) $t->string('customer_name')->nullable();
                if (!Schema::hasColumn($table, 'customer_phone')) $t->string('customer_phone')->nullable();
                if (!Schema::hasColumn($table, 'customer_address')) $t->string('customer_address')->nullable();
                if (!Schema::hasColumn($table, 'vendor_name')) $t->string('vendor_name')->nullable();
                if (!Schema::hasColumn($table, 'payment_method')) $t->string('payment_method')->nullable();
            });
        }
    }
};
