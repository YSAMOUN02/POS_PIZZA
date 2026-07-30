<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Product status is now a 3-state value:
     *   1 = Enable            (on sale — shown to cashier)
     *   2 = Disable           (hidden from cashier, not sellable)
     *   3 = Under development  (chef editing/testing before publishing; hidden, not sellable)
     *
     * Historically it was a 0/1 flag (0 = disabled). Fold those old 0/NULL rows
     * into the new "2 = Disable" so nothing is left in the ambiguous 0 state.
     */
    public function up(): void
    {
        DB::table('product')->where('status', 0)->orWhereNull('status')->update(['status' => 2]);
    }

    public function down(): void
    {
        // Best-effort reverse: 2/3 → 0 (disabled), 1 stays enabled.
        DB::table('product')->whereIn('status', [2, 3])->update(['status' => 0]);
    }
};
