<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes on the columns the app actually filters / joins / sorts / groups on.
     * Idempotent: each index is only created if the table + all its columns exist
     * and an index of that name isn't already present, so it's safe to re-run and
     * won't clash with the FK-generated indexes already in place.
     *
     * [table, [columns...], index_name]
     */
    private array $indexes = [
        // Stock — the hottest table: every lookup filters product+warehouse, and
        // lot generation/consumption scans by lot. Previously only bin_id indexed.
        ['warehouse_product', ['product_id', 'warehouse_id'], 'wp_product_warehouse_idx'],
        ['warehouse_product', ['lot'], 'wp_lot_idx'],

        // Product catalog — type/status is the ubiquitous filter combo; name drives
        // sibling-variant grouping; code/barcode drive search + existence checks.
        ['product', ['type', 'status'], 'product_type_status_idx'],
        ['product', ['name'], 'product_name_idx'],
        ['product', ['code'], 'product_code_idx'],
        ['product', ['bar_code'], 'product_bar_code_idx'],
        ['product', ['category_id'], 'product_category_id_idx'],

        // Item ledger — reports filter by document_type + posting_date + type, and
        // trace back to a source row.
        ['item_ledger_entries', ['document_type', 'posting_date'], 'ile_doctype_date_idx'],
        ['item_ledger_entries', ['type'], 'ile_type_idx'],
        ['item_ledger_entries', ['source_table', 'source_id'], 'ile_source_idx'],

        // Sale invoice lines — kitchen "pending vs prepared" filter + daily reports.
        ['sale_invoice_lines', ['prepared_at'], 'sil_prepared_at_idx'],
        ['sale_invoice_lines', ['document_no'], 'sil_document_no_idx'],
        ['sale_invoice_lines', ['created_at'], 'sil_created_at_idx'],

        // Sale invoice headers.
        ['sale_invoice_headers', ['invoice_number'], 'sih_invoice_number_idx'],
        ['sale_invoice_headers', ['invoice_date'], 'sih_invoice_date_idx'],
        ['sale_invoice_headers', ['customer_id'], 'sih_customer_id_idx'],
        ['sale_invoice_headers', ['sale_order_id'], 'sih_sale_order_id_idx'],

        // Sale orders.
        ['sale_order_headers', ['document_no'], 'soh_document_no_idx'],
        ['sale_order_headers', ['customer_id'], 'soh_customer_id_idx'],
        ['sale_order_headers', ['status'], 'soh_status_idx'],
        ['sale_order_headers', ['order_date'], 'soh_order_date_idx'],
        ['sale_order_lines', ['sale_order_id'], 'sol_sale_order_id_idx'],
        ['sale_order_lines', ['product_id'], 'sol_product_id_idx'],
        ['sale_order_lines', ['document_no'], 'sol_document_no_idx'],

        // Purchasing.
        ['purchase_headers', ['posting_date'], 'ph_posting_date_idx'],
        ['purchase_lines', ['lot'], 'pl_lot_idx'],

        // Recipe (BOM) — component/add-on split filters by product_id + line_type.
        ['product_recipe_lines', ['product_id', 'line_type'], 'prl_product_linetype_idx'],

        // Expenses.
        ['expenses', ['expense_date'], 'exp_expense_date_idx'],
        ['expenses', ['product_id'], 'exp_product_id_idx'],
        ['expenses', ['status'], 'exp_status_idx'],

        // Quotations.
        ['quotations', ['customer_id'], 'quo_customer_id_idx'],
        ['quotations', ['status'], 'quo_status_idx'],
        ['quotation_lines', ['product_id'], 'ql_product_id_idx'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $col) {
                if (!Schema::hasColumn($table, $col)) {
                    continue 2; // skip this index if any column is missing
                }
            }
            if ($this->indexExists($table, $name)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($columns, $name) {
                $t->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (!Schema::hasTable($table) || !$this->indexExists($table, $name)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($name) {
                $t->dropIndex($name);
            });
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        $driver = DB::getDriverName();

        // Query system catalogs directly per driver. We deliberately AVOID
        // Schema::getIndexes(), because Laravel 12's SQL Server implementation of it
        // uses STRING_AGG(...) WITHIN GROUP, which fails on databases whose
        // compatibility level is below 140 (pre-2017 semantics).
        if ($driver === 'sqlsrv') {
            return DB::selectOne(
                'SELECT 1 AS found
                   FROM sys.indexes i
                   INNER JOIN sys.tables t ON t.object_id = i.object_id
                  WHERE t.name = ? AND i.name = ?',
                [$table, $name]
            ) !== null;
        }

        if ($driver === 'mysql') {
            return DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', $name)
                ->exists();
        }

        // Other drivers (sqlite, pgsql): Laravel's introspection is fine there.
        foreach (Schema::getIndexes($table) as $index) {
            if (strcasecmp($index['name'] ?? '', $name) === 0) {
                return true;
            }
        }
        return false;
    }
};
