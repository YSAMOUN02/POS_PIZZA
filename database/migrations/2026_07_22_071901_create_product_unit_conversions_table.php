<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('product')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units_of_measure')->cascadeOnDelete();
            // How many of the product's BASE unit equal 1 of this alternate unit.
            // e.g. base = g, unit = kg, factor = 1000  →  1 kg = 1000 g.
            $table->decimal('factor', 15, 6);
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'unit_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_unit_conversions');
    }
};
