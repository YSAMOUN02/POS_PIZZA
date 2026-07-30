<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE product MODIFY type ENUM('service', 'product', 'expence', 'raw_material', 'cooking_product') DEFAULT 'product'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE product MODIFY type ENUM('service', 'product', 'expence', 'raw_material') DEFAULT 'product'");
    }
};
