<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chosen add-ons (recipe-line ids) for a sale-order line, so the kitchen order
     * docket can print them at the order stage and shipOrderStock can carry them
     * onto the invoice line (which already has this column).
     */
    public function up(): void
    {
        Schema::table('sale_order_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_order_lines', 'addon_line_ids')) {
                $table->json('addon_line_ids')->nullable()->after('variant');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_order_lines', function (Blueprint $table) {
            if (Schema::hasColumn('sale_order_lines', 'addon_line_ids')) {
                $table->dropColumn('addon_line_ids');
            }
        });
    }
};
