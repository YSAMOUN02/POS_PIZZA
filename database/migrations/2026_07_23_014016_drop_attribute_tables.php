<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The attribute system (Attributes / AttributeValues / product tags) is
     * replaced by variant rows + typed recipe lines (component / add_on).
     * Order matters: pivot first, then values, then attributes (FK chain).
     */
    public function up(): void
    {
        Schema::dropIfExists('product_attribute_value');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attributes');
    }

    /**
     * Reverse the migrations — recreates the empty tables (data is not restored).
     */
    public function down(): void
    {
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('status')->default(1);
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('value');
            $table->boolean('status')->default(1);
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('product_attribute_value', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('product')->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained('attribute_values')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'attribute_value_id'], 'product_attribute_value_unique');
        });
    }
};
