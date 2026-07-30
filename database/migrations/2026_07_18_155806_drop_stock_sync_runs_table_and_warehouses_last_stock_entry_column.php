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
        Schema::dropIfExists('stock_sync_runs');

        Schema::table('warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('warehouses', 'last_stock_entry')) {
                $table->dropColumn('last_stock_entry');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouses', 'last_stock_entry')) {
                $table->string('last_stock_entry')->nullable();
            }
        });

        Schema::create('stock_sync_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('status')->default('queued');
            $t->integer('total')->default(0);
            $t->integer('done')->default(0);
            $t->integer('added')->default(0);
            $t->integer('skipped')->default(0);
            $t->json('skipped_items')->nullable();
            $t->text('message')->nullable();
            $t->timestamps();
        });
    }
};
