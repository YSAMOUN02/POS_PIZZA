<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reset the system to a clean transactional state: wipe every transactional table
 * (sales, purchases, stock, ledger…) while KEEPING all master/config data
 * (users, products, warehouses, recipes, currencies…).
 *
 * Whitelist approach: a table is only ever emptied if it is NOT in the keep list
 * and NOT a framework table. Anything unrecognised is kept, never wiped — so a new
 * table can never be destroyed by accident. Always preview with --dry-run first.
 *
 *   php artisan data:truncate --dry-run     # show what would happen, change nothing
 *   php artisan data:truncate               # asks to confirm, then truncates
 *   php artisan data:truncate --force       # no prompt (scripts)
 */
class TruncateTransactions extends Command
{
    protected $signature = 'data:truncate
                            {--dry-run : List what would be truncated vs kept; change nothing}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Truncate all transactional data, keeping master/config tables (users, products, warehouses, recipes, currencies, etc.)';

    /** Master / configuration data to KEEP (your business setup). */
    private array $keepMaster = [
        'users',
        'user_warehouse',            // user ↔ warehouse assignment
        'product',
        'warehouses',
        'categories',
        'bins',
        'customers',
        'vendors',
        'currencies',                // exchange rate
        'pos_profiles',              // company profile
        'product_recipe_lines',      // recipe + components + add-ons
        'product_recipe_steps',      // routing / prep steps
        'units_of_measure',          // UoM definitions
        'product_unit_conversions',  // unit conversion
        'serial_no',                 // document-number counters — MUST persist so
                                     // numbers never reset/reuse across a truncate
        'table_queues',              // daily order-queue counter
    ];

    /** Framework / infrastructure tables to KEEP (never business data). */
    private array $keepFramework = [
        'migrations',
        'permissions',
        'permission_user',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    public function handle(): int
    {
        $keep = array_merge($this->keepMaster, $this->keepFramework);

        $all = DB::table('information_schema.tables')
            ->where('table_schema', DB::getDatabaseName())
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();

        $toTruncate = array_values(array_diff($all, $keep));
        $kept       = array_values(array_intersect($all, $keep));

        // Show the plan (with row counts) so nothing is a surprise.
        $this->line('');
        $this->info('KEEP (' . count($kept) . ' tables):');
        foreach ($kept as $t) {
            $this->line(sprintf('   %-30s %s rows', $t, DB::table($t)->count()));
        }
        $this->line('');
        $this->warn('TRUNCATE (' . count($toTruncate) . ' tables):');
        $total = 0;
        foreach ($toTruncate as $t) {
            $c = DB::table($t)->count();
            $total += $c;
            $this->line(sprintf('   %-30s %s rows', $t, $c));
        }
        $this->line('');
        $this->line("Total rows to be deleted: {$total}");

        if ($this->option('dry-run')) {
            $this->info('DRY RUN — nothing was changed.');
            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm('This permanently empties the TRUNCATE tables above. Continue?')) {
            $this->info('Aborted — nothing changed.');
            return self::SUCCESS;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($toTruncate as $t) {
                DB::table($t)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info('Done — ' . count($toTruncate) . ' tables truncated, ' . count($kept) . ' kept.');
        return self::SUCCESS;
    }
}
