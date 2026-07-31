<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_recipe_lines', function (Blueprint $table) {
            $table->id();
            // The cooking product / variant this recipe line belongs to.
            $table->foreignId('product_id')->constrained('product')->cascadeOnDelete();
            // The raw material consumed (must be a product of type raw_material).
            // SQL Server refuses a SECOND ON DELETE CASCADE foreign key into the same
            // parent table ("may cause cycles or multiple cascade paths"). MySQL allows
            // it. So keep cascade on MySQL; on SQL Server use NO ACTION for this one —
            // a raw material referenced by a recipe line simply can't be hard-deleted
            // until the line is removed (the product_id cascade above is unaffected).
            $rawMaterial = $table->foreignId('raw_material_id')->constrained('product');
            DB::getDriverName() === 'sqlsrv'
                ? $rawMaterial->noActionOnDelete()
                : $rawMaterial->cascadeOnDelete();
            $table->decimal('quantity', 12, 4); // amount of raw material consumed per 1 unit sold
            $table->string('unit')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recipe_lines');
    }
};
