<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // MySQL uses a real ENUM type; SQL Server has no ENUM — Laravel's enum() there
    // is an nvarchar + a CHECK constraint. To accept a new value on SQL Server we
    // drop that CHECK (the allowed values are still enforced in the app layer:
    // ProductController store/update validate `type`).
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE product MODIFY type ENUM('service', 'product', 'expence', 'raw_material') DEFAULT 'product'");
            return;
        }
        $this->dropColumnCheckConstraints('product', 'type');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE product MODIFY type ENUM('service', 'product', 'expence') DEFAULT 'product'");
        }
        // Non-MySQL: leave the column as a free string (we don't re-narrow it).
    }

    // Drop every CHECK constraint attached to $table.$column on SQL Server.
    // Idempotent: if it was already dropped by an earlier widening migration this
    // finds nothing and does nothing.
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
