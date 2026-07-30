<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each routing step (Knead dough, Bake...) carries its own labour/operation
     * cost. Their sum becomes the variant's routing_cost, which is added on top
     * of material cost to give the finished good's total cost.
     */
    public function up(): void
    {
        Schema::table('product_recipe_steps', function (Blueprint $table) {
            $table->decimal('cost', 12, 4)->default(0)->after('instruction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_recipe_steps', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }
};
