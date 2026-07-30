<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cold storage for old, fully-settled item ledger rows.
     *
     * `CREATE TABLE ... LIKE` gives an EXACT copy of the live table's columns and
     * indexes, so archived rows keep their original id/entry_no and stay a faithful
     * historical record. Nothing reads this table in daily operation, so it can
     * grow large without ever slowing the POS or reports — see the ledger:archive
     * command, which only ever moves rows with remaining_quantity = 0 (never live
     * stock).
     */
    public function up(): void
    {
        if (Schema::hasTable('item_ledger_entries_archive')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            // Exact structural copy (columns + indexes), no rows.
            DB::statement('CREATE TABLE item_ledger_entries_archive LIKE item_ledger_entries');
            return;
        }

        // SQL Server has no "CREATE TABLE ... LIKE". SELECT ... INTO with a false
        // predicate copies the column definitions (types + nullability) with zero
        // rows. Indexes/keys aren't copied, which is fine — this is cold storage
        // that nothing reads in daily operation.
        DB::statement('SELECT * INTO item_ledger_entries_archive FROM item_ledger_entries WHERE 1 = 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('item_ledger_entries_archive');
    }
};
