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
        Schema::table('product', function (Blueprint $table) {
            // The structured unit stock/recipes are tracked in (e.g. "g" for Mozzarella).
            // The existing free-text `unit` column is untouched for display/back-compat —
            // this is the new structured link used for conversions.
            $table->foreignId('base_unit_id')->nullable()->after('unit')
                ->constrained('units_of_measure')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product', function (Blueprint $table) {
            $table->dropForeign(['base_unit_id']);
            $table->dropColumn('base_unit_id');
        });
    }
};
