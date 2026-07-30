<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Variants of one dish are separate product rows sharing a name, so they'd
     * otherwise list alphabetically ("Large, Medium, Small"). sort_order lets the
     * chef put them in the order that makes sense — S, M, L, XL.
     */
    public function up(): void
    {
        Schema::table('product', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('variant')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
