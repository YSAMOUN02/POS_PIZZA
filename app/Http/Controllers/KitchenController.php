<?php

namespace App\Http\Controllers;

use App\Models\InvoiceLine;
use App\Models\ItemLedgerEntry;
use App\Models\PosProfile;
use App\Models\Product;
use App\Models\PurchaseHeader;
use App\Models\PurchaseLine;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KitchenController extends Controller
{
    public function index()
    {
        $posInfoForPrint = PosProfile::where('user_report', Auth::id())->first();
        $posInfoForPrint = $posInfoForPrint ? $posInfoForPrint->toArray() : [];
        $posInfoForPrint['logo_url'] = \App\Http\Controllers\PosProfileController::logoUrl();

        return view('backend.kitchen', compact('posInfoForPrint'));
    }

    // Kitchen Order tab: prepared dishes (output) + the materials each consumed,
    // over a date range. Backed by the detailed kitchen_order / kitchen_order_lines.
    public function kitchenOrders(Request $request)
    {
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to   = $request->query('to') ?: now()->toDateString();

        $orders = \App\Models\KitchenOrder::with('lines')
            ->whereBetween('posting_date', [$from, $to])
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        // Totals for the period header.
        $totals = \App\Models\KitchenOrder::whereBetween('posting_date', [$from, $to])
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(qty),0) as qty,
                COALESCE(SUM(material_cost),0) as material_cost,
                COALESCE(SUM(routing_cost),0) as routing_cost,
                COALESCE(SUM(fg_cost),0) as fg_cost')
            ->first();

        return response()->json(['orders' => $orders, 'totals' => $totals, 'from' => $from, 'to' => $to]);
    }

    // Today's menu-sold summary (loaded on demand from the Orders stat button):
    // each cooking product sold today, qty and how many are prepared.
    public function menuSoldToday(Request $request)
    {
        $date = $request->query('date') ?: now()->toDateString();

        $rows = InvoiceLine::whereHas('item', fn($q) => $q->where('type', 'cooking_product'))
            ->whereDate('created_at', $date)
            ->selectRaw('name, variant, unit,
                SUM(quantity) as qty_sold,
                COUNT(*) as order_count,
                SUM(CASE WHEN prepared_at IS NOT NULL THEN 1 ELSE 0 END) as prepared_count')
            ->groupBy('name', 'variant', 'unit')
            ->orderByDesc('qty_sold')
            ->get();

        return response()->json([
            'date'  => $date,
            'items' => $rows,
            'total' => (float) $rows->sum('qty_sold'),
        ]);
    }

    // Flat CSV of the Kitchen Order report: one OUTPUT row per dish followed by its
    // consumption rows. Streamed, no styling.
    // Period summary for the Kitchen Order tab: total materials consumed (across
    // all prepared dishes) + a finished-good production summary.
    public function kitchenOrderSummary(Request $request)
    {
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to   = $request->query('to') ?: now()->toDateString();

        // Every material consumed in the period, aggregated (base unit).
        $materials = DB::table('kitchen_order_lines as l')
            ->join('kitchen_order as o', 'o.id', '=', 'l.kitchen_order_id')
            ->whereBetween('o.posting_date', [$from, $to])
            ->groupBy('l.raw_material_id', 'l.name', 'l.unit')
            ->selectRaw('l.name, l.unit,
                SUM(l.qty) as qty,
                SUM(l.cost_amount) as cost,
                COUNT(*) as uses')
            ->orderByDesc('cost')
            ->get();

        // Finished goods produced in the period, per dish/variant.
        $fg = \App\Models\KitchenOrder::whereBetween('posting_date', [$from, $to])
            ->groupBy('product_id', 'name', 'variant')
            ->selectRaw('name, variant,
                SUM(qty) as qty,
                SUM(material_cost) as material_cost,
                SUM(routing_cost) as routing_cost,
                SUM(fg_cost) as fg_cost')
            ->orderByDesc('qty')
            ->get();

        return response()->json([
            'from'      => $from,
            'to'        => $to,
            'materials' => $materials,
            'fg'        => $fg,
            'totals'    => [
                'material_cost' => (float) $materials->sum('cost'),
                'fg_cost'       => (float) $fg->sum('fg_cost'),
                'dishes_qty'    => (float) $fg->sum('qty'),
                'materials'     => $materials->count(),
            ],
        ]);
    }

    public function kitchenOrdersExport(Request $request)
    {
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to   = $request->query('to') ?: now()->toDateString();

        $orders = \App\Models\KitchenOrder::with('lines')
            ->whereBetween('posting_date', [$from, $to])
            ->orderBy('id');

        $filename = "kitchen_orders_{$from}_to_{$to}.csv";

        return response()->streamDownload(function () use ($orders) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($out, [
                'Date', 'Invoice', 'FG Code', 'Dish', 'Variant', 'Row', 'Item',
                'Qty', 'Unit', 'Unit Cost', 'Cost Amount',
                'Material Cost', 'Routing Cost', 'FG Cost', 'Sell Price', 'Warehouse', 'Prepared By',
            ]);
            foreach ($orders->cursor() as $o) {
                fputcsv($out, [
                    $o->posting_date, $o->document_no, $o->item_code, $o->name, $o->variant,
                    'OUTPUT', $o->name, $o->qty, $o->unit, '', '',
                    $o->material_cost, $o->routing_cost, $o->fg_cost, $o->sell_price, $o->warehouse_name, $o->prepared_by,
                ]);
                foreach ($o->lines as $l) {
                    fputcsv($out, [
                        $o->posting_date, $o->document_no, $o->item_code, $o->name, $o->variant,
                        strtoupper($l->line_type), $l->name, $l->qty, $l->unit, $l->unit_cost, $l->cost_amount,
                        '', '', '', '', '', '',
                    ]);
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // Chef-side purchasing: raw / packaging materials ONLY. The chef enters the
    // quantity in whatever unit the supplier sells (e.g. 2 kg @ $9/kg); the GRN
    // is posted in the material's BASE unit (2000 g @ $0.0045/g), so stock,
    // costs and the consumption ledger all stay in one unit system.
    public function purchaseMaterials(Request $request)
    {
        $data = $request->validate([
            'warehouse_id'     => 'required|integer|exists:warehouses,id',
            'vendor_id'        => 'nullable|integer|exists:vendors,id',
            'posting_date'     => 'nullable|date',            // chef picks the GRN date
            'remark'           => 'nullable|string|max:500',
            'currency_code'    => 'nullable|string|max:10',
            'lines'            => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:product,id',
            'lines.*.qty'      => 'required|numeric|gt:0',
            'lines.*.cost'     => 'required|numeric|min:0', // per ENTERED unit, in the ENTERED currency
            'lines.*.unit_id'  => 'nullable|integer|exists:units_of_measure,id',
            'lines.*.expire'   => 'nullable|date',
        ]);

        // The chef may only receive into a warehouse they're assigned to.
        if (!Auth::user()->warehouses->pluck('id')->contains((int) $data['warehouse_id'])) {
            return response()->json(['status' => false, 'message' => 'You are not assigned to that warehouse.'], 422);
        }

        // Two-currency system: USD is the BASE — its factor is always 1 and it is
        // NOT stored in the currencies table, so it's handled here in code. Any
        // other currency (Riel) is looked up in the DB for its changeable rate.
        // The rate is read from the DB — never taken from the client — so a stale
        // or tampered browser value can't distort what lands in the ledger.
        $currencyCode = strtoupper(trim($data['currency_code'] ?? 'USD'));
        if ($currencyCode === 'USD') {
            $currencyFactor = 1.0;
        } else {
            $currency = \App\Models\Currency::where('code', $data['currency_code'])->first();
            if (!$currency) {
                return response()->json(['status' => false, 'message' => "Unknown currency: {$currencyCode}"], 422);
            }
            $currencyFactor = (float) ($currency->factor ?: 1);
            if ($currencyFactor <= 0) {
                return response()->json(['status' => false, 'message' => "Currency {$currencyCode} has an invalid rate."], 422);
            }
        }

        $products = Product::with('category')
            ->whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->get()
            ->keyBy('id');

        foreach ($data['lines'] as $i => $line) {
            $p = $products[$line['product_id']] ?? null;
            if (!$p || !in_array($p->type, ['raw_material', 'packaging_material'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Line ' . ($i + 1) . ': only raw material / packaging material can be purchased here.',
                ], 422);
            }
        }

        // Resolve every line's unit → base-unit conversion factor up front. A
        // material with no base unit / conversions configured yet (e.g. just
        // created) previously threw a raw exception mid-transaction, 500'd the
        // whole request, and could leave a half-posted GRN. Now it's caught
        // here as a normal validation error, before any DB writes happen, and
        // every problem line is reported together instead of failing on the first.
        $resolvedLines = [];
        $unitErrors = [];
        foreach ($data['lines'] as $i => $line) {
            $product = $products[$line['product_id']];
            $factor = 1.0;
            $enteredUnitCode = $product->unit;

            if (!empty($line['unit_id'])) {
                $unitId = (int) $line['unit_id'];
                if ($product->base_unit_id && $unitId === (int) $product->base_unit_id) {
                    $factor = 1.0;
                    $enteredUnitCode = optional($product->baseUnit)->code ?? $product->unit;
                } else {
                    $conv = $product->unitConversions()->with('unit')->where('unit_id', $unitId)->first();
                    if (!$conv) {
                        $unitErrors[] = 'Line ' . ($i + 1) . " ({$product->name}): that unit isn't defined for this material yet — add it as an alternate unit first, or leave the unit blank to enter the quantity in {$product->unit}.";
                        continue;
                    }
                    $factor = (float) $conv->factor;
                    $enteredUnitCode = optional($conv->unit)->code;
                }
            }

            $resolvedLines[$i] = ['factor' => $factor, 'entered_unit_code' => $enteredUnitCode];
        }

        if (!empty($unitErrors)) {
            return response()->json(['status' => false, 'message' => implode(' ', $unitErrors)], 422);
        }

        $precision = 6;
        $warehouse = Warehouse::find($data['warehouse_id']);
        // Chef's chosen GRN date; defaults to today when left blank.
        $postingDate = !empty($data['posting_date'])
            ? \Carbon\Carbon::parse($data['posting_date'])->toDateString()
            : now()->toDateString();

        DB::beginTransaction();
        try {
            // GRN number is always system-generated from the shared 'grn' serial
            // counter (same one the cashier purchase screen uses) — never manual.
            $documentNo = \App\Models\Serial_No::next('grn', 'GRN', 4);

            $header = PurchaseHeader::create([
                'no'             => $documentNo,
                'vendor_id'      => $data['vendor_id'] ?? null,
                'posting_date'   => $postingDate,
                'currency_name'  => $currencyCode,
                'factor'         => $currencyFactor,
                'deposit_amount' => 0,
                'location_id'    => (string) $data['warehouse_id'],
                'location_name'  => $warehouse->name ?? '',
                'remark'         => trim('Kitchen purchase. ' . ($data['remark'] ?? '')),
                'created_by'     => Auth::user()->username ?? 'NA',
            ]);

            // Per-type lot counter for this batch — raw materials get 26RM####,
            // packaging 26PM####. Seeded from the highest existing lot so numbers
            // never repeat, then incremented locally as we post each line.
            $lotSeq = [];

            foreach ($data['lines'] as $i => $line) {
                $product = $products[$line['product_id']];
                $lotNo = $this->nextMaterialLot($product->type, $lotSeq);

                // ---- Unit conversion: entered unit → base unit (already resolved above) ----
                $factor = $resolvedLines[$i]['factor'];
                $enteredUnitCode = $resolvedLines[$i]['entered_unit_code'];

                $enteredQty  = round((float) $line['qty'], $precision);
                $enteredCost = round((float) $line['cost'], $precision);   // in the entered currency
                // Two independent conversions: currency → USD, then entered unit → base unit.
                $costUsd  = round($enteredCost / $currencyFactor, $precision);
                $baseQty  = round($enteredQty * $factor, $precision);
                $baseCost = $factor > 0 ? round($costUsd / $factor, $precision) : $costUsd;
                $lineAmount = round($baseQty * $baseCost, $precision);
                $baseUnitCode = optional($product->baseUnit)->code ?? $product->unit;

                $noteParts = [];
                if ($factor != 1.0) {
                    $noteParts[] = "{$enteredQty} {$enteredUnitCode}";
                }
                if ($currencyFactor != 1.0) {
                    $noteParts[] = "{$enteredCost} {$currencyCode}/{$enteredUnitCode}";
                }
                $entryNote = $noteParts ? 'Entered as ' . implode(' @ ', $noteParts) : null;

                $purchaseLine = PurchaseLine::create([
                    'document_no'   => $documentNo,
                    'product_id'    => $product->id,
                    'barcode'       => $product->bar_code,
                    'item_code'     => $product->code,
                    'name'          => $product->name,
                    'lot'           => $lotNo,
                    'expire_date'   => null, // expiry is NA for kitchen materials
                    'description'   => $product->description,
                    'quantity'      => $baseQty,
                    'unit'          => $baseUnitCode,
                    'category_name' => optional($product->category)->name,
                    'unit_cost'     => $baseCost,
                    'line_amount'   => $lineAmount,
                    'remark'        => $entryNote,
                    'created_by'    => Auth::user()->username ?? 'NA',
                ]);

                // Each purchase lands as its own lot row (26RM####/26PM####), so
                // consumption can later deduct FIFO at the exact cost of the lot
                // it draws from — not a blended average across purchases.
                DB::table('warehouse_product')->insert([
                    'product_id'   => $product->id,
                    'warehouse_id' => $data['warehouse_id'],
                    'bin_id'       => null,
                    'quantity'     => $baseQty,
                    'track_lot'    => 1,
                    'lot'          => $lotNo,
                    'expire'       => null, // NA
                    'cost'         => $baseCost,
                    'control_exp'  => 0,
                    'created_by'   => Auth::user()->username ?? 'NA',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                $ledger = ItemLedgerEntry::create([
                    'posting_date'       => $postingDate,
                    'document_type'      => 'Purchase',
                    'document_no'        => $documentNo,
                    'source_id'          => $purchaseLine->id,
                    'source_table'       => 'Purchase Lines',
                    'product_id'         => $product->id,
                    'barcode'            => $product->bar_code,
                    'item_code'          => $product->code,
                    'name'               => $product->name,
                    'variant'            => $product->variant ?? '',
                    'description'        => $product->description,
                    'unit'               => $baseUnitCode,
                    'category_name'      => optional($product->category)->name,
                    'type'               => $product->type,
                    'warehouse_id'       => $data['warehouse_id'],
                    'warehouse_name'     => $warehouse->name ?? '',
                    'lot'                => $lotNo,
                    'expire_date'        => null, // NA
                    'quantity'           => $baseQty,
                    'remaining_quantity' => $baseQty,
                    'entry_type'         => 'positive',
                    // Amounts below are already converted to base currency, so the
                    // ledger is labelled USD. The currency the chef actually typed
                    // in is recorded on the purchase header + the line remark.
                    'currency_name'      => 'USD',
                    'factor'             => 1,
                    // Purchase carries COST only — value is in unit_cost + cost_amount
                    // (auto). Sale columns are left at their 0 defaults.
                    'unit_cost'          => $baseCost,
                    'payment_method'     => '',
                    'remark'             => $entryNote ?? ($data['remark'] ?? ''),
                    'created_by'         => Auth::user()->username ?? 'system',
                ]);
                $ledger->update(['entry_no' => $ledger->id]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Failed to post purchase: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'status' => true,
            'message' => "Purchase {$documentNo} posted — stock updated in base units.",
            'document_no' => $documentNo,
        ]);
    }

    // Next lot number for a material type — 26RM#### for raw materials, 26PM####
    // for packaging (year prefix + type + 4-digit counter). Seeded once per batch
    // from the highest existing lot with that prefix, then bumped locally per line
    // via the passed-by-reference $seq so multiple lines never collide.
    private function nextMaterialLot(string $productType, array &$seq): string
    {
        $prefix = now()->format('y') . ($productType === 'packaging_material' ? 'PM' : 'RM');

        if (!isset($seq[$prefix])) {
            $lastLot = DB::table('warehouse_product')
                ->where('lot', 'like', $prefix . '%')
                ->orderByDesc('lot')
                ->value('lot');
            $seq[$prefix] = $lastLot ? (int) substr($lastLot, strlen($prefix)) : 0;
        }

        $seq[$prefix]++;

        return $prefix . str_pad((string) $seq[$prefix], 4, '0', STR_PAD_LEFT);
    }

    // Cooking-product invoice lines sold but not yet prepared — what the chef sees to action.
    public function pendingOrders(Request $request)
    {
        $limit = $request->query('limit', 30);

        $lines = InvoiceLine::with(['item', 'invoice'])
            ->whereNull('prepared_at')
            ->whereHas('item', function ($q) {
                $q->where('type', 'cooking_product');
            })
            ->orderBy('id')
            ->paginate($limit);

        $warehouseIds = Auth::user()->warehouses->pluck('id');

        $lines->getCollection()->transform(function ($line) use ($warehouseIds) {
            $stock = $this->lineComponentStock($line, $warehouseIds);
            return [
                'id'           => $line->id,
                'product_id'   => $line->product_id,
                'name'         => $line->name,
                'variant'      => $line->variant,
                'quantity'     => $line->quantity,
                'unit'         => $line->unit,
                'document_no'  => $line->document_no,
                'invoice_no'   => optional($line->invoice)->no,
                'sold_at'      => $line->created_at,
                'recipe_lines' => $line->item ? $line->item->recipeLines()->count() : 0,
                'components'   => $stock['components'],   // each with ok = enough on hand
                'can_prepare'  => $stock['can_prepare'],  // false when any component is short
            ];
        });

        return response()->json($lines);
    }

    // Per-component stock status for one order line: how much each component needs
    // (recipe qty × line qty, in the material's base unit) vs how much is on hand.
    // Drives the green/red ingredient chips on the Orders board and the "no stock"
    // guard. Mirrors the availability rules markPrepared() actually enforces: a
    // component whose unit can't be resolved is a warning, not a blocker.
    private function lineComponentStock(InvoiceLine $line, $warehouseIds): array
    {
        $product = $line->item;
        if (!$product) {
            return ['components' => [], 'can_prepare' => true];
        }
        $qty = (float) $line->quantity;

        $needs = []; // rm_id => ['name','needed','unit','unresolved']
        foreach ($product->componentLines()->with('rawMaterial.baseUnit')->get() as $rl) {
            $rm = $rl->rawMaterial;
            if (!$rm) {
                continue;
            }
            $unitCode = optional($rm->baseUnit)->code ?? $rm->unit;
            $entry = $needs[$rm->id] ?? ['name' => $rm->name, 'needed' => 0.0, 'unit' => $unitCode, 'unresolved' => false];
            try {
                $factor = $rl->baseUnitFactor($rm);
                $entry['needed'] = round($entry['needed'] + (float) $rl->quantity * $factor * $qty, 6);
            } catch (\Throwable $e) {
                $entry['unresolved'] = true; // can't convert — prepare would skip it, don't block
            }
            $needs[$rm->id] = $entry;
        }

        $available = DB::table('warehouse_product')
            ->whereIn('product_id', array_keys($needs))
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity', '>', 0)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->pluck('qty', 'product_id');

        $components = [];
        $canPrepare = true;
        foreach ($needs as $id => $n) {
            $have = (float) ($available[$id] ?? 0);
            // Unresolved units don't block (prepare skips them); everything else
            // must have enough on hand.
            $ok = $n['unresolved'] ? true : ($have >= $n['needed']);
            if (!$n['unresolved'] && !$ok) {
                $canPrepare = false;
            }
            $components[] = [
                'name'       => $n['name'],
                'needed'     => $n['needed'],
                'available'  => $have,
                'unit'       => $n['unit'],
                'ok'         => $ok,
                'unresolved' => $n['unresolved'],
            ];
        }

        return ['components' => $components, 'can_prepare' => $canPrepare];
    }

    // Cooking-product invoice lines already marked prepared today — read-only
    // recent-activity list, so the chef can see what's already gone out.
    public function preparedToday(Request $request)
    {
        $limit = $request->query('limit', 20);

        $lines = InvoiceLine::with(['item', 'invoice'])
            ->whereNotNull('prepared_at')
            ->whereDate('prepared_at', now()->toDateString())
            ->whereHas('item', function ($q) {
                $q->where('type', 'cooking_product');
            })
            ->orderByDesc('prepared_at')
            ->paginate($limit);

        $lines->getCollection()->transform(function ($line) {
            return [
                'id'          => $line->id,
                'name'        => $line->name,
                'variant'     => $line->variant,
                'quantity'    => $line->quantity,
                'document_no' => $line->document_no,
                'invoice_no'  => optional($line->invoice)->no,
                'sold_at'     => $line->created_at,
                'prepared_at' => $line->prepared_at,
                'prepared_by' => $line->prepared_by,
            ];
        });

        return response()->json($lines);
    }

    // Live counters for the Orders board header — pending count mirrors the
    // pendingOrders() list exactly; the other two are scoped to today's shift.
    public function stats()
    {
        $today = now()->toDateString();

        $pendingCount = InvoiceLine::whereNull('prepared_at')
            ->whereHas('item', function ($q) {
                $q->where('type', 'cooking_product');
            })
            ->count();

        $preparedTodayCount = InvoiceLine::whereNotNull('prepared_at')
            ->whereDate('prepared_at', $today)
            ->whereHas('item', function ($q) {
                $q->where('type', 'cooking_product');
            })
            ->count();

        $avgPrepMinutes = InvoiceLine::whereNotNull('prepared_at')
            ->whereDate('prepared_at', $today)
            ->whereHas('item', function ($q) {
                $q->where('type', 'cooking_product');
            })
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, prepared_at)) as avg_minutes')
            ->value('avg_minutes');

        return response()->json([
            'pending_count'          => $pendingCount,
            'prepared_today_count'   => $preparedTodayCount,
            'avg_prep_minutes_today' => $avgPrepMinutes !== null ? round((float) $avgPrepMinutes, 1) : null,
        ]);
    }

    // Deduct the recipe's raw/packaging materials for one sold cooking-product line,
    // then mark it prepared. Stock is NEVER driven below zero: if any component is
    // short, the whole prepare is blocked (the chef restocks first — deduction is
    // an evening batch, so there's time). Deduction is FIFO by lot, consuming each
    // lot at its own purchase cost.
    public function markPrepared($lineId)
    {
        if (!Auth::user()->hasPermission('kitchen.prepare')) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to prepare orders.'
            ], 403);
        }

        $line = InvoiceLine::with('item', 'invoice')->findOrFail($lineId);

        if (!$line->item || $line->item->type !== 'cooking_product') {
            return response()->json([
                'status' => false,
                'message' => 'This item is not a cooking product.'
            ], 422);
        }

        if ($line->prepared_at) {
            return response()->json([
                'status' => false,
                'message' => 'This order line was already marked prepared.'
            ], 422);
        }

        $warehouseIds = Auth::user()->warehouses->pluck('id');

        if ($warehouseIds->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'You have no warehouse assigned to consume stock from.'
            ], 422);
        }


        DB::beginTransaction();

        try {

            // ============================
            // Collect Components + Addons
            // ============================

            $recipeLines = collect();


            // Components
            $components = $line->item
                ->componentLines()
                ->with('rawMaterial')
                ->get()
                ->each(function ($row) {
                    $row->source_no = 'Component';
                });


            $recipeLines = $recipeLines->concat($components);



            // Addons
            $chosenAddonIds = array_filter(
                array_map('intval', (array) ($line->addon_line_ids ?? []))
            );


            if ($chosenAddonIds) {

                $addons = $line->item
                    ->addOnLines()
                    ->with('rawMaterial')
                    ->whereIn('id', $chosenAddonIds)
                    ->get()
                    ->each(function ($row) {
                        $row->source_no = 'Add-on';
                    });


                $recipeLines = $recipeLines->concat($addons);
            }



            // ============================
            // Calculate Requirement
            // ============================

            $needs = [];

            $skippedMaterials = [];


            foreach ($recipeLines as $recipeLine) {


                $rawMaterial = $recipeLine->rawMaterial;


                if (!$rawMaterial) {
                    continue;
                }


                try {

                    $factor = $recipeLine->baseUnitFactor($rawMaterial);
                } catch (\Throwable $e) {

                    $skippedMaterials[] = $rawMaterial->name;
                    continue;
                }



                $needed = round(
                    (float)$recipeLine->quantity *
                        $factor *
                        (float)$line->quantity,
                    6
                );


                if ($needed <= 0) {
                    continue;
                }



                $needs[] = [
                    'rm'        => $rawMaterial,
                    'needed'    => $needed,
                    'source_no' => $recipeLine->source_no,
                ];
            }



            // ============================
            // Check Stock
            // ============================

            $shortages = [];


            foreach ($needs as $n) {


                $available = (float) DB::table('warehouse_product')
                    ->where('product_id', $n['rm']->id)
                    ->whereIn('warehouse_id', $warehouseIds)
                    ->where('quantity', '>', 0)
                    ->sum('quantity');



                if ($available < $n['needed']) {


                    $unit = optional($n['rm']->baseUnit)->code
                        ?? $n['rm']->unit;


                    $shortages[] =
                        "{$n['rm']->name} (need "
                        . $this->trimNum($n['needed'])
                        . ", have "
                        . $this->trimNum($available)
                        . " {$unit})";
                }
            }



            if (!empty($shortages)) {

                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' =>
                    'Not enough stock to prepare — restock first: '
                        . implode('; ', $shortages)
                        . '.'
                ], 422);
            }



            // ============================
            // Consume FIFO
            // ============================
            // Ledger source_no for consumption/output/add-on rows is the finished
            // good's item code; the sell row uses the sale order no.
            $fgItemCode = $line->item_code ?: (optional($line->item)->code ?? '');
            $saleOrderNo = optional($line->invoice)->source_no ?: '';

            $totalMaterialCost = 0;
            $koLines = []; // detailed consumption for the kitchen_order record


            foreach ($needs as $n) {

                $result = $this->deductRawMaterial(
                    $n['rm']->id,
                    $n['needed'],
                    $warehouseIds,
                    $line,
                    $fgItemCode
                );

                $totalMaterialCost += $result['cost'];

                $lineType = ($n['source_no'] === 'Addon') ? 'add_on' : 'component';
                foreach ($result['lots'] as $lot) {
                    $koLines[] = $lot + ['line_type' => $lineType];
                }
            }



            // ============================
            // Production Cost
            // ============================

            $routingCost = round(
                (float)($line->item->routing_cost ?? 0)
                    *
                    (float)$line->quantity,
                6
            );



            // Ledger order at Mark Prepared: Consumption (materials out, written
            // above — lowest ids) → Output (finished good in) → Sell (finished
            // good out at its own cost). Output + Sell use the SAME finished-good
            // cost, so FG inventory nets to zero and the COGS lands on the sell.
            // Both sit in the SAME warehouse the raw materials were consumed from,
            // so the finished good produces and sells where its RM lived.
            $fgCost = round($totalMaterialCost + $routingCost, 6);
            $prepWarehouseId = $warehouseIds->first();
            $prepWarehouseName = optional(Warehouse::find($prepWarehouseId))->name;

            $this->writeProductionLedger(
                $line,
                $totalMaterialCost,
                $routingCost,
                $prepWarehouseId,
                $prepWarehouseName,
                $fgItemCode
            );

            $this->writeSaleLedger(
                $line,
                $fgCost,
                $prepWarehouseId,
                $prepWarehouseName,
                $saleOrderNo
            );

            // Detailed companion record: the finished good (header) + every material
            // consumed (lines). Richer than the slimmed item ledger.
            $kitchenOrder = \App\Models\KitchenOrder::create([
                'posting_date'   => now()->toDateString(),
                'document_no'    => $line->document_no,
                'source_no'      => $fgItemCode,
                'invoice_line_id' => $line->id,
                'product_id'     => $line->product_id,
                'item_code'      => $fgItemCode,
                'name'           => $line->name,
                'variant'        => $line->variant ?? '',
                'category_name'  => $line->category_name,
                'qty'            => (float) $line->quantity,
                'unit'           => $line->unit,
                'material_cost'  => round($totalMaterialCost, 6),
                'routing_cost'   => round($routingCost, 6),
                'fg_cost'        => $fgCost,
                'sell_price'     => $line->sell_price ?? 0,
                'warehouse_id'   => $prepWarehouseId,
                'warehouse_name' => $prepWarehouseName,
                'prepared_by'    => Auth::user()->username ?? 'System',
                'created_by'     => Auth::user()->username ?? 'System',
            ]);
            foreach ($koLines as $kl) {
                \App\Models\KitchenOrderLine::create($kl + ['kitchen_order_id' => $kitchenOrder->id]);
            }



            $line->update([
                'prepared_at' => now(),
                'prepared_by' => Auth::user()->username ?? 'System',
            ]);



            DB::commit();
        } catch (\Throwable $e) {


            DB::rollBack();


            return response()->json([
                'status' => false,
                'message' => 'Failed to consume recipe: ' . $e->getMessage()
            ], 500);
        }



        if (!empty($skippedMaterials)) {

            return response()->json([
                'status' => true,
                'warning' => true,
                'message' =>
                'Order marked prepared, but stock was NOT deducted for: '
                    . implode(', ', array_unique($skippedMaterials))
            ]);
        }



        return response()->json([
            'status' => true,
            'message' => 'Order marked prepared — raw materials consumed.'
        ]);
    }
    private function trimNum(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }

    // Deduct $needed (base units) of a material FIFO across the chef's warehouse
    // lots, consuming each lot at ITS OWN cost. Availability is guaranteed by the
    // caller's pre-check, so stock never goes negative here.
    //
    // The consumption ledger row's source_no is the finished good's item code
    // ($ledgerSourceNo). Returns ['cost' => total consumed, 'lots' => [per-lot
    // detail]] so the caller can also record each portion on the kitchen_order.
    private function deductRawMaterial(
        $rawMaterialId,
        float $needed,
        $warehouseIds,
        InvoiceLine $line,
        string $ledgerSourceNo = ''
    ): array {
        $rawMaterial = Product::find($rawMaterialId);
        if (!$rawMaterial) {
            return ['cost' => 0.0, 'lots' => []];
        }

        $baseUnit = optional($rawMaterial->baseUnit)->code ?? $rawMaterial->unit;
        $remaining = $needed;
        $costConsumed = 0.0;
        $lotsTaken = [];

        $lots = DB::table('warehouse_product')
            ->where('product_id', $rawMaterialId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity', '>', 0)
            ->orderBy('id') // FIFO — oldest lot first
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((float) $lot->quantity, $remaining);
            $lotCost = (float) ($lot->cost ?? 0);

            DB::table('warehouse_product')->where('id', $lot->id)->update([
                'quantity'   => DB::raw('quantity - ' . $take),
                'updated_at' => now(),
            ]);

            $this->writeConsumptionLedger(
                $rawMaterial,
                $take,
                $lotCost,
                $lot->warehouse_id,
                $lot->bin_id,
                $lot->lot,
                $line,
                $ledgerSourceNo
            );

            // Re-cap the purchase entries' remaining_quantity to the new on-hand for
            // this lot — otherwise consumption reduces stock but the ledger's
            // "remaining" stays stale. Mirrors the sale flow's sync.
            $this->syncRemainingQty($rawMaterialId, $lot->lot, $lot->warehouse_id);

            $lotsTaken[] = [
                'raw_material_id' => $rawMaterial->id,
                'item_code'       => $rawMaterial->code,
                'name'            => $rawMaterial->name,
                'qty'             => round($take, 6),
                'unit'            => $baseUnit,
                'lot'             => $lot->lot,
                'warehouse_id'    => $lot->warehouse_id,
                'unit_cost'       => round($lotCost, 6),
                'cost_amount'     => round($take * $lotCost, 6),
            ];

            $costConsumed += $take * $lotCost;
            $remaining -= $take;
        }

        return ['cost' => round($costConsumed, 6), 'lots' => $lotsTaken];
    }

    // Distribute the current warehouse on-hand for a (product, lot, warehouse)
    // across its positive (purchase/receipt) ledger rows, newest first, so
    // SUM(remaining_quantity) always equals real stock. Same rule the sale flow
    // uses; called after each kitchen consumption.
    private function syncRemainingQty($productId, $lot, $warehouseId): void
    {
        $lot = ($lot !== null && trim((string) $lot) !== '') ? trim((string) $lot) : null;

        $onHand = (float) DB::table('warehouse_product')
            ->where('product_id', $productId)
            ->when($lot !== null, fn($q) => $q->where('lot', $lot), fn($q) => $q->whereNull('lot'))
            ->when($warehouseId !== null, fn($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('quantity');

        $positives = ItemLedgerEntry::where('product_id', $productId)
            ->where('entry_type', 'positive')
            ->when($lot !== null, fn($q) => $q->where('lot', $lot), fn($q) => $q->whereNull('lot'))
            ->when($warehouseId !== null, fn($q) => $q->where('warehouse_id', $warehouseId))
            ->orderByDesc('id') // newest lot keeps stock; oldest depletes first (FIFO)
            ->get();

        $left = $onHand;
        foreach ($positives as $entry) {
            $cap = abs((float) $entry->quantity);
            $newRemaining = max(0.0, min($left, $cap));
            if ((float) $entry->remaining_quantity !== $newRemaining) {
                $entry->remaining_quantity = $newRemaining;
                $entry->save();
            }
            $left = round($left - $newRemaining, 6);
        }
    }

    // Positive counterpart to writeConsumptionLedger() — records the finished good
    // the chef produced. Its cost is the full production cost: raw/packaging
    // material consumed (FIFO lot cost) PLUS the variant's routing (labor) cost.
    private function writeProductionLedger(
        InvoiceLine $line,
        float $totalMaterialCost,
        float $routingCost = 0.0,
        $warehouseId = null,
        $warehouseName = null,
        string $sourceNo = ''
    ): void {
        $product = $line->item;

        $qty = (float) $line->quantity;

        // Finished Good Cost = Material Cost + Labor/Preparation Cost
        $fgCost = round($totalMaterialCost + $routingCost, 6);

        $unitCost = $qty > 0
            ? round($fgCost / $qty, 6)
            : 0;

        $ledger = ItemLedgerEntry::create([
            'posting_date'       => now()->toDateString(),

            'document_type'      => 'Kitchen Production',
            'source_no'          => $sourceNo,   // FG item code

            'document_no'        => $line->document_no,

            'source_id'          => $line->id,
            'source_table'       => 'Sale Invoice Lines',

            // Finished product
            'product_id'         => $line->product_id,
            'barcode'            => $product->bar_code ?? '',
            'item_code'          => $product->code ?? '',

            'name'               => $line->name,
            'variant'            => $line->variant ?? '',
            'description'        => $product->description ?? '',

            'unit'               => $line->unit,
            'category_name'      => $line->category_name,

            'type'               => 'cooking_product',

            // Produced where its raw materials were consumed.
            'warehouse_id'       => $warehouseId,
            'warehouse_name'     => $warehouseName,

            // Output entry — immediately sold below, so no FG stays on hand.
            'quantity'           => $qty,
            'remaining_quantity' => 0,

            'entry_type'         => 'positive',

            // Production cost — value lives in cost_amount (auto, + stock in).
            // Non-sale document: Line/Net/Total carry no sale value.
            'unit_cost'          => $unitCost,

            'sell_price'         => $line->sell_price ?? 0,

            'line_amount'        => 0,
            'net_amount'         => 0,
            'grand_total_amount' => 0,

            'remark'             =>
            'Kitchen production output for invoice line #' . $line->id
                . ' (material cost: '
                . number_format($totalMaterialCost, 4)
                . ', routing cost: '
                . number_format($routingCost, 4)
                . ')',

            'created_by'         => Auth::user()->username ?? 'System',
        ]);

        // Sequential ledger number
        $ledger->update([
            'entry_no' => $ledger->id
        ]);
    }

    // Sell counterpart to writeProductionLedger(): the finished good leaving stock
    // when the order is sold. Same FG cost as the output entry, so the two net to
    // zero (FG never lingers in inventory) and this row carries the COGS. Written
    // last, so the ledger reads Consumption → Output → Sell. Sits in the same
    // warehouse the raw materials came from.
    private function writeSaleLedger(
        InvoiceLine $line,
        float $fgCost,
        $warehouseId = null,
        $warehouseName = null,
        string $saleOrderNo = ''
    ): void {
        $product = $line->item;

        $qty = (float) $line->quantity;

        $unitCost = $qty > 0
            ? round($fgCost / $qty, 6)
            : 0;

        $ledger = ItemLedgerEntry::create([
            'posting_date'       => now()->toDateString(),

            'document_type'      => 'Sales Invoice',
            'source_no'          => $saleOrderNo,   // sale order no

            'document_no'        => $line->document_no,

            'source_id'          => $line->id,
            'source_table'       => 'Sale Invoice Lines',

            'product_id'         => $line->product_id,
            'barcode'            => $product->bar_code ?? '',
            'item_code'          => $product->code ?? '',

            'name'               => $line->name,
            'variant'            => $line->variant ?? '',
            'description'        => $product->description ?? '',

            'unit'               => $line->unit,
            'category_name'      => $line->category_name,

            'type'               => 'cooking_product',

            // Sold from where the finished good was produced (= RM location).
            'warehouse_id'       => $warehouseId,
            'warehouse_name'     => $warehouseName,

            // FG out — the counter to the Output entry above.
            'quantity'           => -1 * $qty,
            'remaining_quantity' => 0,

            'entry_type'         => 'negative',

            // Cost from production (drives COGS via cost_amount); sale figures from
            // the invoice line so the sold finished good is a full sale record.
            'unit_cost'          => $unitCost,
            'unit_price'         => $line->unit_price ?? 0,
            'sell_price'         => $line->sell_price ?? 0,
            'discount_percent'   => $line->discount_percent ?? 0,
            'discount_amount'    => $line->discount_amount ?? 0,
            'vat'                => $line->vat ?? 0,
            'vat_amount'         => $line->vat_amount ?? 0,
            'line_amount'        => $line->line_amount ?? 0,
            'net_amount'         => $line->net_amount ?? 0,
            'grand_total_amount' => $line->grand_total_amount ?? 0,

            'remark'             => 'Finished good sold (invoice line #' . $line->id . ')',

            'created_by'         => Auth::user()->username ?? 'System',
        ]);

        $ledger->update([
            'entry_no' => $ledger->id
        ]);
    }
    // End-of-day summary for the chef: cooking products sold that day, plus the
    // raw/packaging material consumed preparing them (from the Recipe Consumption
    // ledger entries markPrepared() writes above).
    public function endOfDayReport(Request $request)
    {
        $date = $request->query('date', now()->toDateString());

        $cookingSales = InvoiceLine::whereHas('item', function ($q) {
            $q->where('type', 'cooking_product');
        })
            ->whereDate('created_at', $date)
            ->selectRaw('product_id, name, variant, unit,
                SUM(quantity) as qty_sold,
                COUNT(*) as order_count,
                SUM(CASE WHEN prepared_at IS NOT NULL THEN 1 ELSE 0 END) as prepared_count')
            ->groupBy('product_id', 'name', 'variant', 'unit')
            ->orderByDesc('qty_sold')
            ->get();

        $rawMaterialUsage = ItemLedgerEntry::where('document_type', 'Recipe Consumption')
            ->where('entry_type', 'negative')
            ->where('type', 'raw_material')
            ->whereDate('posting_date', $date)
            ->selectRaw('product_id, name, unit,
                SUM(ABS(quantity)) as qty_used,
                SUM(ABS(quantity) * unit_cost) as cost_used')
            ->groupBy('product_id', 'name', 'unit')
            ->orderByDesc('qty_used')
            ->get();

        $packagingUsage = ItemLedgerEntry::where('document_type', 'Recipe Consumption')
            ->where('entry_type', 'negative')
            ->where('type', 'packaging_material')
            ->whereDate('posting_date', $date)
            ->selectRaw('product_id, name, unit,
                SUM(ABS(quantity)) as qty_used,
                SUM(ABS(quantity) * unit_cost) as cost_used')
            ->groupBy('product_id', 'name', 'unit')
            ->orderByDesc('qty_used')
            ->get();

        return response()->json([
            'date' => $date,
            'cooking_sales' => $cookingSales,
            'raw_material_usage' => $rawMaterialUsage,
            'packaging_usage' => $packagingUsage,
            'totals' => [
                'items_sold' => (float) $cookingSales->sum('qty_sold'),
                'distinct_dishes' => $cookingSales->count(),
                'raw_material_cost' => (float) $rawMaterialUsage->sum('cost_used'),
                'packaging_cost' => (float) $packagingUsage->sum('cost_used'),
            ],
        ]);
    }

    // Raw material consumption over a date range — how much of each ingredient
    // has actually been used preparing orders, plus what's left in stock right
    // now, so the chef can see usage against remaining supply at a glance.
    public function consumption(Request $request)
    {
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->toDateString());

        $usage = ItemLedgerEntry::where('document_type', 'Recipe Consumption')
            ->where('entry_type', 'negative')
            ->where('type', 'raw_material')
            ->whereBetween('posting_date', [$from, $to])
            ->selectRaw('product_id, name, unit,
                SUM(ABS(quantity)) as qty_used,
                SUM(ABS(quantity) * unit_cost) as cost_used')
            ->groupBy('product_id', 'name', 'unit')
            ->orderByDesc('qty_used')
            ->get();

        $stockByProduct = DB::table('warehouse_product')
            ->whereIn('product_id', $usage->pluck('product_id'))
            ->selectRaw('product_id, COALESCE(SUM(quantity), 0) as stock')
            ->groupBy('product_id')
            ->pluck('stock', 'product_id');

        $usage = $usage->map(function ($row) use ($stockByProduct) {
            $row->current_stock = (float) ($stockByProduct[$row->product_id] ?? 0);
            $row->avg_cost = $row->qty_used > 0 ? round($row->cost_used / $row->qty_used, 6) : 0;
            return $row;
        });

        return response()->json([
            'from' => $from,
            'to' => $to,
            'usage' => $usage,
            'total_cost' => (float) $usage->sum('cost_used'),
        ]);
    }

    private function writeConsumptionLedger(
        Product $product,
        float $qty,
        float $cost,
        $warehouseId,
        $binId,
        $lot,
        InvoiceLine $line,
        string $sourceNo = 'Component'
    ): void {
        $warehouse = Warehouse::find($warehouseId);
        // $cost is the specific LOT's cost this portion was drawn from (FIFO),
        // so the consumption ledger reflects what the material actually cost.

        $ledger = ItemLedgerEntry::create([
            'posting_date'       => now()->toDateString(),
            'document_type'      => 'Recipe Consumption',
            'document_no'        => $line->document_no,
            'source_id'          => $line->id,
            'source_no' => $sourceNo,
            'source_table'       => 'Sale Invoice Lines',
            'product_id'         => $product->id,
            'barcode'            => $product->bar_code,
            'item_code'          => $product->code,
            'name'               => $product->name,
            'variant'            => $product->variant ?? '',
            'description'        => $product->description,
            'unit'               => $product->unit,
            'category_name'      => optional($product->category)->name,
            'type'               => $product->type,
            'warehouse_id'       => $warehouseId,
            'warehouse_name'     => $warehouse->name ?? '',
            'bin_id'             => $binId,
            'lot'                => $lot ?? '',
            // Stock-OUT → negative quantity, matching the ledger convention used by
            // sales (writeSaleLedger stores -qty). Direction also flagged by
            // entry_type; cost_amount is signed from |quantity| by the model hook.
            'quantity'           => -1 * abs($qty),
            'remaining_quantity' => 0,
            'entry_type'         => 'negative',
            'unit_cost'          => $cost,
            'sell_price'         => $product->sell_price ?? 0,
            // Non-sale document: value is in cost_amount (auto, − stock out).
            'line_amount'        => 0,
            'net_amount'         => 0,
            'grand_total_amount' => 0,
            'remark'             => 'Consumed for cooking product recipe (invoice line #' . $line->id . ')',
            'created_by'         => Auth::user()->username ?? 'System',
        ]);

        $ledger->update(['entry_no' => $ledger->id]);
    }
}
