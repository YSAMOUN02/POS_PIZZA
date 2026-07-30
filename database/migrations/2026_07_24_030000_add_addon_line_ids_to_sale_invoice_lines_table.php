<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which optional add-ons the cashier picked on this line, as a list of
     * product_recipe_lines.id.
     *
     * Add-on rows belong to a single variant, so the id alone carries that
     * variant's own quantity — "Add Mushroom" is 10g on S and 20g on L because
     * they are two different rows. Storing the ids (not just the display label)
     * is what lets the kitchen consume the right amount at Mark Prepared.
     */
    public function up(): void
    {
        Schema::table('sale_invoice_lines', function (Blueprint $table) {
            $table->json('addon_line_ids')->nullable()->after('variant');
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('addon_line_ids');
        });
    }
};
