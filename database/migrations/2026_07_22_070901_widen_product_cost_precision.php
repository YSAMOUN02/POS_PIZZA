<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // Raw materials are often costed per gram/ml (e.g. $0.003), which decimal(10,2)
    // silently truncates to $0.00 — breaks recipe costing and consumption ledger accuracy.
    public function up(): void
    {
        Schema::table('product', function (Blueprint $table) {
            $table->decimal('cost', 12, 6)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product', function (Blueprint $table) {
            $table->decimal('cost', 10, 2)->default(0)->change();
        });
    }
};
