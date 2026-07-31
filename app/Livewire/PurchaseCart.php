<?php

namespace App\Livewire;

use App\Models\Currency;
use App\Models\ItemLedgerEntry;
use App\Models\Product;
use App\Models\PurchaseHeader;
use App\Models\PurchaseLine;
use App\Models\Serial_No;
use App\Models\UserWarehouse;
use App\Models\Vendor;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class PurchaseCart extends Component
{
    public $cart = [];
    public $cart_queue_no = 0;
    public $new_cart = true;


    public $qty = 0;
    public $count_cart = 0;
    public $currency = 'USD';
    public $currency_name = '$';
    public $factor = 1; // Conversion factor
    public $all_currency;

    public $vendor_name = 'General vendor';
    public $vendor_id = null;
    public $vendor_phone = '';
    public $vendor_address1 = '';
    public $vendor_address2 = '';
    public $vendor_contact_name = '';
    public $vendor_contact_phone = '';
    public $vendor_city = '';


    public $generatedLots = []; // Livewire property to track lots per product+warehouse
    public $remark = '';
    public $deposit_amount = 0;

    public $grn_date;

    public $openIndex = null;

    public function toggleItem($index)
    {
        $this->openIndex = $this->openIndex === $index ? null : $index;
    }


    public function mount_wh()
    {
        $warehouse_user = UserWarehouse::with('warehouse')
            ->where('user_id', Auth::id())
            ->get();

        $this->warehouses = $warehouse_user
            ->pluck('warehouse.name', 'warehouse.id')
            ->toArray();

        // no default selected
        $this->warehouse_id = '';
    }
    // Add product to cart
    #[\Livewire\Attributes\On('add-product')]
    public function addProduct($productJson)
    {
        $product = json_decode($productJson, true);

        $cost  = $product['cost'] ?? 0;
        $unit  = $product['unit'] ?? 'NA';
        $stock = $product['stock'] ?? 0;

        static $lastAddedId = null;
        static $lastClickTime = 0;
        $now = microtime(true) * 1000;

        if ($lastAddedId === $product['id'] && $now - $lastClickTime < 300) return;

        $lastAddedId = $product['id'];
        $lastClickTime = $now;

        // ❌ REMOVE merge logic completely

        // ✅ Always add new row
        $this->cart[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'qty' => 1,
            'cost_price' => $cost,
            'amount_line' => $cost,
            'order_no' => count($this->cart) + 1,
            'unit' => $unit,
            'stock' => $stock,
            'lot' => $this->generateLotNumber(), // unique lot
            'has_expire' => false, // default: no expiry (NA) — tick to set one
            'expire' => null,
            'bin_id' => null,  // user picks from the receiving warehouse's bins
            'remark' => '',    // user sets from front-end
        ];

        $this->count_cart = count($this->cart);
    }
    private function cleanNumber($value): float
    {
        $value = (string) ($value ?? 0);

        // remove currency symbol, spaces, commas
        $value = str_replace(['៛', '$', ',', ' '], '', $value);

        return is_numeric($value) ? (float) $value : 0;
    }

    public function recalcLine($index, $field, $inputValue = null)
    {
        $factor = (float) ($this->factor ?: 1);
        $precision = 6;

        $qty  = round($this->cleanNumber($this->cart[$index]['qty'] ?? 0), 2);   // ← qty 2 dp
        $cost = $this->cleanNumber($this->cart[$index]['cost_price'] ?? 0);

        if ($field === 'cost_price') {
            $displayCost = $this->cleanNumber($inputValue);

            // if display is KHR, store base/USD
            $cost = round($displayCost / $factor, $precision);

            $this->cart[$index]['cost_price']  = $cost;
            $this->cart[$index]['amount_line'] = round($qty * $cost, $precision);
        } elseif ($field === 'amount_line') {
            $displayAmount = $this->cleanNumber($inputValue);

            $amount = round($displayAmount / $factor, $precision);

            $this->cart[$index]['amount_line'] = $amount;
            $this->cart[$index]['cost_price']  = $qty > 0
                ? round($amount / $qty, $precision)
                : 0;
        } elseif ($field === 'qty') {
            $qty = round(max(0.01, $this->cleanNumber($inputValue ?? $qty)), 2);   // blocks 0.001

            $this->cart[$index]['qty']         = $qty;
            $this->cart[$index]['amount_line'] = round($qty * $cost, $precision);
        }

        $this->cart = array_values($this->cart);
    }

    public function toggleLineExpire($index)
    {
        $has = !($this->cart[$index]['has_expire'] ?? false);
        $this->cart[$index]['has_expire'] = $has;
        $this->cart[$index]['expire'] = $has ? now()->format('Y-m-d') : null;
    }

    public $warehouses = [];
    public $warehouse_id; // selected warehouse
    public $binsForWarehouse = [];

    public function updatedWarehouseId($value)
    {
        $this->binsForWarehouse = $value
            ? \App\Models\Bin::where('warehouse_id', $value)->orderBy('name')->get(['id', 'name'])->toArray()
            : [];
    }
    private function getRielCurrency()
    {
        // Never firstOrFail() — a missing Riel row would throw ModelNotFoundException
        // (renders as a 404). Fall back to an unsaved zero-rate row on a fresh /
        // misconfigured database so the purchasing screen still loads and posts.
        $riel = Currency::where('code', '៛')->first();
        if (! $riel) {
            $riel = new Currency();
            $riel->code = '៛';
            $riel->factor = 0;
        }
        return $riel;
    }
    public function post_grn()
    {
        // Cashier-side purchasing only — the chef posts kitchen material through
        // KitchenController::purchaseMaterials(), which converts to base units.
        if (!Auth::user()->hasPermission('purchasing.purchase')) {
            $this->dispatch('app-error', ['message' => 'You do not have permission to post a purchase.']);
            return;
        }

        if (empty($this->cart)) {
            $this->dispatch('app-error', ['message' => 'Cart is empty!']);
            return;
        }

        if (!$this->warehouse_id) {
            $this->dispatch('app-error', ['message' => 'Please select warehouse!']);
            return;
        }

        $precision = 6;

        DB::beginTransaction();

        try {
            $riel = $this->getRielCurrency();
            $documentNo = $this->generateGrnNo();
            // 🔥 location = the receiving warehouse of this GRN
            $grnWarehouse = Warehouse::find($this->warehouse_id);


            $header = PurchaseHeader::create([
                'no'             => $documentNo,
                'vendor_id'      => $this->vendor_id,
                'posting_date'   => $this->grn_date,
                'due_date'       => null,
                'payment_method' => null,
                'currency_name'  => $riel->code,
                'factor'         => $riel->factor,
                'deposit_amount' => round((float) $this->deposit_amount, 6),
                'location_id'     => (string) $this->warehouse_id,
                'location_name'        => $grnWarehouse->name ?? '',
                'remark'         => $this->remark,
                'created_by'     => Auth::user()->username ?? 'NA',
            ]);

            $productIds = collect($this->cart)->pluck('id');

            $products = Product::with('category')
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            $warehouse = Warehouse::find($this->warehouse_id);
            $vendor = $this->vendor_id ? Vendor::find($this->vendor_id) : null;

            foreach ($this->cart as $item) {
                $qty = round((float) ($item['qty'] ?? 0), $precision);

                if ($qty <= 0) {
                    continue;
                }

                $qty = max(0.01, $qty);

                $product = $products[$item['id']] ?? null;

                if (!$product) {
                    throw new \Exception("Product not found ID: " . $item['id']);
                }

                $unitCost = round((float) ($item['cost_price'] ?? 0), $precision);
                $lineAmount = round($qty * $unitCost, $precision);

                $purchaseLine = PurchaseLine::create([
                    'document_no'   => $documentNo,
                    'product_id'    => $product->id,
                    'barcode'       => $product->bar_code,
                    'item_code'     => $product->code,
                    'name'          => $product->name,
                    'variant'       => $item['variant'] ?? null,
                    'lot'           => $item['lot'] ?? null,
                    'expire_date'   => $item['expire'] ?? null,
                    'description'   => $product->description,
                    'quantity'      => $qty,
                    'unit'          => $product->unit ?? ($item['unit'] ?? null),
                    'category_name' => optional($product->category)->name,
                    'unit_cost'     => $unitCost,
                    'line_amount'   => $lineAmount,
                    'remark'        => $item['remark'] ?? null,
                    'created_by'    => Auth::user()->username ?? 'NA',
                ]);

                // find-or-create keyed by (product, warehouse, bin, lot) — avoids
                // splitting one lot across duplicate warehouse_product rows if
                // the same lot+bin is received more than once (matches
                // Adjustment's behavior).
                $lotValue = $item['lot'] ?? null;
                $binId = !empty($item['bin_id']) ? (int) $item['bin_id'] : null;
                $existingWp = DB::table('warehouse_product')
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $this->warehouse_id)
                    ->when($lotValue !== null && $lotValue !== '', fn($q) => $q->where('lot', $lotValue), fn($q) => $q->whereNull('lot'))
                    ->when($binId !== null, fn($q) => $q->where('bin_id', $binId), fn($q) => $q->whereNull('bin_id'))
                    ->lockForUpdate()
                    ->first();

                if ($existingWp) {
                    DB::table('warehouse_product')
                        ->where('id', $existingWp->id)
                        ->update([
                            'quantity'   => DB::raw('quantity + ' . $qty),
                            'cost'       => $unitCost,
                            'expire'     => $item['expire'] ?? $existingWp->expire,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('warehouse_product')->insert([
                        'product_id'    => $product->id,
                        'warehouse_id'  => $this->warehouse_id,
                        'bin_id'        => $binId,
                        'quantity'      => $qty,
                        'track_lot'     => !empty($item['lot']) ? 1 : 0,
                        'lot'           => $lotValue,
                        'expire'        => $item['expire'] ?? null,
                        'cost'          => $unitCost,
                        'control_exp'   => !empty($item['expire']) ? 1 : 0,
                        'created_by'    => Auth::user()->username ?? 'NA',
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }

                $ledger = ItemLedgerEntry::create([
                    'entry_no'            => 'ILE-' . now()->format('YmdHis') . '-' . $product->id,
                    'posting_date'        => now()->toDateString(),

                    'document_type'       => 'Purchase',
                    'document_no'         => $documentNo,

                    'source_id'           => $purchaseLine->id,
                    'source_table'        => 'Purchase Lines',

                    'product_id'          => $product->id,
                    'barcode'             => $product->bar_code,
                    'item_code'           => $product->code,
                    'name'                => $product->name,
                    'variant'             => $product->variant ?? '',
                    'description'         => $product->description,
                    'unit'                => $product->unit ?? ($item['unit'] ?? ''),
                    'category_name'       => optional($product->category)->name,
                    'type'                => $product->type ?? 'product',

                    'warehouse_id'        => $this->warehouse_id,
                    'warehouse_name'      => $warehouse->name ?? '',
                    'bin_id'              => $binId,
                    'bin_name'            => $binId ? optional(\App\Models\Bin::find($binId))->name : null,
                    'lot'                 => $item['lot'] ?? '',
                    'expire_date'         => $item['expire'] ?? null,

                    'quantity'            => $qty,
                    'remaining_quantity'  => $qty,
                    'entry_type'          => 'positive',

                           // dynamic Riel from currency table
                    'currency_name'  => $riel->code,
                    'factor'         => $riel->factor,

                    // Purchase carries COST only — the money lives in unit_cost +
                    // cost_amount (auto). Sale columns (sell/unit price, discount,
                    // VAT, line/net/grand amounts) stay at their 0 defaults so a
                    // purchase never looks like a sale in the ledger.
                    'unit_cost'           => $unitCost,

                    'vendor_id'           => $this->vendor_id,
                    'vendor_name'         => $vendor->name ?? '',
                    'customer_id'         => null,
                    'customer_name'       => '',
                    'customer_phone'      => '',
                    'customer_address'    => '',

                    'payment_method'      => '',
                    'remark'              => $item['remark'] ?? $this->remark,
                    'created_by'          => Auth::user()->username ?? 'system',
                ]);

                $ledger->update([
                    'entry_no' => $ledger->id,
                ]);
            }

            DB::commit();

            $binNames = collect($this->binsForWarehouse)->pluck('name', 'id');

            $receivedLines = collect($this->cart)
                ->filter(fn($item) => (float) ($item['qty'] ?? 0) > 0)
                ->map(function ($item) use ($products, $binNames) {
                    $product = $products[$item['id']] ?? null;
                    return [
                        'name'    => $product->name ?? $item['name'] ?? '',
                        'variant' => $item['variant'] ?? $product->variant ?? '',
                        'lot'     => $item['lot'] ?? '',
                        'expire'  => $item['expire'] ?? 'NA',
                        'bin'     => !empty($item['bin_id']) ? ($binNames[$item['bin_id']] ?? 'NA') : 'NA',
                    ];
                })
                ->values();

            $this->cart = [];
            $this->count_cart = 0;
            $this->warehouse_id = '';
            $this->deposit_amount = 0;
            $this->dispatch('success', [
                'message'      => 'GRN Posted Successfully!',
                'document_no'  => $documentNo,
                'purchase_id'  => $header->id,
                'lines'        => $receivedLines,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->dispatch('app-error', ['message' => 'Error posting GRN: ' . $e->getMessage()]);
        }
    }
    private function generateGrnNo()
    {
        // Shared GRN counter (same 'grn' serial the kitchen purchase uses) — no
        // scan of purchase_headers.
        return Serial_No::next('grn', 'GRN', 4);
    }

    public function generateLotNumber()
{
    $year = now()->format('y'); // e.g. 26
    $prefix = $year . 'FG';     // 26FG

    $key = $prefix;

    if (isset($this->generatedLots[$key])) {
        $next = $this->generatedLots[$key] + 1;
    } else {
        $lastLot = DB::table('warehouse_product')
            ->where('lot', 'like', $prefix . '%')
            ->orderByDesc('lot')
            ->value('lot');

        if ($lastLot) {
            // Remove "26FG" and convert "0001" to 1
            $number = (int) substr($lastLot, strlen($prefix));
            $next = $number + 1;
        } else {
            $next = 1;
        }
    }

    $this->generatedLots[$key] = $next;

    return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
}
    // Calculate total amount
    public function getTotalsProperty()
    {
        $total_amount = 0;
        foreach ($this->cart as $item) {
            $total_amount += $item['amount_line'] ?? 0;
        }

        return [
            'total_amount' => $total_amount,
        ];
    }

    public function removeItem($id)
    {
        foreach ($this->cart as $index => $item) {
            if ($item['id'] == $id) {
                unset($this->cart[$index]);
                break;
            }
        }

        // Reindex array (IMPORTANT for Livewire)
        $this->cart = array_values($this->cart);

        // Recalculate order_no
        foreach ($this->cart as $i => $item) {
            $this->cart[$i]['order_no'] = $i + 1;
        }

        $this->count_cart = count($this->cart);
    }
    protected $listeners = ['refreshCurrency'];
    public function refreshCurrency()
    {
        $currency = Currency::where('code', $this->currency)->first();

        if ($currency) {
            $this->currency = $currency->code;
            $this->factor   = $currency->factor;
            $this->currency_name = $currency->name;
        }
    }
    #[\Livewire\Attributes\On('clearCart')]
    public function clearCart()
    {

        $this->cart = [];
        $this->qty = 0;
        $this->count_cart = 0;
        $this->generatedLots = [];

        $this->vendor_name = '';
        $this->vendor_id = null;
        $this->dispatch('cart-cleared', [
            'message' => 'Cart has been cleared'
        ]);
    }
    public function mount()
    {
        $this->all_currency = Currency::all();
        $this->mount_wh();
        $default = $this->all_currency->where('is_default', 1)->first();

        if ($default) {
            $this->currency      = $default->code;
            $this->currency_name = $default->code;
            $this->factor        = $default->factor;
        }
    }
     public function setCurrency($code)
    {
        $currency = Currency::where('code', $code)->first();


        if ($currency) {
            $this->currency = $currency->code;
            $this->currency_name = $currency->code;
            $this->factor = $currency->factor;

            $this->dispatch('change-currency', ['factor' => $this->factor,  'currency_name' => $this->currency_name]);
        }else{
             $this->currency = 'USD';
                $this->currency_name = '$';
                $this->factor = 1; // Conversion factor
                   $this->dispatch('change-currency', ['factor' => $this->factor,  'currency_name' => $this->currency_name]);
        }

    }

    public function render()
    {
        return view('livewire.purchase-cart');
    }
}
