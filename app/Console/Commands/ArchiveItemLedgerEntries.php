<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manual, period-based archiving of item_ledger_entries.
 *
 * You decide when and which period to move — nothing runs on a schedule. Old,
 * fully-settled rows are moved from the live table into item_ledger_entries_archive
 * so the live table stays small and fast.
 *
 * SAFETY: only rows with remaining_quantity = 0 are ever moved. A purchase/receipt
 * lot that still has stock on hand (remaining_quantity > 0) is left in the live
 * table no matter how old, because current stock and FIFO costing are computed from
 * it. So archiving can never change what's on hand.
 *
 *   php artisan ledger:archive --from=2024-01-01 --to=2024-12-31 --dry-run
 *   php artisan ledger:archive --from=2024-01-01 --to=2024-12-31
 */
class ArchiveItemLedgerEntries extends Command
{
    protected $signature = 'ledger:archive
                            {--from= : Start posting_date (YYYY-MM-DD) of the period to archive}
                            {--to= : End posting_date (YYYY-MM-DD) of the period to archive}
                            {--batch=1000 : Rows moved per transaction}
                            {--dry-run : Only report what WOULD move; change nothing}';

    protected $description = 'Move old, fully-settled item ledger rows for a chosen period into the archive table';

    private string $live = 'item_ledger_entries';
    private string $archive = 'item_ledger_entries_archive';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to   = (string) $this->option('to');
        $batch = max(100, (int) $this->option('batch'));
        $dry  = (bool) $this->option('dry-run');

        // ---- validate inputs ----
        if ($from === '' || $to === '') {
            $this->error('Both --from and --to are required, e.g. --from=2024-01-01 --to=2024-12-31');
            return self::FAILURE;
        }
        foreach (['from' => $from, 'to' => $to] as $label => $val) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) || !strtotime($val)) {
                $this->error("Invalid --{$label} date: {$val}. Use YYYY-MM-DD.");
                return self::FAILURE;
            }
        }
        if ($from > $to) {
            $this->error("--from ({$from}) is after --to ({$to}).");
            return self::FAILURE;
        }
        if (!Schema::hasTable($this->archive)) {
            $this->error("Archive table '{$this->archive}' is missing. Run: php artisan migrate");
            return self::FAILURE;
        }

        // Only columns present in BOTH tables — so an added column on the live table
        // later never breaks the copy.
        $cols = array_values(array_intersect(
            Schema::getColumnListing($this->live),
            Schema::getColumnListing($this->archive)
        ));
        $colList = '`' . implode('`,`', $cols) . '`';

        // ---- what's eligible (settled) vs held back (still has stock) ----
        $base = fn() => DB::table($this->live)
            ->whereBetween('posting_date', [$from, $to]);

        $eligible = (clone $base())->where('remaining_quantity', 0)->count();
        $heldStock = (clone $base())->where('remaining_quantity', '<>', 0)->count();
        $periodTotal = $eligible + $heldStock;

        $this->info("Period {$from} → {$to}");
        $this->line("  rows in period:        {$periodTotal}");
        $this->line("  eligible (settled):    {$eligible}");
        $this->line("  kept (still on hand):  {$heldStock}   ← never moved (live stock)");

        if ($eligible === 0) {
            $this->info('Nothing to archive.');
            return self::SUCCESS;
        }

        if ($dry) {
            $this->warn("DRY RUN — nothing changed. Re-run without --dry-run to move {$eligible} rows.");
            return self::SUCCESS;
        }

        // ---- move in batches: copy → delete, one transaction each ----
        $moved = 0;
        $bar = $this->output->createProgressBar($eligible);
        $bar->start();

        do {
            $ids = DB::table($this->live)
                ->whereBetween('posting_date', [$from, $to])
                ->where('remaining_quantity', 0)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($ids, $cols, $colList, &$moved) {
                // INSERT ... SELECT keeps the data on the DB server (no round-trip).
                DB::statement(
                    "INSERT INTO `{$this->archive}` ({$colList}) "
                    . "SELECT {$colList} FROM `{$this->live}` WHERE id IN (" . $ids->implode(',') . ")"
                );
                $deleted = DB::table($this->live)->whereIn('id', $ids)->delete();
                $moved += $deleted;
            });

            $bar->advance($ids->count());
        } while ($ids->count() === $batch);

        $bar->finish();
        $this->newLine(2);
        $this->info("Archived {$moved} rows for {$from} → {$to}. Live table stays lean; {$heldStock} rows with stock kept.");

        return self::SUCCESS;
    }
}
