<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Detailed companion record for kitchen preparation, richer than the (slimmed)
     * item ledger. One kitchen_order per prepared invoice line (the OUTPUT / dish),
     * with one kitchen_order_line per raw/packaging material CONSUMED — capturing
     * lot, unit, cost and whether it was a component or a chosen add-on.
     */
    public function up(): void
    {
        Schema::create('kitchen_order', function (Blueprint $table) {
            $table->id();
            $table->date('posting_date')->index();
            $table->string('document_no')->nullable()->index();   // invoice no
            $table->string('source_no')->nullable();              // FG item code
            $table->unsignedBigInteger('invoice_line_id')->nullable()->index();

            // Finished good (the output)
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('item_code')->nullable();
            $table->string('name')->nullable();
            $table->string('variant')->nullable();
            $table->string('category_name')->nullable();
            $table->decimal('qty', 18, 6)->default(0);
            $table->string('unit')->nullable();

            // Costs (per the whole prepared qty)
            $table->decimal('material_cost', 18, 6)->default(0);
            $table->decimal('routing_cost', 18, 6)->default(0);
            $table->decimal('fg_cost', 18, 6)->default(0);   // material + routing
            $table->decimal('sell_price', 18, 6)->default(0);

            $table->unsignedBigInteger('warehouse_id')->nullable()->index();
            $table->string('warehouse_name')->nullable();

            $table->string('prepared_by')->nullable();
            $table->string('created_by')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('kitchen_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kitchen_order_id')->index();
            $table->enum('line_type', ['component', 'add_on'])->default('component');

            $table->unsignedBigInteger('raw_material_id')->nullable()->index();
            $table->string('item_code')->nullable();
            $table->string('name')->nullable();

            $table->decimal('qty', 18, 6)->default(0);   // consumed, in base unit
            $table->string('unit')->nullable();          // base unit code
            $table->string('lot')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();

            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('cost_amount', 18, 6)->default(0); // qty * unit_cost
            $table->timestamps();

            $table->foreign('kitchen_order_id')->references('id')->on('kitchen_order')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_order_lines');
        Schema::dropIfExists('kitchen_order');
    }
};
