<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Categories are now scoped to the kind of product they classify, so a
     * raw-material category (Dairy, Flour) never shows up when categorising a
     * menu item, and vice versa.
     *   fg = finished goods: cooking_product / product / service
     *   rm = raw material
     *   pm = packaging material
     * Existing rows default to `fg` — they were all menu/sale categories.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('kind', 10)->default('fg')->after('name')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // SQL Server won't drop a column that still has an index on it, so drop
            // the index first (MySQL would drop it automatically with the column).
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
