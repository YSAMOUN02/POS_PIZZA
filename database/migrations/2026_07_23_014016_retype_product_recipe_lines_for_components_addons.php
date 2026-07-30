<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pizza variant's recipe now has two kinds of lines:
     *  - component: always consumed when the dish is prepared (dough, sauce, cheese)
     *  - add_on:    an optional extra defined on the variant (e.g. "Add Mushroom" -5g,
     *               "Extra Mushroom" -8g) — consumed only when the order asks for it.
     * unit_id links the line to a real unit of measure so consumption can convert
     * into the raw material's base unit (e.g. line entered in kg → stock kept in g).
     */
    public function up(): void
    {
        Schema::table('product_recipe_lines', function (Blueprint $table) {
            $table->string('line_type', 20)->default('component')->after('raw_material_id');
            $table->string('addon_name')->nullable()->after('line_type');    // display name for add_on lines
            $table->decimal('extra_price', 10, 2)->default(0)->after('unit'); // charge when the add-on is chosen
            $table->foreignId('unit_id')->nullable()->after('unit')
                ->constrained('units_of_measure')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_recipe_lines', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn(['line_type', 'addon_name', 'extra_price', 'unit_id']);
        });
    }
};
