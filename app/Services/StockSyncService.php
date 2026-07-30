<?php
namespace App\Services;

use App\Events\StockSyncProgress;
use App\Models\Category;
use App\Models\Currency;
use App\Models\GetStock;
use App\Models\ItemLedgerEntry;
use App\Models\Product;
use App\Models\PurchaseHeader;
use App\Models\PurchaseLine;
use App\Models\StockSyncRun;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockSyncService
{
    private int $precision = 6;
    private const VENDOR_ID = 1;                 // V0001 (your internal transfer vendor)
    private array $skipped = [];                 // [{item, lot, reason}]

    /** NAV blank date (1753-01-01) → null. */
    private function navDate($v): ?string
    {
        if (!$v) return null;
        $d = Carbon::parse($v);
        return $d->year <= 1753 ? null : $d->toDateString();
    }

    /* ---- entry point (called by the job) ---- */
    public function run(StockSyncRun $run): void
    {
        $run->update(['status' => 'running']);
        broadcast(new StockSyncProgress($run));

        try {
            $user = User::findOrFail($run->user_id);
            $riel = Currency::where('code', '៛')->firstOrFail();     // KHR row (code + factor)
            $vendorName = optional(Vendor::find(self::VENDOR_ID))->name ?? '';
            $warehouses = $user->warehouses;

            // ---- pass 1: total (for the %) ----
            $total = 0;
            foreach ($warehouses as $wh) {
                if (!$wh->name) continue;
                $wm = (int) ($wh->last_stock_entry ?? 0);
                $total += GetStock::where('LocationCode', $wh->name)
                    ->where('EntryNo', '>', $wm)->count();
            }
            $run->update(['total' => $total, 'done' => 0, 'added' => 0, 'skipped' => 0]);
            broadcast(new StockSyncProgress($run));

            // ---- pass 2: process ----
            foreach ($warehouses as $wh) {
                $this->syncWarehouse($wh, $run, $riel, $vendorName);
            }

            $run->update([
                'status'        => 'done',
                'message'       => "{$run->added} added · {$run->skipped} skipped",
                'skipped_items' => array_slice($this->skipped, 0, 200),
            ]);
        } catch (\Throwable $e) {
            Log::error('Stock sync failed: ' . $e->getMessage());
            $run->update(['status' => 'failed', 'message' => $e->getMessage()]);
        }
        broadcast(new StockSyncProgress($run));
    }

    /* ---- one warehouse ---- */
    private function syncWarehouse(Warehouse $wh, StockSyncRun $run, $riel, string $vendorName): void
    {
        $location = $wh->name;                  // NAV Location Code = warehouse.name
        if (!$location) return;

        $watermark = (int) ($wh->last_stock_entry ?? 0);

        $rows = GetStock::where('LocationCode', $location)
            ->where('EntryNo', '>', $watermark)
            ->orderBy('EntryNo')                // ascending — watermark = last handled entry
            ->get();

        if ($rows->isEmpty()) return;

        $codes    = $rows->pluck('ItemNo')->unique()->filter();
        $products = Product::with('category')->whereIn('code', $codes)->get()->keyBy('code');

        $lastEntry = $watermark;
        $sinceFlush = 0;

        foreach ($rows as $r) {
            $entryNo = (int) $r->EntryNo;

            // resolve product — auto-create if missing
            $product = $products[$r->ItemNo] ?? null;
            if (!$product) {
                if (blank($r->ItemName)) {                       // not in NAV item master (anomaly)
                    $this->skip($r, 'not in NAV item master');
                    $lastEntry = $entryNo; $run->increment('done'); $sinceFlush++;
                    $this->flush($run, $wh, $lastEntry, $sinceFlush, $forced = false);
                    continue;
                }
                $product = $this->autoCreateProduct($r);
                if (!$product) {                                 // category/insert failed
                    $this->skip($r, 'product auto-create failed');
                    $lastEntry = $entryNo; $run->increment('done'); $sinceFlush++;
                    $this->flush($run, $wh, $lastEntry, $sinceFlush);
                    continue;
                }
                $products[$r->ItemNo] = $product;
            }

            // already imported? (belt + suspenders with the watermark)
            if (ItemLedgerEntry::where('source_no', 'NAV-' . $entryNo)->exists()) {
                $lastEntry = $entryNo; $run->increment('done'); $sinceFlush++;
                $this->flush($run, $wh, $lastEntry, $sinceFlush);
                continue;
            }

            try {
                $this->importRow($r, $product, $wh, $riel, $vendorName, $entryNo);
                $run->increment('added');
            } catch (\Throwable $e) {
                Log::warning("Sync entry {$entryNo}: " . $e->getMessage());
                $this->skip($r, 'import error');
            }

            $lastEntry = $entryNo;                               // advance regardless → never freezes
            $run->increment('done');
            $sinceFlush++;
            $this->flush($run, $wh, $lastEntry, $sinceFlush);
        }

        // final watermark + progress for this warehouse
        Warehouse::whereKey($wh->id)->update(['last_stock_entry' => (string) $lastEntry]);
        broadcast(new StockSyncProgress($run->fresh()));
    }

    /** persist watermark + push progress every 25 rows */
    private function flush(StockSyncRun $run, Warehouse $wh, int $lastEntry, int &$since, bool $forced = true): void
    {
        if ($since < 25) return;
        Warehouse::whereKey($wh->id)->update(['last_stock_entry' => (string) $lastEntry]);
        $run->update(['skipped' => count($this->skipped)]);
        broadcast(new StockSyncProgress($run->fresh()));
        $since = 0;
    }

    /** the actual dual-write: PurchaseHeader/Line + ledger + warehouse_product, one tx */
    private function importRow($r, Product $product, Warehouse $wh, $riel, string $vendorName, int $entryNo): void
    {
        $qty      = round((float) $r->RemainingQuantity, $this->precision);  // ON-HAND, not received
        $lot      = (string) ($r->LotNo ?? '');
        $expire   = $this->navDate($r->LotExpiry);                            // from LOT card
        $unitCost = round((float) ($product->cost ?? 0), $this->precision);  // 0 until NAV costs set
        $line     = round($qty * $unitCost, $this->precision);
        $unit     = $product->unit ?: ($r->ItemUnit ?: '');
        $catName  = $product->category_name ?: ($r->ItemCategoryCode ?: '');
        $variant  = $r->VariantCode ?: ($product->variant ?? '');
        $docNo    = $r->DocumentNo ?: ($r->SourceNo ?: ('TRF-' . $entryNo));
        $posting  = $this->navDate($r->PostingDate) ?? now()->toDateString();

        DB::transaction(function () use (
            $r, $product, $wh, $riel, $vendorName, $entryNo,
            $qty, $lot, $expire, $unitCost, $line, $unit, $catName, $variant, $docNo, $posting
        ) {
            // one header per NAV Document No_
            $header = PurchaseHeader::firstOrCreate(
                ['no' => $docNo],
                [
                    'vendor_id'      => self::VENDOR_ID,
                    'posting_date'   => $posting,
                    'due_date'       => null,
                    'payment_method' => null,
                    'location_id'    => $wh->id,
                    'location_name'  => $wh->name,
                    'currency_name'  => $riel->code,
                    'factor'         => $riel->factor,
                    'remark'         => 'ERP stock sync',
                    'created_by'     => 'erp-sync',
                ]
            );

            // purchase line (positive, mirrors post_grn)
            $pl = PurchaseLine::create([
                'document_no'   => $docNo,
                'product_id'    => $product->id,
                'barcode'       => $product->bar_code,
                'item_code'     => $product->code,
                'name'          => $product->name ?: 'Item',
                'variant'       => $variant,
                'lot'           => $lot ?: null,
                'expire_date'   => $expire,
                'description'   => $product->description,
                'quantity'      => $qty,
                'unit'          => $unit,
                'category_name' => $catName,
                'unit_cost'     => $unitCost,
                'line_amount'   => $line,
                'remark'        => 'ERP stock sync',
                'created_by'    => 'erp-sync',
            ]);

            // ledger stock lot (positive stock; negative amounts, mirrors post_grn)
            $ledger = ItemLedgerEntry::create([
                'posting_date'       => $posting,
                'document_type'      => 'Purchase',
                'document_no'        => $docNo,
                'source_id'          => $pl->id,
                'source_no'          => 'NAV-' . $entryNo,   // dedup marker
                'source_table'       => 'Purchase Lines',
                'product_id'         => $product->id,
                'barcode'            => $product->bar_code,
                'item_code'          => $product->code,
                'name'               => $product->name,
                'variant'            => $variant,
                'description'        => $product->description,
                'unit'               => $unit,
                'category_name'      => $catName,
                'type'               => $product->type ?? 'product',
                'warehouse_id'       => $wh->id,
                'warehouse_name'     => $wh->name,
                'lot'                => $lot,
                'expire_date'        => $expire,
                'quantity'           => $qty,
                'remaining_quantity' => $qty,
                'entry_type'         => 'positive',
                'currency_name'      => $riel->code,
                'factor'             => $riel->factor,
                // Purchase carries COST only — value is in unit_cost + cost_amount
                // (auto). Sale columns are left at their 0 defaults.
                'unit_cost'          => $unitCost,
                'vendor_id'          => self::VENDOR_ID,
                'vendor_name'        => $vendorName,
                'customer_id'        => null,
                'customer_name'      => '',
                'customer_phone'     => '',
                'customer_address'   => '',
                'payment_method'     => '',
                'remark'             => 'ERP stock sync',
                'created_by'         => 'erp-sync',
            ]);
            $ledger->update(['entry_no' => $ledger->id]);

            // aggregate warehouse_product per (product, warehouse, lot)
            $key = fn($q) => $lot !== ''
                ? $q->where('product_id', $product->id)->where('warehouse_id', $wh->id)->where('lot', $lot)
                : $q->where('product_id', $product->id)->where('warehouse_id', $wh->id)->whereNull('lot');

            if ($key(DB::table('warehouse_product'))->exists()) {
                $key(DB::table('warehouse_product'))->update([
                    'quantity'   => DB::raw('quantity + ' . $qty),
                    'cost'       => $unitCost,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('warehouse_product')->insert([
                    'product_id'   => $product->id,
                    'warehouse_id' => $wh->id,
                    'quantity'     => $qty,
                    'track_lot'    => $lot !== '' ? 1 : 0,
                    'lot'          => $lot ?: null,
                    'expire'       => $expire,
                    'cost'         => $unitCost,
                    'control_exp'  => $expire ? 1 : 0,
                    'created_by'   => 'erp-sync',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        });
    }

    /* ---- auto-create a POS product from the joined NAV row ---- */
    private function autoCreateProduct($r): ?Product
    {
        try {
            return DB::transaction(function () use ($r) {
                return Product::create([
                    'code'                => $r->ItemNo,
                    'name'                => $r->ItemName ?: $r->ItemNo,
                    'description'         => $r->ItemDescription2 ?: ($r->ItemName ?: ''),
                    'unit'                => $r->ItemUnit ?: '',
                    'variant'             => $r->VariantCode ?: '',
                    'category_id'         => $this->resolveCategoryId($r->ItemCategoryCode),
                    'category_name'       => $r->ItemCategoryCode ?: '',
                    'sell_price'          => (float) ($r->ItemUnitPrice ?? 0),
                    'cost'                => (float) ($r->ItemUnitCost ?? 0),
                    'last_purchase_price' => (float) ($r->ItemLastCost ?? 0),
                    'min_stock'           => (float) ($r->ItemMinStock ?? 0),
                    'max_stock'           => (float) ($r->ItemMaxStock ?? 0),
                    'track_stock'         => 1,
                    'allow_discount'      => $r->ItemAllowDisc ? 1 : 0,
                    'allow_return'        => 1,
                    'vat'                 => 0,
                    'type'                => 'product',
                    'bar_code'            => '',
                    'status'              => $r->ItemBlocked ? 0 : 1,
                    'created_by'          => 'erp-sync',
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning("Auto-create {$r->ItemNo}: " . $e->getMessage());
            return null;
        }
    }

    private function resolveCategoryId($code): ?int
    {
        $code = trim((string) $code);
        if ($code === '') return null;
        try {
            return Category::firstOrCreate(['name' => $code])->id;   // ⚠️ see note 1
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function skip($r, string $reason): void
    {
        $this->skipped[] = ['item' => $r->ItemNo, 'lot' => $r->LotNo ?: '—', 'reason' => $reason];
    }
}
