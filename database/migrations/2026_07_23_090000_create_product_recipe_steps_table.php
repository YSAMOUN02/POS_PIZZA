<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cooking routine — the ordered preparation steps for one cooking-product
     * VARIANT (a Large and a Small can be assembled differently), separate from
     * its bill of materials. One row per step.
     */
    public function up(): void
    {
        Schema::create('product_recipe_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('product')->cascadeOnDelete();
            $table->unsignedInteger('step_no');       // 1-based order
            $table->text('instruction');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'step_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recipe_steps');
    }
};
