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
        Schema::table('sale_invoice_lines', function (Blueprint $table) {
            // Set once a chef marks this cooking-product line as prepared —
            // that's the moment its recipe's raw materials get consumed
            // ("sell first, consume later"). Null/irrelevant for non-cooking lines.
            $table->timestamp('prepared_at')->nullable()->after('created_by');
            $table->string('prepared_by')->nullable()->after('prepared_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['prepared_at', 'prepared_by']);
        });
    }
};
