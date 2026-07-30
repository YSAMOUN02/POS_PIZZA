<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Routing (labor/prep) cost for one cooking-product VARIANT — added on top of
     * the consumed raw/packaging material cost to give the finished-good cost when
     * the chef marks an order prepared. Per variant, so a Large and a Small can
     * carry different prep effort. Only meaningful for cooking_product rows.
     */
    public function up(): void
    {
        Schema::table('product', function (Blueprint $table) {
            $table->decimal('routing_cost', 10, 4)->default(0)->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('product', function (Blueprint $table) {
            $table->dropColumn('routing_cost');
        });
    }
};
