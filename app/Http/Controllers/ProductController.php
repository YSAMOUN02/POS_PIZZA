<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductRecipeLine;
use App\Models\ProductRecipeStep;
use App\Models\ProductUnitConversion;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
class ProductController extends Controller
{
    public $warehouse_id = 1;
    /**
     * 🔍 Search products (LOT-aware stock)
     */
    public function search(Request $request)
    {
        $allowed = ['name', 'code', 'barcode'];
        $field = in_array($request->query('field'), $allowed)
            ? $request->query('field')
            : 'name';

        $query = $request->query('query', '');

        $products = Product::with(['warehouses' => function ($q) {
            $q->where('warehouse_id', $this->warehouse_id);
        }])
            ->where('status', 1)
            ->where($field, 'like', "%{$query}%")
            ->get();

        // ✅ LOT-aware stock sum
        $products->each(function ($product) {
            $product->total_stock = $product->warehouses->sum(fn($wh) => $wh->pivot->quantity ?? 0);
        });

        // Sort: in-stock first, then name
        $products = $products->sort(function ($a, $b) {
            if ($a->total_stock == 0 && $b->total_stock > 0) return 1;
            if ($a->total_stock > 0 && $b->total_stock == 0) return -1;
            return strcmp($a->name, $b->name);
        })->values();

        return response()->json($products);
    }


    public function list_search(Request $request)
    {
        $limit = $request->query('limit', 30);

        $query = Product::query()->with(['category', 'warehouses']); // eager load category + stock (avoids N+1 on ->stock)

        // Search
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%$s%")
                    ->orWhere('name', 'like', "%$s%")
                    ->orWhere('variant', 'like', "%$s%")
                    ->orWhere('description', 'like', "%$s%")
                    ->orWhereHas('category', function ($q2) use ($s) {
                        $q2->where('name', 'like', "%$s%");
                    });
            });
        }

        // Filter by category (frontend type = category_id)
        if ($request->filled('type')) {
            $query->where('category_id', $request->type);
        }

        // Filter by status
        if ($request->filled('status') != '') {
            if ($request->filled('status') && is_numeric($request->status)) {
                $query->where('status', $request->status);
            }
        }


        // Filter by track_stock
        if ($request->filled('track_stock')) {
            $query->where('track_stock', $request->track_stock);
        }

        // Sorting
        $sortableColumns = [
            'id',
            'code',
            'name',
            'variant',
            'sell_price',
            'cost',
            'vat',
            'discount_percent',
            'last_purchase_price',
            'min_stock',
            'max_stock',
            'status'
        ];

        if ($request->filled('sort_by') && in_array($request->sort_by, $sortableColumns)) {
            $dir = $request->query('sort_dir', 'asc') === 'desc' ? 'desc' : 'asc';
            $query->orderBy($request->sort_by, $dir);
        } else {
            $query->orderBy('id', 'desc');
        }

        // Return paginated products including category
        $products = $query->paginate($limit);

        // Optional: map to include only fields you want + category name
        $products->getCollection()->transform(function ($product) {
            return [
                'id' => $product->id,
                'code' => $product->code,
                'bar_code' => $product->bar_code,
                'name' => $product->name,
                'variant' => $product->variant,
                'type' => $product->type,
                'description' => $product->description,
                'sell_price' => $product->sell_price,
                'image' => $product->image,
                'cost' => $product->cost,
                'vat' => $product->vat,
                'discount_percent' => $product->discount_percent,
                'last_purchase_price' => $product->last_purchase_price,
                'category_id' => $product->category_id,
                'min_stock' => $product->min_stock,
                'max_stock' => $product->max_stock,
                'track_stock' => $product->track_stock,
                'stock' => $product->stock,
                'allow_discount' => $product->allow_discount,
                'allow_return' => $product->allow_return,
                'category_name' => $product->category_name,
                'status' => $product->status,
                'unit' => $product->unit,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name
                ] : null,
            ];
        });

        return response()->json($products);
    }

    // Product list for the Kitchen interface — cooking/raw/packaging products only,
    // so a chef managing "products" never sees (or accidentally edits) the general
    // Sale-screen catalog.
    public function kitchenProducts(Request $request)
    {
        $limit = $request->query('limit', 30);

        // NOTE: select('product.*') must come BEFORE withCount(). withCount adds its
        // count columns via addSelect; a later select('product.*') would replace the
        // whole select list and silently drop them (that's what made every card read
        // "no recipe").
        $query = Product::query()
            ->with('category')
            ->whereIn('type', ['cooking_product', 'raw_material', 'packaging_material'])
            ->select('product.*')
            ->addSelect([
                'computed_stock' => DB::table('warehouse_product')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('warehouse_product.product_id', 'product.id'),
            ])
            ->withCount([
                'recipeLines as components_count' => fn($q) => $q->where('line_type', 'component'),
                'recipeLines as addons_count' => fn($q) => $q->where('line_type', 'add_on'),
            ]);

        // The "Product" tab (menu items) and "Inventory" tab (raw/packaging stock)
        // are separate views over the same table — scope narrows to one or the other
        // before the finer `type` filter (e.g. raw vs packaging within Inventory) applies.
        if ($request->query('scope') === 'product') {
            $query->where('type', 'cooking_product');
        } elseif ($request->query('scope') === 'inventory') {
            $query->whereIn('type', ['raw_material', 'packaging_material']);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%$s%")
                    ->orWhere('name', 'like', "%$s%")
                    ->orWhere('variant', 'like', "%$s%");
            });
        }

        if ($request->filled('type') && in_array($request->type, ['cooking_product', 'raw_material', 'packaging_material'])) {
            $query->where('type', $request->type);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Menu tab: has the chef defined this dish's recipe yet?
        if ($request->filled('recipe_status')) {
            if ($request->recipe_status === 'set') {
                $query->whereHas('recipeLines');
            } elseif ($request->recipe_status === 'none') {
                $query->whereDoesntHave('recipeLines');
            }
        }

        // Filter to materials consumed by a specific finished good (its recipe).
        // Accepts a product id (one variant) or, with used_in_by=name, every
        // variant of that dish — "show me everything a Margherita uses".
        if ($request->filled('used_in_product')) {
            $fgProductIds = $request->query('used_in_by') === 'name'
                ? Product::where('name', function ($sub) use ($request) {
                    $sub->select('name')->from('product')->where('id', $request->used_in_product)->limit(1);
                })->pluck('id')
                : [$request->used_in_product];

            $query->whereIn('id', function ($sub) use ($fgProductIds) {
                $sub->select('raw_material_id')
                    ->from('product_recipe_lines')
                    ->whereIn('product_id', $fgProductIds);
            });
        }

        if ($request->filled('stock_status')) {
            match ($request->stock_status) {
                'out' => $query->havingRaw('computed_stock <= 0'),
                'low' => $query->havingRaw('computed_stock > 0 AND min_stock > 0 AND computed_stock <= min_stock'),
                'in'  => $query->havingRaw('computed_stock > 0 AND (min_stock <= 0 OR computed_stock > min_stock)'),
                default => null,
            };
        }

        // Group each dish's variants together in the chef's chosen order (S, M, L, XL)
        // rather than scattering them by id.
        $products = $query->orderBy('name')->orderBy('sort_order')->orderBy('id')->paginate($limit);

        // "Where used" — for raw/packaging rows, which cooking products' recipes consume them.
        $rawIds = $products->getCollection()->where('type', '!=', 'cooking_product')->pluck('id');
        $usedInMap = [];
        if ($rawIds->isNotEmpty()) {
            $usedInMap = ProductRecipeLine::whereIn('raw_material_id', $rawIds)
                ->with('product:id,name,variant')
                ->get()
                ->groupBy('raw_material_id')
                ->map(function ($lines) {
                    return $lines->pluck('product')->filter()->unique('id')->map(function ($p) {
                        return trim($p->name . ($p->variant ? ' · ' . $p->variant : ''));
                    })->values();
                });
        }

        // How much of each material is still needed to prepare the PENDING orders —
        // so the chef sees "need 5 pcs" and clicks Buy. In the material's base unit.
        $demandMap = $this->pendingMaterialDemand($rawIds);

        $products->getCollection()->transform(function ($product) use ($usedInMap, $demandMap) {
            return [
                'id' => $product->id,
                'code' => $product->code,
                'bar_code' => $product->bar_code,
                'name' => $product->name,
                'variant' => $product->variant,
                'description' => $product->description,
                'image' => $product->image,
                'type' => $product->type,
                'sell_price' => $product->sell_price,
                'cost' => $product->cost,
                'unit' => $product->unit,
                'status' => $product->status,
                'category_id' => $product->category_id,
                'category_name' => $product->category_name,
                'base_unit_id' => $product->base_unit_id,
                'track_stock' => (bool) $product->track_stock,
                'min_stock' => $product->min_stock,
                'stock' => (float) $product->computed_stock,
                'used_in' => $product->type !== 'cooking_product'
                    ? ($usedInMap->get($product->id, collect())->all())
                    : null,
                // Typed recipe-line counts, so the Product tab can show at a glance
                // what each variant's BOM defines.
                'components_count' => (int) ($product->components_count ?? 0),
                'addons_count' => (int) ($product->addons_count ?? 0),
                // Base-unit qty still needed to prepare pending orders (0 = nothing outstanding).
                'needed' => $product->type !== 'cooking_product'
                    ? (float) ($demandMap[$product->id] ?? 0)
                    : 0,
            ];
        });

        return response()->json($products);
    }

    // Base-unit quantity of each given material still needed to prepare the
    // currently PENDING (unprepared) cooking orders — components + chosen add-ons.
    private function pendingMaterialDemand($rawIds): array
    {
        $demand = [];
        if ($rawIds->isEmpty()) {
            return $demand;
        }
        $wanted = $rawIds->flip(); // id => idx, for fast membership test

        $lines = \App\Models\InvoiceLine::whereNull('prepared_at')
            ->whereHas('item', fn($q) => $q->where('type', 'cooking_product'))
            ->with(['item.componentLines.rawMaterial', 'item.addOnLines.rawMaterial'])
            ->get(['id', 'product_id', 'quantity', 'addon_line_ids']);

        foreach ($lines as $line) {
            $item = $line->item;
            if (!$item) {
                continue;
            }
            $qty = (float) $line->quantity;
            $recipeLines = $item->componentLines;

            $chosen = array_filter(array_map('intval', (array) ($line->addon_line_ids ?? [])));
            if ($chosen) {
                $recipeLines = $recipeLines->concat($item->addOnLines->whereIn('id', $chosen));
            }

            foreach ($recipeLines as $rl) {
                $rm = $rl->rawMaterial;
                if (!$rm || !$wanted->has($rm->id)) {
                    continue;
                }
                try {
                    $factor = $rl->baseUnitFactor($rm);
                } catch (\Throwable $e) {
                    continue; // unit not resolvable → skip, don't guess
                }
                $demand[$rm->id] = ($demand[$rm->id] ?? 0) + (float) $rl->quantity * $factor * $qty;
            }
        }

        foreach ($demand as $k => $v) {
            $demand[$k] = round($v, 6);
        }
        return $demand;
    }

    public function store(Request $request)
    {



        $data = $request->validate([
            'code' => 'required|unique:product,code',
            'name' => 'required',
            'sell_price' => 'numeric',
            'cost' => 'numeric',
            'vat' => 'numeric',
            'discount_percent' => 'numeric',
            'type' => 'required|in:product,service,expence,raw_material,cooking_product,packaging_material',
            // Phone photos are routinely 5-20 MB; the thumbnailer downsizes them
            // anyway, so don't reject a normal camera shot (the old 2 MB cap did).
            'image' => 'nullable|image|max:20480',
        ]);

        // Add extra fields
        $data['bar_code'] = $request->input('bar_code');
        $data['variant'] = $request->input('variant');
        $data['description'] = $request->input('description');
        $data['min_stock'] = $request->input('min_stock', 0);
        $data['max_stock'] = $request->input('max_stock', 0);
        $data['category_id'] = $request->input('category_id');
        $data['category_name'] = $request->input('category_name');
        $data['unit'] = $request->input('unit');
        // Unit-of-measure conversions are raw/packaging material only — a finished
        // dish is always sold as one whole unit, so a base unit never applies.
        $data['base_unit_id'] = in_array($data['type'], ['raw_material', 'packaging_material'])
            ? ($request->input('base_unit_id') ?: null)
            : null;
        // 3-state status. The product form's "Active" checkbox posts 1 (ticked) or
        // 0 (not) → map to 1 Enable / 2 Disable. An explicit 3 (Under development,
        // set from the recipe modal) is preserved.
        $s = (int) $request->input('status', 1);
        $data['status'] = in_array($s, [2, 3], true) ? $s : ($s === 1 ? 1 : 2);
        // Read the flag's VALUE, not just its presence — the kitchen modal always
        // sends these keys as 0/1 (a plain has() would read "0" as true). Still
        // works for classic checkbox forms that omit the key when unchecked.
        $flag = fn($k) => $request->has($k)
            && in_array((string) $request->input($k), ['1', 'on', 'true'], true);
        $data['allow_discount'] = $flag('allow_discount');
        $data['allow_return'] = $flag('allow_return');
        $data['track_stock'] = $flag('track_stock');

        // Upload image
        if ($request->hasFile('image')) {
            $data['image'] = $this->uploadFileToPublic($request, 'image', $request->name);
        }
         $data['created_by'] = Auth::user()->username ?? 'System';
        Product::create($data);

        return response()->json([
            'status' => true,
            'message' => 'Product added successfully'
        ]);
    }




    public function update(Request $request, $id)
    {
        // 1️⃣ Find the product
        $product = Product::findOrFail($id);

        // 2️⃣ Validate required fields
        $request->validate([
            'code' => 'required|string',
            'name' => 'required|string',
        ]);

        // Toggling track_stock off (or on) while the product already carries stock would
        // silently orphan existing warehouse_product/ledger records from the stock system.
        // Require the stock to be zeroed out (via adjustment/transfer) before the flag can change.
        $currentStock = (float) $product->stock;
        $requestedTrackStock = $request->boolean('track_stock');
        if ($currentStock != 0 && (bool) $product->track_stock !== $requestedTrackStock) {
            return response()->json([
                'success' => false,
                'message' => "Cannot change Track Stock: this product currently has {$currentStock} in stock. Adjust stock to zero first.",
            ], 422);
        }

        DB::beginTransaction();

        try {
            /* ==========================
            HANDLE IMAGE UPLOAD
            ========================== */
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $folder = 'assets/startic_img';
                // Unique per upload (time + uniqid): a changed photo always gets a new
                // filename, so its thumb URL changes and the browser fetches the fresh
                // image instead of the immutably-cached old one. uniqid() also prevents
                // two edits in the same second from overwriting each other.
                $ext = $file->getClientOriginalExtension() ?: 'jpg';
                $filename = time() . '-' . uniqid() . '-' . ($request->code ?? 'product') . '.' . $ext;

                $file->move(public_path($folder), $filename);

                // Pre-build the resized versions the UI actually renders.
                self::warmThumbnails($filename);

                $product->image = $filename;
            }

            /* ==========================
            UPDATE PRODUCT
            ========================== */
          $product->update([
    // Accept the shared Add-Product form's field names (bar_code / discount_percent);
    // keep the old update-form names (barcode / discount) as a fallback.
    'bar_code'         => $request->input('bar_code', $request->input('barcode', '')),
    'code'             => $request->code,
    'name'             => $request->name,
    'variant'          => $request->variant ?? '',
    'description'      => $request->description ?? '',
    'min_stock'        => $request->min_stock ?? 0,
    'max_stock'        => $request->max_stock ?? 0,
    'cost'             => $request->cost ?? 0,
    'sell_price'       => $request->sell_price ?? 0,
    'vat'              => $request->vat ?? 0,
    'discount_percent' => $request->input('discount_percent', $request->input('discount', 0)),
    'category_id'      => $request->category_id ?? null,
    'category_name'    => $request->category_name ?? '',
    'unit'             => $request->unit ?? '',
    // base_unit_id is immutable after creation: recipe quantities, stock and the
    // consumption ledger are all recorded against it, so changing it would
    // silently corrupt history. The chef disables the material and makes a new
    // one instead. We always keep the existing value and ignore any incoming one.
    'base_unit_id'     => $product->base_unit_id,

    // no type here ✅

    'track_stock'      => $request->track_stock ? 1 : 0,
    'allow_discount'   => $request->allow_discount ? 1 : 0,
    'allow_return'     => $request->allow_return ? 1 : 0,
    // 3-state: 1 Enable / 2 Disable (Active checkbox), 3 Under development preserved.
    'status'           => in_array((int) $request->status, [2, 3], true)
        ? (int) $request->status
        : ((int) $request->status === 1 ? 1 : 2),
]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'product' => $product
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            logger()->error($e);

            return response()->json([
                'success' => false,
                'message' => 'Update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Active raw materials + packaging materials, for populating the recipe (BOM)
    // builder's ingredient picker (a pizza's recipe can consume both, e.g. cheese + box).
    // Each row carries its base unit, its alternate units (with the factor into the
    // base unit), and its weighted-average on-hand cost per base unit — everything
    // the recipe modal and chef purchase screen need for unit-aware quantities.
    // `search` + `limit` let the chef purchase screen query the DB as the user
    // types instead of shipping the whole material catalog to the browser.
    public function rawMaterials(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $limit = (int) $request->query('limit', 0);

        $query = Product::with(['baseUnit:id,code,name', 'unitConversions.unit:id,code,name'])
            ->whereIn('type', ['raw_material', 'packaging_material'])
            ->where('status', 1)
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->orderBy('name');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $rawMaterials = $query->get(['id', 'code', 'name', 'unit', 'type', 'base_unit_id', 'cost']);

        // One grouped query for weighted average cost (Σ qty×cost / Σ qty over positive lots).
        $avgByProduct = DB::table('warehouse_product')
            ->whereIn('product_id', $rawMaterials->pluck('id'))
            ->where('quantity', '>', 0)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity * cost) as total_value, SUM(quantity) as total_qty')
            ->get()
            ->keyBy('product_id');

        // Net on-hand (all lots incl. negatives) — shown on the chef purchase tab.
        $stockByProduct = DB::table('warehouse_product')
            ->whereIn('product_id', $rawMaterials->pluck('id'))
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(quantity), 0) as stock')
            ->pluck('stock', 'product_id');

        $out = $rawMaterials->map(function ($rm) use ($avgByProduct, $stockByProduct) {
            $lots = $avgByProduct->get($rm->id);
            $avgCost = ($lots && (float) $lots->total_qty > 0 && (float) $lots->total_value > 0)
                ? round((float) $lots->total_value / (float) $lots->total_qty, 6)
                : (float) ($rm->cost ?? 0);

            return [
                'stock' => (float) ($stockByProduct[$rm->id] ?? 0),
                'id' => $rm->id,
                'code' => $rm->code,
                'name' => $rm->name,
                'type' => $rm->type,
                'unit' => $rm->unit,
                'base_unit_id' => $rm->base_unit_id,
                'base_unit_code' => optional($rm->baseUnit)->code ?? $rm->unit,
                'avg_cost' => $avgCost, // per base unit
                // Units this material can be entered in: base first (factor 1), then alternates.
                'units' => collect([
                    $rm->base_unit_id ? [
                        'unit_id' => $rm->base_unit_id,
                        'code' => optional($rm->baseUnit)->code,
                        'factor' => 1,
                    ] : null,
                ])->filter()->concat(
                    $rm->unitConversions->map(fn($c) => [
                        'unit_id' => $c->unit_id,
                        'code' => optional($c->unit)->code,
                        'factor' => (float) $c->factor,
                    ])
                )->values(),
            ];
        });

        return response()->json($out);
    }

    // Materials the PENDING (unprepared) cooking orders still need, with the full
    // purchase-row shape (same as rawMaterials) plus `needed` (base-unit demand)
    // and `shortfall` (demand not covered by current stock). Powers the purchase
    // modal's "Get Needed" button, which pre-fills a line per material at the
    // needed quantity so the chef can buy everything in one go.
    public function neededMaterials(Request $request)
    {
        $materials = Product::with(['baseUnit:id,code,name', 'unitConversions.unit:id,code,name'])
            ->whereIn('type', ['raw_material', 'packaging_material'])
            ->where('status', 1)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'unit', 'type', 'base_unit_id', 'cost']);

        // Base-unit qty each material still needs to cover the pending orders.
        $demandMap = $this->pendingMaterialDemand($materials->pluck('id'));

        // Only keep materials the pending orders actually need.
        $needed = $materials->filter(fn($rm) => (float) ($demandMap[$rm->id] ?? 0) > 0)->values();

        if ($needed->isEmpty()) {
            return response()->json([]);
        }

        $ids = $needed->pluck('id');

        // Weighted-average on-hand cost per base unit (positive lots only).
        $avgByProduct = DB::table('warehouse_product')
            ->whereIn('product_id', $ids)
            ->where('quantity', '>', 0)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity * cost) as total_value, SUM(quantity) as total_qty')
            ->get()
            ->keyBy('product_id');

        // Net on-hand across all lots (incl. negatives).
        $stockByProduct = DB::table('warehouse_product')
            ->whereIn('product_id', $ids)
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(quantity), 0) as stock')
            ->pluck('stock', 'product_id');

        $out = $needed->map(function ($rm) use ($avgByProduct, $stockByProduct, $demandMap) {
            $lots = $avgByProduct->get($rm->id);
            $avgCost = ($lots && (float) $lots->total_qty > 0 && (float) $lots->total_value > 0)
                ? round((float) $lots->total_value / (float) $lots->total_qty, 6)
                : (float) ($rm->cost ?? 0);
            $stock = (float) ($stockByProduct[$rm->id] ?? 0);
            $need  = (float) ($demandMap[$rm->id] ?? 0);

            return [
                'stock' => $stock,
                'id' => $rm->id,
                'code' => $rm->code,
                'name' => $rm->name,
                'type' => $rm->type,
                'unit' => $rm->unit,
                'base_unit_id' => $rm->base_unit_id,
                'base_unit_code' => optional($rm->baseUnit)->code ?? $rm->unit,
                'avg_cost' => $avgCost, // per base unit
                'needed' => round($need, 6),                        // full pending demand (base unit)
                'shortfall' => max(0, round($need - $stock, 6)),    // qty not covered by stock
                'units' => collect([
                    $rm->base_unit_id ? [
                        'unit_id' => $rm->base_unit_id,
                        'code' => optional($rm->baseUnit)->code,
                        'factor' => 1,
                    ] : null,
                ])->filter()->concat(
                    $rm->unitConversions->map(fn($c) => [
                        'unit_id' => $c->unit_id,
                        'code' => optional($c->unit)->code,
                        'factor' => (float) $c->factor,
                    ])
                )->values(),
            ];
        })
        // Drop materials whose current stock already covers the pending demand —
        // those don't need buying, so "Get Needed" shouldn't pull them in.
        ->filter(fn($r) => $r['shortfall'] > 0)
        ->values();

        return response()->json($out);
    }

    // Alternate units a product is commonly bought/sold in (e.g. "kg" for a
    // product whose base unit is "g"), with the factor to convert into its base unit.
    public function unitConversions($id)
    {
        $product = Product::findOrFail($id);

        return response()->json([
            'base_unit' => $product->baseUnit,
            'conversions' => $product->unitConversions()->with('unit')->get(),
        ]);
    }

    public function storeUnitConversion(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        if (!in_array($product->type, ['raw_material', 'packaging_material'])) {
            return response()->json(['status' => false, 'message' => 'Unit conversions only apply to raw material / packaging material products.'], 422);
        }

        $data = $request->validate([
            'unit_id' => 'required|exists:units_of_measure,id',
            'factor' => 'required|numeric|gt:0',
        ]);

        if ((int) $data['unit_id'] === (int) $product->base_unit_id) {
            return response()->json(['status' => false, 'message' => 'That is already this product\'s base unit.'], 422);
        }

        ProductUnitConversion::updateOrCreate(
            ['product_id' => $product->id, 'unit_id' => $data['unit_id']],
            ['factor' => $data['factor'], 'created_by' => Auth::user()->username ?? 'System']
        );

        return response()->json(['status' => true, 'message' => 'Unit conversion saved.']);
    }

    public function destroyUnitConversion($conversionId)
    {
        ProductUnitConversion::where('id', $conversionId)->delete();

        return response()->json(['status' => true, 'message' => 'Unit conversion removed.']);
    }

    // Typed recipe (BOM) for a cooking product / variant: components (always
    // consumed) and add-ons (optional extras), each unit-aware, plus the
    // computed average material cost — display-only; the chef's manual cost
    // on the product row stays authoritative.
    public function recipe($id)
    {
        $product = Product::findOrFail($id);

        $lines = $product->recipeLines()
            ->with(['rawMaterial.baseUnit', 'rawMaterial.unitConversions.unit', 'unitOfMeasure:id,code'])
            ->get();

        $componentAvgTotal = 0.0;

        $mapped = $lines->map(function ($line) use (&$componentAvgTotal) {
            $rm = $line->rawMaterial;

            // A line whose unit can't be resolved to the material's base unit
            // (e.g. an ingredient missing its gram conversion) must not crash the
            // whole modal — surface it as a flagged row so the chef can open the
            // recipe and fix it, rather than being locked out entirely.
            $factor = null;
            $unitError = null;
            try {
                $factor = $line->baseUnitFactor($rm);
            } catch (\Throwable $e) {
                $unitError = $e->getMessage();
            }

            $avgUnitCost = $rm ? $rm->averageCost() : 0.0; // per base unit
            $lineAvgCost = $factor !== null ? round((float) $line->quantity * $factor * $avgUnitCost, 6) : 0.0;

            if ($factor !== null && $line->line_type === ProductRecipeLine::TYPE_COMPONENT) {
                $componentAvgTotal += $lineAvgCost;
            }

            return [
                'id'                => $line->id,
                'line_type'         => $line->line_type,
                'addon_name'        => $line->addon_name,
                'raw_material_id'   => $line->raw_material_id,
                'raw_material_name' => optional($rm)->name,
                'quantity'          => $line->quantity,
                'unit'              => $line->unit,
                'unit_id'           => $line->unit_id,
                'extra_price'       => $line->extra_price,
                'base_qty'          => $factor !== null ? round((float) $line->quantity * $factor, 6) : null,
                'avg_line_cost'     => $lineAvgCost,
                'unit_error'        => $unitError,
            ];
        });

        // Sibling variants of the same dish (same name) so the modal can switch
        // between S / M / L without closing — each carries its own recipe.
        $variants = Product::where('type', 'cooking_product')
            ->where('name', $product->name)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'variant', 'sell_price', 'sort_order', 'status'])
            ->map(fn($v) => [
                'id' => $v->id,
                'variant' => $v->variant,
                'sell_price' => $v->sell_price,
                'sort_order' => $v->sort_order,
                'status' => (int) $v->status,   // 1 enable, 2 disable, 3 under development
                'is_current' => $v->id === $product->id,
            ]);

        // Cooking routine — ordered prep steps for THIS variant, each with its
        // own labour cost. Their sum is the routing cost added on top of material.
        $steps = $product->recipeSteps()->get(['step_no', 'instruction', 'cost'])
            ->map(fn($s) => [
                'step_no' => $s->step_no,
                'instruction' => $s->instruction,
                'cost' => (float) $s->cost,
            ])
            ->values();

        // Steps are the source once any exist; otherwise fall back to the flat
        // routing_cost held on the product (recipes created before per-step costs).
        $routingCost = $steps->count()
            ? round($steps->sum('cost'), 4)
            : (float) ($product->routing_cost ?? 0);

        return response()->json([
            'product_id' => $product->id,
            'name'       => $product->name,
            'variant'    => $product->variant,
            'type'       => $product->type,
            'sell_price' => $product->sell_price,
            'chef_cost'  => $product->cost,                       // manual, authoritative
            'routing_cost' => $routingCost,                       // labour/prep, per unit, per variant
            'avg_cost'   => round($componentAvgTotal, 6),         // materials only, computed
            'total_cost' => round($componentAvgTotal + $routingCost, 6), // material + routing
            'components' => $mapped->where('line_type', ProductRecipeLine::TYPE_COMPONENT)->values(),
            'addons'     => $mapped->where('line_type', ProductRecipeLine::TYPE_ADD_ON)->values(),
            'routine'    => $steps,
            'status'     => (int) $product->status,   // 1 enable, 2 disable, 3 under development
            'variants'   => $variants,
        ]);
    }

    // Take a single cooking-product variant off the menu (or put it back) without
    // deleting it — its recipe, costs and sales history are all preserved. Only the
    // Sale screen's `status = 1` filter changes what the cashier sees.
    public function toggleStatus(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        // 3-state: 1 = Enable (on sale), 2 = Disable (hidden), 3 = Under development
        // (hidden while the chef edits/tests). An explicit `status` sets it directly;
        // with none, fall back to the old Enable↔Disable toggle.
        $new = (int) $request->input('status', 0);
        if (in_array($new, [1, 2, 3], true)) {
            $product->status = $new;
        } else {
            $product->status = ((int) $product->status === 1) ? 2 : 1;
        }
        $product->save();

        $messages = [
            1 => 'Variant enabled — it is back on the menu.',
            2 => 'Variant disabled — it no longer shows on the Sale screen.',
            3 => 'Variant set to Under development — hidden from the cashier while you edit.',
        ];

        return response()->json([
            'status'  => true,
            'value'   => (int) $product->status,
            'enabled' => (int) $product->status === 1,
            'message' => $messages[(int) $product->status] ?? 'Variant status updated.',
        ]);
    }

    // Rename a cooking-product variant's label (e.g. "M" → "Medium"). Only the
    // `variant` field changes; the dish `name` (and therefore its grouping with
    // sibling sizes on the Sale screen) is untouched.
    public function renameVariant(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        if ($product->type !== 'cooking_product') {
            return response()->json(['status' => false, 'message' => 'Only cooking-product variants can be renamed here.'], 422);
        }

        $data = $request->validate([
            'variant' => 'required|string|max:100',
        ]);

        $product->variant = trim($data['variant']);
        $product->save();

        return response()->json([
            'status'  => true,
            'variant' => $product->variant,
            'message' => 'Variant renamed to "' . $product->variant . '".',
        ]);
    }

    // Where a material is used across all recipes: which dish/variant, as a
    // component or add-on, how much per unit sold (converted to the material's
    // BASE unit), and the cost of that amount at the material's current cost.
    public function materialUsage($id)
    {
        $material = Product::with('baseUnit')->findOrFail($id);

        $lines = ProductRecipeLine::with(['product:id,name,variant', 'unitOfMeasure:id,code'])
            ->where('raw_material_id', $id)
            ->get();

        $baseUnit = optional($material->baseUnit)->code ?? $material->unit;
        $unitCost = (float) ($material->cost ?? 0);   // per base unit

        $rows = $lines->map(function ($line) use ($material, $baseUnit, $unitCost) {
            // Don't let one unresolvable recipe line crash the whole usage report.
            try {
                $factor = $line->baseUnitFactor($material);
                $baseQty = round((float) $line->quantity * $factor, 6);
                $cost = round($baseQty * $unitCost, 6);
            } catch (\Throwable $e) {
                $baseQty = null;
                $cost = 0.0;
            }
            return [
                'dish'       => optional($line->product)->name,
                'variant'    => optional($line->product)->variant,
                'line_type'  => $line->line_type,
                'addon_name' => $line->addon_name,
                'entered'    => trim($line->quantity . ' ' . ($line->unit ?? '')),
                'base_qty'   => $baseQty,
                'base_unit'  => $baseUnit,
                'cost'       => $cost,
            ];
        })->sortBy([['dish', 'asc'], ['line_type', 'asc']])->values();

        return response()->json([
            'material'   => ['id' => $material->id, 'name' => $material->name, 'base_unit' => $baseUnit, 'unit_cost' => $unitCost],
            'usage'      => $rows,
            'used_count' => $rows->count(),
        ]);
    }

    // Distinct finished goods (one row per dish name) for the Material tab's
    // "used in" filter — lets the chef see every material a given dish consumes.
    public function cookingProductList()
    {
        $dishes = Product::where('type', 'cooking_product')
            ->where('status', 1)
            ->orderBy('name')
            ->orderBy('sort_order')
            ->get(['id', 'name'])
            ->unique('name')          // collapse variants → one entry per dish
            ->map(fn($p) => ['id' => $p->id, 'name' => $p->name])
            ->values();

        return response()->json($dishes);
    }

    // Clone a cooking product into a new size/variant: copies everything (category,
    // image, description, VAT, and the full component + add-on recipe) so the chef
    // only has to set what actually differs — the variant name, sell price and the
    // baseline cost. The real cost is still recomputed from raw/packaging usage.
    public function duplicateVariant(Request $request, $id)
    {
        $source = Product::findOrFail($id);

        if ($source->type !== 'cooking_product') {
            return response()->json(['status' => false, 'message' => 'Only cooking products can have variants.'], 422);
        }

        $data = $request->validate([
            'variant'    => 'required|string|max:100',
            'sell_price' => 'nullable|numeric|min:0',
            'cost'       => 'nullable|numeric|min:0',
            'copy_recipe' => 'nullable|boolean',
        ]);

        // Code is always derived from the source: PZ-MAR-01 + "Large" → PZ-MAR-01-LARGE
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim($data['variant'])));
        $base = $source->code . '-' . $slug;
        $code = $base;
        $n = 2;
        while (Product::where('code', $code)->exists()) {
            $code = $base . '-' . $n++;
        }

        // New variants land at the end of the dish's existing order.
        $nextSort = (int) Product::where('type', 'cooking_product')
            ->where('name', $source->name)
            ->max('sort_order') + 1;

        DB::beginTransaction();
        try {
            $clone = $source->replicate([
                'id', 'created_at', 'updated_at',
            ]);
            $clone->code = $code;
            $clone->variant = $data['variant'];
            $clone->sort_order = $nextSort;
            $clone->sell_price = $data['sell_price'] ?? $source->sell_price;
            $clone->cost = $data['cost'] ?? $source->cost;
            $clone->created_by = Auth::user()->username ?? 'System';
            $clone->save();

            // Same BOM by default — the chef tweaks quantities per size afterwards.
            if ($data['copy_recipe'] ?? true) {
                foreach ($source->recipeLines as $line) {
                    $newLine = $line->replicate(['id', 'created_at', 'updated_at']);
                    $newLine->product_id = $clone->id;
                    $newLine->created_by = Auth::user()->username ?? 'System';
                    $newLine->save();
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Failed to create variant: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'status' => true,
            'message' => "Variant \"{$data['variant']}\" created.",
            'product' => ['id' => $clone->id, 'name' => $clone->name, 'variant' => $clone->variant, 'code' => $clone->code],
        ]);
    }

    // Persist the order the chef dragged/arranged the variants into (S, M, L, XL).
    public function reorderVariants(Request $request)
    {
        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:product,id',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['ids'] as $position => $id) {
                Product::where('id', $id)->update(['sort_order' => $position + 1]);
            }
        });

        return response()->json(['status' => true, 'message' => 'Variant order saved.']);
    }

    // Replace the typed recipe (BOM) of a cooking product / variant: components
    // (always consumed) and add-ons (optional extras with their own consumption
    // and optional extra charge).
    public function saveRecipe(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        if ($product->type !== 'cooking_product') {
            return response()->json([
                'status'  => false,
                'message' => 'Recipes can only be set on Cooking Product items.',
            ], 422);
        }

        $data = $request->validate([
            'recipe'                    => 'array',
            'recipe.*.raw_material_id'  => 'required|integer|exists:product,id',
            'recipe.*.line_type'        => 'required|in:component,add_on',
            'recipe.*.addon_name'       => 'nullable|string|max:100|required_if:recipe.*.line_type,add_on',
            'recipe.*.quantity'         => 'required|numeric|min:0.0001',
            'recipe.*.unit'             => 'nullable|string|max:50',
            'recipe.*.unit_id'          => 'nullable|integer|exists:units_of_measure,id',
            'recipe.*.extra_price'      => 'nullable|numeric|min:0',
            // Editable inline from the Manage Recipe cost strip — null/absent leaves them untouched.
            'sell_price'                 => 'nullable|numeric|min:0',
            'cost'                       => 'nullable|numeric|min:0',
            'routing_cost'               => 'nullable|numeric|min:0',
            // Cooking routine — ordered prep steps for THIS variant. Each entry is
            // {instruction, cost}; plain strings are still accepted (older clients)
            // and treated as a zero-cost step. Shapes are normalised below rather
            // than with routine.* rules, which can't express "string OR object".
            'routine'                    => 'nullable|array',
        ]);

        $rawMaterialIds = collect($data['recipe'] ?? [])->pluck('raw_material_id')->unique();
        $invalidCount = Product::whereIn('id', $rawMaterialIds)->whereNotIn('type', ['raw_material', 'packaging_material'])->count();
        if ($invalidCount > 0) {
            return response()->json([
                'status'  => false,
                'message' => 'One or more ingredients are not raw materials or packaging materials.',
            ], 422);
        }

        // A line's unit must be one the material actually understands: its base
        // unit or one of its alternate units. Anything else would deduct stock
        // with a silently wrong factor.
        foreach ($data['recipe'] ?? [] as $i => $line) {
            if (empty($line['unit_id'])) {
                continue;
            }
            $rm = Product::find($line['raw_material_id']);
            $ok = ((int) $line['unit_id'] === (int) $rm->base_unit_id)
                || $rm->unitConversions()->where('unit_id', $line['unit_id'])->exists();
            if (!$ok) {
                return response()->json([
                    'status'  => false,
                    'message' => "Line " . ($i + 1) . ": that unit is not defined for {$rm->name}. Add it as an alternate unit first.",
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $components = collect($data['recipe'] ?? [])->where('line_type', 'component')->values();
            $addons = collect($data['recipe'] ?? [])->where('line_type', 'add_on')->values();

            // Components are variant-specific (a Large uses more dough than a
            // Small) — apply only to the variant being edited.
            $product->recipeLines()->where('line_type', 'component')->delete();
            foreach ($components as $line) {
                $product->recipeLines()->create([
                    'raw_material_id' => $line['raw_material_id'],
                    'line_type'       => 'component',
                    'addon_name'      => null,
                    'quantity'        => $line['quantity'],
                    'unit'            => $line['unit'] ?? null,
                    'unit_id'         => $line['unit_id'] ?? null,
                    'extra_price'     => 0,
                    'created_by'      => Auth::user()->username ?? 'System',
                ]);
            }

            // Add-ons belong to THIS variant only — a Large can offer "Add Mushroom
            // +20g" while a Small offers "+10g", each with its own price. Apply the
            // edited set to the open variant, exactly like components.
            $product->recipeLines()->where('line_type', 'add_on')->delete();
            foreach ($addons as $line) {
                $product->recipeLines()->create([
                    'raw_material_id'  => $line['raw_material_id'],
                    'line_type'        => 'add_on',
                    'addon_name'       => $line['addon_name'] ?? null,
                    'quantity'         => $line['quantity'],
                    'unit'             => $line['unit'] ?? null,
                    'unit_id'          => $line['unit_id'] ?? null,
                    'extra_price'      => $line['extra_price'] ?? 0,
                    'created_by'       => Auth::user()->username ?? 'System',
                ]);
            }

            // Sell Price / Chef Cost are edited inline in the same modal — this
            // variant only (unlike add-ons, price legitimately differs by size).
            $priceUpdate = [];
            if (array_key_exists('sell_price', $data) && $data['sell_price'] !== null) {
                $priceUpdate['sell_price'] = $data['sell_price'];
            }
            if (array_key_exists('cost', $data) && $data['cost'] !== null) {
                $priceUpdate['cost'] = $data['cost'];
            }
            if (array_key_exists('routing_cost', $data) && $data['routing_cost'] !== null) {
                $priceUpdate['routing_cost'] = $data['routing_cost'];
            }
            if (!empty($priceUpdate)) {
                $product->update($priceUpdate);
            }

            // Cooking routine — replace this variant's steps (per-variant, like
            // components). Only present when the client sends the key, so saving
            // from a client that doesn't manage routine leaves existing steps be.
            if (array_key_exists('routine', $data)) {
                $product->recipeSteps()->delete();
                $stepNo = 1;
                $routingTotal = 0.0;
                foreach (($data['routine'] ?? []) as $row) {
                    // Accept {instruction, cost} or a bare string (older clients).
                    $instruction = trim((string) (is_array($row) ? ($row['instruction'] ?? '') : $row));
                    $stepCost = is_array($row) ? (float) ($row['cost'] ?? 0) : 0.0;
                    if ($instruction === '') {
                        continue; // skip blank rows
                    }
                    $stepCost = max(0, round($stepCost, 4));
                    $routingTotal += $stepCost;
                    ProductRecipeStep::create([
                        'product_id'  => $product->id,
                        'step_no'     => $stepNo++,
                        'instruction' => $instruction,
                        'cost'        => $stepCost,
                        'created_by'  => Auth::user()->username ?? 'System',
                    ]);
                }
                // Steps are the source of truth for routing cost once any exist —
                // keep product.routing_cost as their rolled-up total so the
                // production ledger and cost reports need no extra lookup.
                if ($stepNo > 1) {
                    $product->update(['routing_cost' => round($routingTotal, 4)]);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Failed to save recipe: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Recipe saved successfully',
        ]);
    }

    public function searchByCategory(Request $request)
    {
        $query = trim($request->input('query', ''));
        $field = $request->input('field', 'name');

        $warehouse_ids = Auth::user()->warehouses->pluck('id');

        // ✅ must match the DB column AND what the frontend <select> sends
        $allowedFields = ['name', 'description', 'code', 'bar_code'];
        if (!in_array($field, $allowedFields)) {
            $field = 'name';
        }

        $sql = Product::with([
            'warehouses' => function ($q) use ($warehouse_ids) {
                $q->whereIn('warehouse_id', $warehouse_ids);
            },
            'addOnLines',
        ]);

        if (!in_array(Auth::user()->role, ['admin', 'supervisor'])) {
            $sql->whereIn('type', ['product', 'service']);
        }

        // Raw/packaging materials are chef stock, not sellable menu items — never list them on the Sale screen.
        $sql->whereNotIn('type', ['raw_material', 'packaging_material']);

        $sql->where('status', 1);

        $sql->when($query !== '', function ($q) use ($field, $query) {
            if ($field === 'bar_code') {
                // exact match → guarantees single result → auto add-to-cart works
                $q->where('bar_code', $query);
            } else {
                $q->where($field, 'LIKE', "%{$query}%");
            }
        });

        $products = $sql->limit(41)->get();

        $products->each(function ($product) {
            $product->total_stock = $product->warehouses->sum(function ($wh) {
                return $wh->pivot->quantity ?? 0;
            });
            $product->setAttribute('addons', \App\Http\Controllers\AdminController::addonList($product));
        });

        $products = $products->sortBy([
            fn($a, $b) => ($b->total_stock > 0) <=> ($a->total_stock > 0),
            fn($a, $b) => strnatcasecmp($a->name ?? '', $b->name ?? ''),
        ])->values();

        return response()->json($products);
    }

/**
     * Small cached JPEG thumbnail for Excel embedding.
     * Original 2MB photos → ~5-10KB thumbs. Cached in storage so
     * repeat exports are instant.
     */

    public function exportProducts(Request $request): StreamedResponse
    {
        $query = Product::query()->with('category');
        $withImages = $request->input('images', '1') === '1';
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%$s%")
                    ->orWhere('name', 'like', "%$s%")
                    ->orWhere('bar_code', 'like', "%$s%")
                    ->orWhere('variant', 'like', "%$s%")
                    ->orWhere('description', 'like', "%$s%");
            });
        }
        if ($request->filled('type'))   $query->where('category_id', $request->type);
        if ($request->filled('status') && is_numeric($request->status)) {
            $query->where('status', $request->status);
        }

        $products = $query->orderBy('category_name')->orderBy('name')->get();

        // total stock per product (all warehouses, summed across lots)
        $stocks = DB::table('warehouse_product')
            ->whereIn('product_id', $products->pluck('id'))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->pluck('qty', 'product_id');

        // ── aggregate ──
        $total = count($products);
        $active = $products->where('status', 1)->count();
        $tracked = $products->where('track_stock', 1)->count();
        $stockValue = 0; $stockUnits = 0;
        $byCategory = [];
        foreach ($products as $p) {
            $qty = (float) ($stocks[$p->id] ?? 0);
            $stockUnits += $qty;
            $stockValue += $qty * (float) ($p->cost ?: 0);

            $ck = $p->category_name ?: '(uncategorised)';
            $byCategory[$ck] = $byCategory[$ck] ?? ['count' => 0, 'qty' => 0, 'value' => 0];
            $byCategory[$ck]['count']++;
            $byCategory[$ck]['qty'] += $qty;
            $byCategory[$ck]['value'] += $qty * (float) ($p->cost ?: 0);
        }
        uasort($byCategory, fn($a, $b) => $b['count'] <=> $a['count']);

        $BAR = 'FF0F172A'; $INK = 'FF1E293B'; $CARD = 'FFF8FAFC'; $LINE = 'FFE2E8F0';
        $CYAN = 'FF0891B2'; $GREEN = 'FF059669'; $AMBER = 'FFD97706'; $VIOLET = 'FF7C3AED';
        $BLUE = 'FF2563EB'; $SUBTXT = 'FF64748B';
        $usdFmt = '"$"#,##0.00;[Red]-"$"#,##0.00';

        $ss = new Spreadsheet();
        $ss->getDefaultStyle()->getFont()->setName('Khmer OS Siemreap')->setSize(10);

        /* ================= SHEET 1 — SUMMARY ================= */
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Summary');
        $sh->setShowGridlines(false);
        foreach (['A' => 26, 'B' => 12, 'C' => 12, 'D' => 15, 'E' => 3, 'F' => 13, 'G' => 13,
                  'H' => 13, 'I' => 13, 'J' => 13, 'K' => 13, 'L' => 13, 'M' => 13, 'N' => 13] as $c => $w) {
            $sh->getColumnDimension($c)->setWidth($w);
        }

        $sh->mergeCells('A1:N1');
        $sh->setCellValue('A1', 'PRODUCT REPORT');
        $sh->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 20, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $BAR]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sh->getRowDimension(1)->setRowHeight(38);

        $sh->mergeCells('A2:N2');
        $sh->setCellValue('A2',
            'Search: ' . ($request->search ?: 'All')
            . '     ·     Status: ' . ($request->status === '1' ? 'Active' : ($request->status === '0' ? 'Inactive' : 'All'))
            . '     ·     By ' . (Auth::user()->username ?? 'System')
            . '  at ' . now()->format('d M Y H:i'));
        $sh->getStyle('A2')->applyFromArray([
            'font' => ['size' => 10, 'color' => ['argb' => 'FFCBD5E1']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $INK]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sh->getRowDimension(2)->setRowHeight(20);

        // KPI cards
        $cards = [
            ['PRODUCTS',       $total,      '#,##0',    $BLUE,   'A', 'B'],
            ['ACTIVE',         $active,     '#,##0',    $GREEN,  'C', 'D'],
            ['TRACK STOCK',    $tracked,    '#,##0',    $CYAN,   'F', 'G'],
            ['CATEGORIES',     count($byCategory), '#,##0', $AMBER, 'H', 'I'],
            ['STOCK UNITS',    $stockUnits, '#,##0.##', $VIOLET, 'K', 'L'],
            ['STOCK VALUE',    $stockValue, $usdFmt,    $GREEN,  'M', 'N'],
        ];
        $sh->getRowDimension(4)->setRowHeight(16);
        $sh->getRowDimension(5)->setRowHeight(26);
        foreach ($cards as [$label, $value, $fmt, $accent, $c1, $c2]) {
            $sh->mergeCells("{$c1}4:{$c2}4");
            $sh->mergeCells("{$c1}5:{$c2}5");
            $sh->setCellValue("{$c1}4", $label);
            $sh->setCellValue("{$c1}5", $value);
            $sh->getStyle("{$c1}4:{$c2}5")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $CARD]],
                'borders' => [
                    'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $LINE]],
                    'top'     => ['borderStyle' => Border::BORDER_THICK, 'color' => ['argb' => $accent]],
                ],
            ]);
            $sh->getStyle("{$c1}4")->applyFromArray([
                'font' => ['bold' => true, 'size' => 8, 'color' => ['argb' => $SUBTXT]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sh->getStyle("{$c1}5")->applyFromArray([
                'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => $INK]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sh->getStyle("{$c1}5")->getNumberFormat()->setFormatCode($fmt);
        }

        // BY CATEGORY table (A:D)
        $sh->mergeCells('A7:D7');
        $sh->setCellValue('A7', 'BY CATEGORY');
        $sh->getStyle('A7')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $INK]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
            'borders' => ['left' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['argb' => $CYAN]]],
        ]);
        $sh->getRowDimension(7)->setRowHeight(20);

        $r = 8;
        foreach (['A' => 'Category', 'B' => 'Products', 'C' => 'Stock Qty', 'D' => 'Stock Value'] as $col => $txt) {
            $sh->setCellValue("{$col}{$r}", $txt);
        }
        $sh->getStyle("A{$r}:D{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => $SUBTXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $CARD]],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $LINE]]],
        ]);
        $r++;
        $catStart = $r;
        foreach ($byCategory as $cname => $c) {
            $sh->setCellValue("A{$r}", $cname);
            $sh->setCellValue("B{$r}", $c['count']);
            $sh->setCellValue("C{$r}", round($c['qty'], 2));
            $sh->setCellValue("D{$r}", round($c['value'], 2));
            $sh->getStyle("C{$r}")->getNumberFormat()->setFormatCode('#,##0.##');
            $sh->getStyle("D{$r}")->getNumberFormat()->setFormatCode($usdFmt);
            if (($r - $catStart) % 2 === 1) {
                $sh->getStyle("A{$r}:D{$r}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($CARD);
            }
            $r++;
        }
        $catEnd = $r - 1;

        // charts
        $mkFills = function (int $count) {
            $palette = ['0891B2', '059669', 'D97706', '7C3AED', 'E11D48', '2563EB', 'DB2777', '65A30D'];
            $out = [];
            for ($i = 0; $i < $count; $i++) $out[] = $palette[$i % count($palette)];
            return $out;
        };
        $addChart = function (string $name, string $title, string $type, ?string $grouping,
                              int $lblRow, int $s, int $e, string $catCol, string $valCol,
                              string $tl, string $br, bool $pct) use ($sh, $mkFills) {
            $count = $e - $s + 1;
            if ($count < 1) return;
            $labels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "Summary!\${$valCol}\${$lblRow}", null, 1)];
            $cats   = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "Summary!\${$catCol}\${$s}:\${$catCol}\${$e}", null, $count)];
            $vals   = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "Summary!\${$valCol}\${$s}:\${$valCol}\${$e}", null, $count);
            if (method_exists($vals, 'setFillColor')) {
                try {
                    $vals->setFillColor($type === DataSeries::TYPE_PIECHART ? $mkFills($count) : $mkFills(1)[0]);
                } catch (\Throwable $x) {}
            }
            $series = new DataSeries($type, $grouping, range(0, 0), $labels, $cats, [$vals]);
            if ($type === DataSeries::TYPE_BARCHART) $series->setPlotDirection(DataSeries::DIRECTION_COL);
            $layout = new Layout();
            $pct ? $layout->setShowPercent(true) : $layout->setShowVal(true);
            $chart = new Chart($name, new Title($title), new Legend(Legend::POSITION_RIGHT, null, false), new PlotArea($layout, [$series]));
            $chart->setTopLeftPosition($tl);
            $chart->setBottomRightPosition($br);
            $sh->addChart($chart);
        };

        $addChart('cat_pie', 'Products by Category (%)', DataSeries::TYPE_PIECHART, null,
            $catStart - 1, $catStart, $catEnd, 'A', 'B', 'F7', 'N23', true);
        $addChart('val_bar', 'Stock Value by Category', DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED,
            $catStart - 1, $catStart, $catEnd, 'A', 'D', 'F25', 'N41', false);

        /* ============ SHEET 2 — PRODUCT DATA (with images) ============ */
        $sh2 = $ss->createSheet();
        $sh2->setTitle('Products');
        $sh2->setShowGridlines(false);

        $cols = ['Image', 'Code', 'Barcode', 'Name', 'Variant', 'Description', 'Category',
                 'Unit', 'Stock Qty', 'Cost', 'Sell Price', 'VAT %', 'Disc %',
                 'Min', 'Max', 'Track', 'Status'];
        $sh2->fromArray($cols, null, 'A1');
        $sh2->getStyle('A1:Q1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $BAR]],
        ]);
        $sh2->getRowDimension(1)->setRowHeight(20);
        $sh2->freezePane('A2');

        $r = 2;
        foreach ($products as $p) {
            $qty = (float) ($stocks[$p->id] ?? 0);

            $sh2->setCellValue("B{$r}", $p->code);
            $sh2->setCellValueExplicit("C{$r}", (string) $p->bar_code,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);   // keep leading zeros
            $sh2->setCellValue("D{$r}", $p->name);
            $sh2->setCellValue("E{$r}", $p->variant);
            $sh2->setCellValue("F{$r}", $p->description);
            $sh2->setCellValue("G{$r}", $p->category_name);
            $sh2->setCellValue("H{$r}", $p->unit);
            $sh2->setCellValue("I{$r}", $qty);
            $sh2->setCellValue("J{$r}", (float) $p->cost);
            $sh2->setCellValue("K{$r}", (float) $p->sell_price);
            $sh2->setCellValue("L{$r}", (float) $p->vat);
            $sh2->setCellValue("M{$r}", (float) $p->discount_percent);
            $sh2->setCellValue("N{$r}", (float) $p->min_stock);
            $sh2->setCellValue("O{$r}", (float) $p->max_stock);
            $sh2->setCellValue("P{$r}", $p->track_stock ? 'Yes' : 'No');
            $sh2->setCellValue("Q{$r}", $p->status ? 'Active' : 'Inactive');

            // embedded image (skip silently if file missing)

             if ($withImages) {
                $imgPath = $p->image ? public_path('assets/startic_img/' . $p->image) : null;
                if ($imgPath && is_file($imgPath)) {
                    $thumb = $this->excelThumb($imgPath);
                    if ($thumb) {
                        try {
                            $drawing = new Drawing();
                            $drawing->setPath($thumb);
                            $drawing->setHeight(48);
                            $drawing->setCoordinates("A{$r}");
                            $drawing->setOffsetX(4);
                            $drawing->setOffsetY(3);
                            $drawing->setWorksheet($sh2);
                        } catch (\Throwable $x) {}
                    }
                }
            }
            $sh2->getRowDimension($r)->setRowHeight($withImages ? 40 : 18);
            $r++;
        }
        $end = $r - 1;

        foreach (['J', 'K'] as $c) $sh2->getStyle("{$c}2:{$c}{$end}")->getNumberFormat()->setFormatCode($usdFmt);
        $sh2->getStyle("I2:I{$end}")->getNumberFormat()->setFormatCode('#,##0.##');
        $sh2->setAutoFilter("A1:Q{$end}");
        foreach (['A' => 9, 'B' => 13, 'C' => 15, 'D' => 34, 'E' => 12, 'F' => 30, 'G' => 15,
                  'H' => 8, 'I' => 10, 'J' => 11, 'K' => 11, 'L' => 8, 'M' => 8,
                  'N' => 7, 'O' => 7, 'P' => 7, 'Q' => 10] as $c => $w) {
            $sh2->getColumnDimension($c)->setWidth($w);
        }

        $ss->setActiveSheetIndex(0);
        $name = 'products_' . now()->format('Ymd_His') . '.xlsx';
        return response()->streamDownload(function () use ($ss) {
            $writer = new XlsxWriter($ss);
            $writer->setIncludeCharts(true);
            $writer->save('php://output');
        }, $name, [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }


        private function excelThumb(string $srcPath, int $maxPx = 96): ?string
    {
        $dir = storage_path('app/excel-thumbs');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $thumb = $dir . '/' . md5($srcPath . filemtime($srcPath) . $maxPx) . '.jpg';
        if (is_file($thumb)) return $thumb;

        // No GD on this PHP? Skip the Excel image thumbnail rather than fatally
        // crashing the whole export with "call to undefined function".
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return null;
        }

        $info = @getimagesize($srcPath);
        if (!$info) return null;

        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($srcPath),
            IMAGETYPE_PNG  => @imagecreatefrompng($srcPath),
            IMAGETYPE_GIF  => @imagecreatefromgif($srcPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : null,
            default        => null,
        };
        if (!$src) return null;

        [$w, $h] = $info;
        $scale = min($maxPx / $w, $maxPx / $h, 1);
        $nw = max(1, (int) ($w * $scale));
        $nh = max(1, (int) ($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        // white background (kills PNG transparency → clean in Excel)
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        imagejpeg($dst, $thumb, 70);
        imagedestroy($src);
        imagedestroy($dst);

        return is_file($thumb) ? $thumb : null;
    }
}
