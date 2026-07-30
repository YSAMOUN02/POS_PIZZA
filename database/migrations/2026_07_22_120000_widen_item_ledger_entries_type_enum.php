<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * item_ledger_entries.type was a DB enum limited to ('product','service') —
     * every ledger entry for an 'expence', 'raw_material', 'cooking_product' or
     * 'packaging_material' item couldn't be stored under its real type. Widen it to
     * match product.type. On MySQL that's a real ENUM; on SQL Server it's an
     * nvarchar + CHECK constraint, so we just drop the CHECK (app validates values).
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE item_ledger_entries MODIFY type ENUM('service', 'product', 'expence', 'raw_material', 'cooking_product', 'packaging_material') NULL");
            return;
        }
        $this->dropColumnCheckConstraints('item_ledger_entries', 'type');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE item_ledger_entries MODIFY type ENUM('product', 'service') NULL");
        }
    }

    private function dropColumnCheckConstraints(string $table, string $column): void
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return;
        }
        $rows = DB::select(
            "SELECT cc.name AS name
               FROM sys.check_constraints cc
               INNER JOIN sys.columns col
                 ON col.object_id = cc.parent_object_id AND col.column_id = cc.parent_column_id
              WHERE cc.parent_object_id = OBJECT_ID(?) AND col.name = ?",
            [$table, $column]
        );
        foreach ($rows as $r) {
            DB::statement("ALTER TABLE [{$table}] DROP CONSTRAINT [{$r->name}]");
        }
    }
};
