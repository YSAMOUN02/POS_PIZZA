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
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. g, kg, ml, l, pcs, box
            $table->string('name'); // Gram, Kilogram, Milliliter, Liter, Piece...
            // Which family a unit belongs to — conversions only ever happen within
            // the same category (grams convert to/from kilograms, never to pieces).
            $table->enum('category', ['weight', 'volume', 'count', 'other'])->default('other');
            $table->boolean('status')->default(1);
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units_of_measure');
    }
};
