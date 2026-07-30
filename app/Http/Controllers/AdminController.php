<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\InvoiceLine;
use App\Models\PosProfile;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Metadata\Test;

class AdminController extends Controller
{

    public function index_by_page()
    {
        $warehouse_ids = Auth::user()->warehouses->pluck('id');


        // 1️⃣ Load products with only the selected warehouse. addOnLines rides along
        // so a dish's optional extras are available on the FIRST page load — without
        // this the Sale screen only learned about add-ons after the background
        // /pos/products refresh, so they appeared to show up only "sometimes".
        $sql = Product::with([
            'warehouses' => function ($q) use ($warehouse_ids) {
                $q->whereIn('warehouse_id', $warehouse_ids);
            },
            'addOnLines',
        ]);
        if (Auth::user()->role == 'admin' || Auth::user()->role == 'supervisor') {
        } else {
            $sql->whereIn('type', ['Product', 'Service']);
        }
        // Raw/packaging materials are chef stock, not sellable menu items — never list them on the Sale screen.
        $sql->whereNotIn('type', ['raw_material', 'packaging_material']);
        $sql->where('status', 1);
        // A cooking product with no recipe components has no recipe to produce/cost
        // it, so it can't really be sold — hide those empty dishes from the cashier.
        // Non-cooking products (resale goods) are unaffected.
        $sql->where(function ($q) {
            $q->where('type', '!=', 'cooking_product')
                ->orWhereHas('componentLines');
        });
        $products =  $sql->get();

        // 2️⃣ Sum stock per product (only from this warehouse)
        $products->each(function ($product) {
            $product->total_stock = $product->warehouses->sum(function ($wh) {
                return $wh->pivot->quantity ?? 0;
            });
            $product->setAttribute('addons', self::addonList($product));
        });

        // 3️⃣ Sort: in-stock first, then by name ascending
        $products = $products->sort(function ($a, $b) {
            if ($a->total_stock == 0 && $b->total_stock > 0) return 1;
            if ($a->total_stock > 0 && $b->total_stock == 0) return -1;
            return strcmp($a->name, $b->name);
        })->values();

        // 4️⃣ Group by category (limit 50 per category)
        $categories = [];
        foreach ($products as $product) {
            $category = $product->category_name ?? 'Uncategorized';
            if (!isset($categories[$category])) {
                $categories[$category] = [];
            }
            if (count($categories[$category]) < 50) {
                $categories[$category][] = $product;
            }
        }


        $byId = $products->keyBy('id');

        $topSellerRows = InvoiceLine::query()
            ->select('product_id')
            ->selectRaw('SUM(quantity) as sold_qty')
            ->whereNotNull('product_id')
            // ->where('created_at', '>=', now()->subDays(90))  // ← uncomment for a rolling 90-day window
            ->groupBy('product_id')
            ->orderByDesc('sold_qty')
            ->limit(60)                // buffer; some ids drop out below
            ->get();

        $top_products = [];
        foreach ($topSellerRows as $row) {
            $p = $byId->get($row->product_id);
            if (!$p) continue;         // product inactive / filtered out for this role
            $top_products[] = $p;
            if (count($top_products) >= 30) break;
        }


        // 5️⃣ Currency
        $currency = Currency::where('code', '<>', 'USD')->get();
        $currency_default = Currency::where('is_default', 1)->first();
        $factor = $currency_default ? $currency_default->factor : 1;
        $currency_name = $currency_default ? $currency_default->code : 'USD';


         $posInfoForPrint = PosProfile::where('user_report', auth()->id())->first();
        $posInfoForPrint = $posInfoForPrint ? $posInfoForPrint->toArray() : [];
        $posInfoForPrint['logo_url'] = \App\Http\Controllers\PosProfileController::logoUrl();

        // Product picker display mode is decided per-user by whichever of the
        // two mutually-exclusive permissions they hold (set in Manage
        // Permissions) — not a manual toggle. Admins and anyone not
        // explicitly granted "view_list" fall back to the existing grid.
        $productViewMode = (Auth::user()->role !== 'admin' && Auth::user()->permissions()->where('key', 'pos_sale.view_list')->exists())
            ? 'list'
            : 'grid';

        return view('backend.pos', compact('categories', 'currency', 'factor', 'currency_name', 'top_products', 'posInfoForPrint', 'productViewMode'));
    }



    // Optional extras a dish offers at the register (from its recipe's add_on lines):
    // display name + the charge added when the cashier selects it.
    public static function addonList(Product $product): array
    {
        if (!$product->relationLoaded('addOnLines')) {
            return [];
        }
        return $product->addOnLines->map(fn($l) => [
            'id' => $l->id,
            'name' => $l->addon_name,
            'extra_price' => (float) $l->extra_price,
        ])->filter(fn($a) => $a['name'])->values()->all();
    }

    public function getProducts()
    {
        $warehouse_ids = Auth::user()->warehouses->pluck('id');

        // 1️⃣ Load products with only the selected warehouse. addOnLines rides along
        // so the Sale screen can offer a dish's optional extras (Add Mushroom +$0.50).
        $sql = Product::with([
            'warehouses' => function ($q) use ($warehouse_ids) {
                $q->whereIn('warehouse_id', $warehouse_ids);
            },
            'addOnLines',
        ]);
        if (Auth::user()->role == 'admin') {
        } else {
            $sql->where('type', 'Product');
        }
        // Raw/packaging materials are chef stock, not sellable menu items — never list them on the Sale screen.
        $sql->whereNotIn('type', ['raw_material', 'packaging_material']);
        $sql->where('status', 1);
        // A cooking product with no recipe components has no recipe to produce/cost
        // it, so it can't really be sold — hide those empty dishes from the cashier.
        // Non-cooking products (resale goods) are unaffected.
        $sql->where(function ($q) {
            $q->where('type', '!=', 'cooking_product')
                ->orWhereHas('componentLines');
        });
        $products =  $sql->get();


        $products->each(function ($product) {
            $product->total_stock = $product->warehouses->sum(function ($wh) {
                return $wh->pivot->quantity ?? 0;
            });
            $product->setAttribute('addons', self::addonList($product));
        });

        $products = $products->sort(function ($a, $b) {
            if ($a->total_stock == 0 && $b->total_stock > 0) return 1;
            if ($a->total_stock > 0 && $b->total_stock == 0) return -1;
            return strcmp($a->name, $b->name);
        })->values();

        $categories = [];
        foreach ($products as $product) {
            $category = $product->category_name ?? 'Uncategorized';
            if (!isset($categories[$category])) {
                $categories[$category] = [];
            }
            if (count($categories[$category]) < 50) {
                $categories[$category][] = $product;
            }
        }

        // Included so the POS screen's background refresh can also pick up
        // an exchange-rate change (Manage Currency) without a page reload —
        // not just stock.
        $currencyDefault = Currency::where('is_default', 1)->first();

        return response()->json([
            'categories'    => $categories,
            'factor'        => $currencyDefault ? $currencyDefault->factor : 1,
            'currency_name' => $currencyDefault ? $currencyDefault->code : 'USD',
        ]);
    }


    // Async function to get currency by code
    public function getByCode(Request $request, $code)
    {
        $currency = Currency::where('code', $code)->first();

        if (!$currency) {
            return response()->json([
                'success' => false,
                'message' => 'Currency not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $currency
        ]);
    }

  public function updateAll(Request $request)
{
    try {
        if ($request->has('currency')) {
            foreach ($request->currency as $id => $data) {
                Currency::where('id', $id)->update([
                    'factor' => $data['factor'] ?? null,
                    'code'   => $data['code'] ?? null,
                    'name'   => $data['name'] ?? null,
                    // is_default NOT touched — stays as it is
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Currency saved successfully',
        ]);
    } catch (\Exception $e) {
        \Log::error('Currency update error: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}



    public function login()
    {
        return view('backend.login');
    }

    public function login_submit(Request $request)
    {
        $request->validate([
            'name_email' => 'required|string',
            'password'   => 'required|string',
        ]);

        $loginInput = $request->input('name_email');
        $password   = $request->input('password');
        $remember   = $request->has('remember');

        $ok = Auth::attempt(['name' => $loginInput, 'password' => $password], $remember)
            || Auth::attempt(['email' => $loginInput, 'password' => $password], $remember);

        if (!$ok) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials'], 401);
        }

        if (Auth::user()->status == 0) {
            Auth::logout();
            return response()->json(['success' => false, 'message' => 'Your user has been disabled from the system'], 403);
        }

        // Chef / Supervisor-Chef land straight in the Kitchen interface — everyone
        // else (cashier, supervisor, admin) keeps landing on the Sale screen as before.
        $redirect = in_array(Auth::user()->role, ['chef', 'chef_supervisor']) ? 'Kitchen' : 'Sale';

        return response()->json([
            'success'  => true,
            'message'  => 'Login success ✅',
            'redirect' => $redirect,
        ]);
    }
    public function logout()
    {
        $auth = Auth::logout();

        if ($auth) {
            return redirect("/login")->with('success', 'Logout Suceess.');
        } else {
            return redirect("/")->with('fail', 'Logout Suceess.');
        }
    }
}
