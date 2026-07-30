<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_sync_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('status')->default('queued');   // queued|running|done|failed
            $t->integer('total')->default(0);
            $t->integer('done')->default(0);
            $t->integer('added')->default(0);
            $t->integer('skipped')->default(0);
            $t->json('skipped_items')->nullable();
            $t->text('message')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('stock_sync_runs'); }
};
