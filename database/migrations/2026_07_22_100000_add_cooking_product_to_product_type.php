<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // See 2026_07_22_090000_add_raw_material_to_product_type for the MySQL-vs-SQL-Server
    // rationale. On SQL Server the CHECK constraint was already dropped by that
    // earlier migration, so this is a no-op there.
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE product MODIFY type ENUM('service', 'product', 'expence', 'raw_material', 'cooking_product') DEFAULT 'product'");
            return;
        }
        $this->dropColumnCheckConstraints('product', 'type');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE product MODIFY type ENUM('service', 'product', 'expence', 'raw_material') DEFAULT 'product'");
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
