<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\PermissionController;
use App\Models\Currency;
use App\Models\Product;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchasingController;
use App\Http\Controllers\SaleInvoiceController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\PosProfileController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\BinController;
use App\Http\Controllers\ItemLedgerEntryController;
use App\Http\Controllers\SaleOrderController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\UnitOfMeasureController;


use App\Http\Controllers\GainCostController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

Route::get('/', [AdminController::class, 'login'])->name('login');
// Handle login post
Route::post('/login-submit', [AdminController::class, 'login_submit']);

// Cached product thumbnails. Unauthenticated on purpose: the source images are
// already publicly served from /assets/startic_img, so gating this would only
// add session overhead to every tile without adding any protection.
Route::get('/thumb', [\App\Http\Controllers\ImageController::class, 'thumb'])->name('image.thumb');


Route::middleware(['auth'])->group(function () {
    Route::get('/generate-token', function () {

        $user = Auth::user();

        return $user->createToken('excel')->plainTextToken;
    });


    Route::get('/Sale', [AdminController::class, 'index_by_page'])->middleware('permission:pos_sale.view');
    Route::get('/pos/products', [AdminController::class, 'getProducts'])->middleware('permission:pos_sale.view');
    // USER
    Route::get('/users-list-data', [UserController::class, 'userListData'])->name('users.list.data')->middleware('permission:user.view');
    Route::post('/users/store', [UserController::class, 'store_user'])->middleware('permission:user.create');
    Route::get('/users/{id}', [UserController::class, 'show'])->middleware('permission:user.view');
    Route::put('/users/{id}', [UserController::class, 'update'])->middleware('permission:user.edit');
    // Get Warehouse for User
    Route::get('/warehouse-list-data', [UserController::class, 'get_warehouse_list'])->middleware('permission:user.view');
    // Get Permissions for User
    Route::get('/permissions-list-data', [PermissionController::class, 'permissionListData'])->middleware('permission:user.view');
    Route::post('/purchase/products/search', [PurchasingController::class, 'search'])->middleware('permission:purchasing.view');

    Route::post('/products/category/search', [ProductController::class, 'searchByCategory'])->middleware('permission:product.view');

    // Shared read-only lookup used by both the POS screen and Purchasing screen — not gated to a single section
    Route::get('/categories', [CategoryController::class, 'getCategories']);

    // Manage Categories tool
    Route::get('/categories/manage', [CategoryController::class, 'index'])->middleware('permission:category.view');
    Route::post('/categories', [CategoryController::class, 'store'])->middleware('permission:category.create');
    Route::put('/categories/{id}', [CategoryController::class, 'update'])->middleware('permission:category.edit');
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->middleware('permission:category.delete');


    Route::get('/currency/{code}', [AdminController::class, 'getByCode'])->middleware('permission:exchange_rate.view');
    Route::post('/currency/update-all', [AdminController::class, 'updateAll'])
        ->name('currency.updateAll')->middleware('permission:exchange_rate.edit');

    Route::post('/customers/store', [CustomerController::class, 'store'])->name('customers.store')->middleware('permission:customer.create');


    Route::get('/customers/search', [CustomerController::class, 'search'])->name('customers.search')->middleware('permission:customer.view');
    Route::get('/customers/list', [CustomerController::class, 'list'])->middleware('permission:customer.view');
    // DELETE customer
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customer.delete');

    // UPDATE customer
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customer.edit');
    Route::get('/customers/list_search', [CustomerController::class, 'list_search'])->middleware('permission:customer.view');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customer.view');


    Route::get('/warehouses/list', [WarehouseController::class, 'list_warehouse'])->middleware('permission:warehouse.view');
    Route::post('/warehouses', [WarehouseController::class, 'store'])->middleware('permission:warehouse.create');
    Route::post('/warehouses/update/{id}', [WarehouseController::class, 'update'])->middleware('permission:warehouse.edit');
    Route::post('/warehouses/{id}/toggle-status', [WarehouseController::class, 'toggleStatus'])->middleware('permission:warehouse.edit');
    Route::delete('/warehouses/{id}', [WarehouseController::class, 'destroy'])->middleware('permission:warehouse.delete');
    // get stock
    Route::get('/warehouses/{id}/stock', [WarehouseController::class, 'getStock'])->middleware('permission:warehouse.view');
    Route::get('/product/categories', [WarehouseController::class, 'getCategories'])->middleware('permission:warehouse.view');

    // Bins
    Route::get('/bins', [BinController::class, 'index'])->middleware('permission:warehouse.view');
    Route::post('/bins', [BinController::class, 'store'])->middleware('permission:warehouse.create');
    Route::put('/bins/{id}', [BinController::class, 'update'])->middleware('permission:warehouse.edit');
    Route::delete('/bins/{id}', [BinController::class, 'destroy'])->middleware('permission:warehouse.delete');

    // Company / print profile
    Route::get('/pos-profile', [PosProfileController::class, 'show'])->middleware('permission:company_profile.view');
    Route::post('/pos-profile', [PosProfileController::class, 'update'])->middleware('permission:company_profile.edit');
    Route::post('/pos-profile/logo', [PosProfileController::class, 'uploadLogo'])->middleware('permission:company_profile.edit');


    // Get lot
    Route::get('/get-lot-data/{product_id}', [WarehouseController::class, 'getLotData'])->middleware('permission:warehouse.view');
    // transfer Lot — route-level middleware only confirms basic warehouse
    // access; transfer() / transferFefo() decide whether this specific
    // request is a cross-warehouse "transfer" or a same-warehouse
    // bin-to-bin "movement" from the payload and check the matching
    // permission themselves (a single endpoint serves both).
    Route::post('/transfer-lot', [WarehouseController::class, 'transfer'])->middleware('permission:warehouse.view');
    Route::post('/transfer-fefo', [WarehouseController::class, 'transferFefo'])->middleware('permission:warehouse.view');


    // Each also accepts the matching kitchen.* permission so the Chef/Supervisor-Chef
    // interface can create/manage cooking/raw/packaging products without being
    // granted the general (Sale-screen-facing) product.* permissions.
    Route::post('/products/store', [ProductController::class, 'store'])->name('products.store')->middleware('permission:product.create,kitchen.product');
    Route::get('/products/search', [ProductController::class, 'search'])->name('products.search')->middleware('permission:product.view,kitchen.product');
    Route::get('/products/list_search', [ProductController::class, 'list_search'])->middleware('permission:product.view,kitchen.product');
    Route::get('/kitchen/products', [ProductController::class, 'kitchenProducts'])->middleware('permission:kitchen.product,kitchen.recipe');
    Route::put('/product/{id}', [ProductController::class, 'update'])->name('product.update')->middleware('permission:product.edit,kitchen.product');

    Route::get('/products/raw-materials', [ProductController::class, 'rawMaterials'])->middleware('permission:product.view,kitchen.recipe,kitchen.product,kitchen.purchase');
    // Materials the pending orders still need — feeds the purchase modal's "Get Needed" button.
    Route::get('/kitchen/needed-materials', [ProductController::class, 'neededMaterials'])->middleware('permission:kitchen.purchase');
    Route::get('/kitchen/cooking-products', [ProductController::class, 'cookingProductList'])->middleware('permission:kitchen.product,kitchen.recipe');
    Route::get('/products/{id}/material-usage', [ProductController::class, 'materialUsage'])->middleware('permission:product.view,kitchen.product,kitchen.recipe');
    Route::get('/products/{id}/recipe', [ProductController::class, 'recipe'])->middleware('permission:product.view,kitchen.recipe');
    Route::post('/products/{id}/recipe', [ProductController::class, 'saveRecipe'])->middleware('permission:product.edit,kitchen.recipe');
    // Clone a dish into another size/variant (copies the recipe; price/cost differ).
    Route::post('/products/{id}/toggle-status', [ProductController::class, 'toggleStatus'])->middleware('permission:product.edit,kitchen.product,kitchen.recipe');
    Route::post('/products/{id}/rename-variant', [ProductController::class, 'renameVariant'])->middleware('permission:product.edit,kitchen.product,kitchen.recipe');
    Route::post('/products/{id}/duplicate-variant', [ProductController::class, 'duplicateVariant'])->middleware('permission:product.create,kitchen.product');
    Route::post('/products/variants/reorder', [ProductController::class, 'reorderVariants'])->middleware('permission:product.edit,kitchen.product');

    // Units of measure — catalog (Base Unit dropdowns) + per-product alternate
    // units with conversion factors (e.g. "1 kg = 1000 g" for a raw material).
    Route::get('/units-of-measure', [UnitOfMeasureController::class, 'index'])->middleware('permission:product.view,kitchen.product,kitchen.recipe');
    Route::post('/units-of-measure', [UnitOfMeasureController::class, 'store'])->middleware('permission:product.create,kitchen.product');
    Route::get('/products/{id}/unit-conversions', [ProductController::class, 'unitConversions'])->middleware('permission:product.view,kitchen.product,kitchen.recipe');
    Route::post('/products/{id}/unit-conversions', [ProductController::class, 'storeUnitConversion'])->middleware('permission:product.edit,kitchen.product');
    Route::delete('/unit-conversions/{conversionId}', [ProductController::class, 'destroyUnitConversion'])->middleware('permission:product.edit,kitchen.product');




    // Report




    Route::get('/sales-report', [SaleInvoiceController::class, 'salesReport'])->name('sales.report')->middleware('permission:report.sales');
    Route::get('/sales/categories', [SaleInvoiceController::class, 'getCategories'])->middleware('permission:report.sales');

    Route::get('/sales/customer-search', [SaleInvoiceController::class, 'searchCustomers'])->middleware('permission:report.sales');
    Route::get('/sales/product-search', [SaleInvoiceController::class, 'searchProducts'])->middleware('permission:report.sales');
    Route::get('/sales/payment-methods', [SaleInvoiceController::class, 'getPaymentMethods'])->middleware('permission:report.sales');








    Route::get('/forgot/password', [AdminController::class, 'forgot_password']);


    Route::get('/logout', [AdminController::class, 'logout']);



    // "Purchase" is usable standalone — a user granted only purchasing.purchase
    // (no purchasing.view) can still reach the page and use it, not just
    // someone who separately also has view.
    Route::get('/Purchasing', [PurchasingController::class, 'Purchasing'])->middleware('permission:purchasing.view,purchasing.purchase');
    Route::get('/fetch-purchase', [PurchasingController::class, 'fetchPurchase'])->middleware('permission:purchasing.view,purchasing.purchase');

    // Chef / Supervisor-Chef's dedicated interface
    Route::get('/Kitchen', [KitchenController::class, 'index'])->middleware('permission:kitchen.view');
    Route::get('/kitchen/orders', [KitchenController::class, 'pendingOrders'])->middleware('permission:kitchen.view');
    Route::get('/kitchen/orders/prepared-today', [KitchenController::class, 'preparedToday'])->middleware('permission:kitchen.view');
    Route::get('/kitchen/stats', [KitchenController::class, 'stats'])->middleware('permission:kitchen.view');
    Route::post('/kitchen/orders/{lineId}/prepare', [KitchenController::class, 'markPrepared'])->middleware('permission:kitchen.prepare');
    Route::get('/kitchen/menu-sold-today', [KitchenController::class, 'menuSoldToday'])->middleware('permission:kitchen.view');
    Route::get('/kitchen/kitchen-orders', [KitchenController::class, 'kitchenOrders'])->middleware('permission:kitchen.report');
    Route::get('/kitchen/kitchen-orders/summary', [KitchenController::class, 'kitchenOrderSummary'])->middleware('permission:kitchen.report');
    Route::get('/kitchen/kitchen-orders/export', [KitchenController::class, 'kitchenOrdersExport'])->middleware('permission:kitchen.report');
    Route::post('/kitchen/purchase', [KitchenController::class, 'purchaseMaterials'])->middleware('permission:kitchen.purchase');
    // Currency list for the chef purchase screen's USD / ៛ toggle.
    Route::get('/kitchen/currencies', function () {
        // USD is the base currency: factor is ALWAYS 1 and it is NOT stored in the
        // currencies table. Provide it from code so it's always selectable and the
        // default; every other currency (Riel) comes from the DB with its own
        // changeable rate.
        $usd = ['code' => 'USD', 'factor' => 1, 'is_default' => 1];
        $others = Currency::where('code', '!=', 'USD')
            ->orderBy('id')
            ->get(['code', 'factor'])
            ->map(fn($c) => ['code' => $c->code, 'factor' => (float) $c->factor, 'is_default' => 0])
            ->all();
        return response()->json(array_merge([$usd], $others));
    })->middleware('permission:kitchen.purchase');
    Route::post('/vendors', [VendorController::class, 'store'])->middleware('permission:vendor.create,kitchen.purchase');
    Route::get('/vendors/list', [VendorController::class, 'list'])->name('vendors.list')->middleware('permission:vendor.view,kitchen.purchase');
    Route::get('/vendors/{id}', [VendorController::class, 'show'])->name('vendors.show')->middleware('permission:vendor.view');
    Route::put('/vendors/{id}', [VendorController::class, 'update'])->name('vendors.update')->middleware('permission:vendor.edit');
    Route::post('/vendor-search', [VendorController::class, 'search'])
        ->name('vendor.search')->middleware('permission:vendor.view');




    Route::get('/item-ledger-entry', [ItemLedgerEntryController::class, 'index'])->middleware('permission:report.stock');
    Route::get('/item-ledger-entry/export', [ItemLedgerEntryController::class, 'export'])->middleware('permission:report.stock');

    Route::get('/expenses/latest', [ExpenseController::class, 'latest'])->middleware('permission:report.expense');

    Route::get('/get-sale-orders', [SaleOrderController::class, 'getSaleOrders'])->middleware('permission:pos_sale.view');

    Route::get('/sale-order-lines/{id}', [SaleOrderController::class, 'getSaleOrderLines'])->middleware('permission:pos_sale.view');
    // Kitchen order docket (ORDER-###) payload — category-split parts, by stage.
    Route::get('/order-docket', [SaleOrderController::class, 'orderDocketData'])->middleware('permission:pos_sale.view');
    Route::get('/picking-list-data/{id}', [SaleOrderController::class, 'pickingListData'])->middleware('permission:pos_sale.view');

    Route::post('/update-sale-order-status', [SaleOrderController::class, 'updateStatus'])
        ->name('sale-order.update-status')->middleware('permission:pos_sale.sell');

    // Quotations
    Route::get('/quotations', [QuotationController::class, 'index'])->middleware('permission:quotation.view');
    Route::get('/quotations/{id}', [QuotationController::class, 'show'])->middleware('permission:quotation.view');
    Route::post('/quotations/update-status', [QuotationController::class, 'updateStatus'])
        ->name('quotations.update-status')->middleware('permission:quotation.edit');
    // routes/web.php
    Route::post('/sale-order/update-delivery-status', [SaleOrderController::class, 'updateDeliveryStatus'])
        ->name('sale-order.update-delivery-status')->middleware('permission:pos_sale.sell');


 // ===== Customer Display (second screen) =====
    Route::prefix('pos')->name('pos.')->group(function () {

        // The customer-facing display page (second screen)
        Route::get('/customer-display', function () {
            return view('backend.customer_display', [
                'user_id' => Auth::id(),
            ]);
        })->name('customer-display');

        // POS pushes cart state here every 400ms
        Route::post('/display-sync', function (Request $request) {
            Cache::put(
                'pos_display_' . Auth::id(),
                $request->getContent(),
                now()->addMinutes(10)
            );
            return response()->noContent();
        })->name('display-sync');

        // Customer display polls this
        Route::get('/display-state/{userId}', function ($userId) {
            return response(
                Cache::get('pos_display_' . $userId, '{}'),
                200,
                ['Content-Type' => 'application/json']
            );
        })->name('display-state');
    });

    Route::prefix('reports/gain-cost')->middleware('permission:report.dashboard')->group(function () {
        Route::get('/',             [GainCostController::class, 'index']);        // the page
        Route::get('/summary',      [GainCostController::class, 'summary']);      // KPI cards + deltas
        Route::get('/trend',        [GainCostController::class, 'trend']);        // line chart series
        Route::get('/breakdown',    [GainCostController::class, 'breakdown']);    // donut + category bar + top products
        Route::get('/transactions', [GainCostController::class, 'transactions']); // table (sales|purchases|expenses)
        Route::get('/detail',       [GainCostController::class, 'detail']);       // modal content (?type=&id=)
        Route::get('/stock',        [GainCostController::class, 'stock']);        // current stock charts (qty + value)
        Route::get('/export',       [GainCostController::class, 'export']);       // CSV (?kind=summary|table&tab=)
        Route::get('/export-excel', [GainCostController::class, 'exportExcel']);  // styled .xlsx workbook (current filters)
        Route::get('/export-stock', [GainCostController::class, 'exportStock']);  // current stock list CSV
        Route::get('/sales-detail', [GainCostController::class, 'salesDetail']);  // line explorer (async, paginated; ?export=csv)
        Route::get('/inventory', [GainCostController::class, 'inventory']);
        Route::get('/services', [GainCostController::class, 'services']);
    });








    Route::get('/export-purchase', [PurchasingController::class, 'exportPurchase'])->name('purchase.export')->middleware('permission:purchasing.view');
    Route::get('/sale-report/export-excel', [SaleOrderController::class, 'exportSalesExcel'])->name('sale.export.excel')->middleware('permission:report.sales');
    Route::get('/products/export-excel', [ProductController::class, 'exportProducts'])->middleware('permission:product.view');


    Route::get('/fetch-purchase-doc', [PurchasingController::class, 'fetchPurchaseDoc'])->middleware('permission:purchasing.view');
    Route::get('/purchase-return/returnable', [PurchaseReturnController::class, 'returnable'])->middleware('permission:purchasing.view');
    Route::post('/purchase-return/confirm',   [PurchaseReturnController::class, 'confirm'])->middleware('permission:purchasing.purchase_return');
    Route::get('/fetch-purchase-lines', [PurchasingController::class, 'fetchPurchaseLines'])->middleware('permission:purchasing.view');

    // routes/web.php
Route::post('/pos/print-receipt', [App\Http\Controllers\ThermalPrintController::class, 'print'])->middleware('permission:pos_sale.view');
});
