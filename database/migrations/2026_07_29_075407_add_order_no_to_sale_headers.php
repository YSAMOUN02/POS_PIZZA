<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily kitchen-order docket number (ORDER-001 …). Assigned once on the sale
     * order and carried onto its invoice, so both documents share ONE order no.
     * Indexed so the docket reprint / lookup by order no is fast.
     */
    public function up(): void
    {
        Schema::table('sale_order_headers', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_order_headers', 'order_no')) {
                $table->string('order_no', 32)->nullable()->after('document_no')->index();
            }
        });

        Schema::table('sale_invoice_headers', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_invoice_headers', 'order_no')) {
                $table->string('order_no', 32)->nullable()->after('source_no')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_order_headers', function (Blueprint $table) {
            if (Schema::hasColumn('sale_order_headers', 'order_no')) {
                $table->dropIndex(['order_no']);
                $table->dropColumn('order_no');
            }
        });

        Schema::table('sale_invoice_headers', function (Blueprint $table) {
            if (Schema::hasColumn('sale_invoice_headers', 'order_no')) {
                $table->dropIndex(['order_no']);
                $table->dropColumn('order_no');
            }
        });
    }
};
