<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css"
        integrity="sha512-DxV+EoADOkOygM4IR9yXP8Sb2qwgidEmeqAEmDKIOfPRQZOWbXCzLC6vjbZyy0vPisbH2SyW27+ddLVCN+OMzQ=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    {{-- Fonts come from app.css (--font-sans) — same stack as the Sale screen. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="shortcut icon" href="{{ asset('assets/icon/download.jpg') }}" type="image/x-icon">
    <title>Chef Kitchen</title>
</head>

<body class="bg-gray-100 h-screen overflow-hidden">

    <div class="h-screen overflow-hidden flex flex-col">

        {{-- ===================== TOP BAR — same amber chrome + tab pills as the Sale screen ===================== --}}
        <header class="shrink-0 sticky top-0 z-40 bg-amber-400 border-b border-default">
            <div class="px-4 flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-2.5 py-2 shrink-0">
                    <div class="h-9 w-9 rounded-xl bg-white/70 border border-black/5 shadow-sm flex items-center justify-center text-amber-700">
                        <i class="fa-solid fa-kitchen-set"></i>
                    </div>
                    <div class="leading-tight hidden sm:block">
                        <p class="text-sm font-bold text-amber-950">Kitchen</p>
                        <p class="text-2xs text-amber-900/70">Production &amp; Inventory</p>
                    </div>
                </div>

                <ul class="tab-track flex items-center gap-1.5 overflow-x-auto px-1 py-2" id="kitchen-tabs">
                    <li>
                        <button type="button" class="kitchen-tab-btn" data-tab="orders">
                            Orders
                            <span id="ordersTabCount" class="kitchen-tab-badge hidden ml-1.5">0</span>
                        </button>
                    </li>
                    @if (Auth::user()->hasPermission('kitchen.recipe'))
                        <li>
                            <button type="button" class="kitchen-tab-btn active" data-tab="recipe">
                                Menu &amp; Recipes
                            </button>
                        </li>
                    @endif
                    @if (Auth::user()->hasPermission('kitchen.product'))
                        <li>
                            <button type="button" class="kitchen-tab-btn" data-tab="products">
                                Material
                            </button>
                        </li>
                    @endif
                    {{-- Purchase is no longer a tab — it opens as a modal from the
                         Material list (per-row "Buy" + a "New Purchase" button). --}}
                    @if (Auth::user()->hasPermission('kitchen.report'))
                        <li>
                            <button type="button" class="kitchen-tab-btn" data-tab="kitchenorder">
                                Kitchen Order
                            </button>
                        </li>
                    @endif
                </ul>

                <div class="flex items-center gap-2 py-2 shrink-0">
                    <div class="hidden md:flex items-center gap-2 bg-white/50 border border-black/5 rounded-xl px-2.5 py-1.5">
                        <div class="h-6 w-6 rounded-full bg-amber-600 flex items-center justify-center text-2xs font-bold text-white uppercase">
                            {{ substr(Auth::user()->name, 0, 2) }}
                        </div>
                        <div class="leading-tight">
                            <p class="text-xs font-semibold text-amber-950">{{ Auth::user()->name }}</p>
                            <p class="text-2xs text-amber-900/70">{{ ucfirst(str_replace('_', ' ', Auth::user()->role)) }}</p>
                        </div>
                    </div>

                    {{-- Only shown to users who actually have Sale-screen access (e.g. admin) —
                         a pure Chef/Supervisor-Chef has no pos_sale.view and won't see this. --}}
                    @if (Auth::user()->hasPermission('pos_sale.view'))
                        <a href="/Sale" class="kitchen-chip-btn" title="Switch to Sale">
                            <i class="fa-solid fa-cash-register"></i>
                            <span class="hidden sm:inline">Sale</span>
                        </a>
                    @endif
                    <a href="/logout" class="kitchen-chip-btn" title="Logout">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    </a>
                </div>
            </div>
        </header>

        {{-- Slim context strip: current section + clock / sync --}}
        <div class="shrink-0 bg-white border-b border-gray-200 px-6 py-2 flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 id="kitchenPageTitle" class="text-sm font-semibold text-gray-800 leading-tight">Menu &amp; Recipes</h2>
                <p id="kitchenPageSubtitle" class="text-2xs text-gray-500 truncate">Define variants and the bill of materials behind every dish.</p>
            </div>
            <div class="hidden sm:flex items-center gap-4 text-xs text-gray-500 shrink-0">
                <span><i class="fa-regular fa-clock mr-1 text-amber-500"></i><span id="kitchenClock" class="tabular-nums text-gray-700">--:--:--</span> &middot; <span id="kitchenDate">&nbsp;</span></span>
                <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Synced <span id="kitchenLastSync" class="tabular-nums">just now</span></span>
            </div>
        </div>

    <main class="p-6 flex-1 min-h-0 overflow-hidden flex flex-col">

        {{-- ===================== ORDERS ===================== --}}
        <section id="kitchen-tab-orders" class="kitchen-tab-panel hidden">

            <div class="shrink-0 grid grid-cols-2 gap-3 mb-5">
                <div class="kitchen-stat-card">
                    <div>
                        <p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold mb-0.5">Pending</p>
                        <p id="statPending" class="kitchen-stat-value">0</p>
                    </div>
                </div>
                {{-- Click to load today's menu-sold summary on demand. --}}
                <button type="button" onclick="openMenuSoldToday()" class="kitchen-stat-card text-left w-full hover:border-amber-300 transition-colors cursor-pointer">
                    <div>
                        <p class="text-2xs uppercase tracking-wider text-amber-700 font-semibold mb-0.5"><i class="fa-solid fa-chart-simple mr-1"></i>Menu Sold Today</p>
                        <p class="text-sm font-semibold text-gray-600">Click to view summary</p>
                    </div>
                </button>
            </div>

            <div class="kitchen-scroll">
                <div class="space-y-6 min-w-0">
                    {{-- Pending column --}}
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>
                                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Pending</h3>
                            </div>
                            <button type="button" onclick="loadPendingOrders()" class="kitchen-btn-outline px-3! py-1.5! text-xs!">
                                <i class="fa-solid fa-arrows-rotate"></i> Refresh
                            </button>
                        </div>
                        <div id="pendingOrdersBody" class="grid grid-cols-2 lg:grid-cols-4 gap-3"></div>
                        <div id="pendingOrdersPagination" class="flex items-center gap-1.5 mt-4"></div>
                    </div>
                </div>

            </div>
        </section>

        {{-- Menu Sold Today summary — loaded on demand from the Orders stat button --}}
        <div id="menuSoldTodayModal" class="fixed inset-0 hidden items-center justify-center backdrop-blur-sm bg-black/60 p-4 z-60">
            <div class="relative w-full max-w-lg">
                <div class="kitchen-modal-card flex flex-col max-h-[85vh]">
                    <div class="kitchen-modal-header shrink-0">
                        <div class="flex items-center gap-3">
                            <span class="h-8 w-8 rounded-md bg-amber-100 border border-amber-200 flex items-center justify-center text-amber-700 text-xs"><i class="fa-solid fa-chart-simple"></i></span>
                            <div>
                                <h3 class="text-[15px] font-semibold text-gray-900 leading-tight">Menu Sold Today</h3>
                                <p id="mstDate" class="text-xs text-gray-500">&nbsp;</p>
                            </div>
                        </div>
                        <button type="button" onclick="closeMenuSoldToday()" class="kitchen-modal-close">✕</button>
                    </div>
                    <div class="p-5 overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="kitchen-thead">
                                <tr><th class="px-3 py-2 text-left">Dish</th><th class="px-3 py-2 text-right">Sold</th><th class="px-3 py-2 text-center">Prepared</th></tr>
                            </thead>
                            <tbody id="mstBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Order details modal — opened when a ticket is clicked --}}
        <div id="orderDetailsModal" class="fixed inset-0 hidden items-center justify-center backdrop-blur-sm bg-black/60 p-4 z-60">
            <div class="relative w-full max-w-md">
                <div class="kitchen-modal-card flex flex-col max-h-[85vh]">
                    <div class="kitchen-modal-header shrink-0">
                        <div class="flex items-center gap-3">
                            <span class="h-8 w-8 rounded-md bg-amber-100 border border-amber-200 flex items-center justify-center text-amber-700 text-xs"><i class="fa-regular fa-rectangle-list"></i></span>
                            <h3 class="text-[15px] font-semibold text-gray-900 leading-tight">Order Details</h3>
                        </div>
                        <button type="button" onclick="closeOrderDetails()" class="kitchen-modal-close">✕</button>
                    </div>
                    <div class="p-6 overflow-y-auto">
                        <div class="space-y-3 text-sm">
                            <div>
                                <p class="text-2xs uppercase tracking-wide text-gray-400 font-semibold">Dish</p>
                                <p id="odName" class="font-bold text-gray-800 text-base"></p>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <p class="text-2xs uppercase tracking-wide text-gray-400 font-semibold">Quantity</p>
                                    <p id="odQty" class="font-semibold text-gray-700"></p>
                                </div>
                                <div>
                                    <p class="text-2xs uppercase tracking-wide text-gray-400 font-semibold">Invoice</p>
                                    <p id="odInvoice" class="font-semibold text-gray-700"></p>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <p class="text-2xs uppercase tracking-wide text-gray-400 font-semibold">Sold At</p>
                                    <p id="odSoldAt" class="font-semibold text-gray-700"></p>
                                </div>
                                <div>
                                    <p class="text-2xs uppercase tracking-wide text-gray-400 font-semibold">Waiting</p>
                                    <p id="odElapsed" class="font-semibold text-amber-600"></p>
                                </div>
                            </div>
                            <div id="odPreparedRow" class="hidden">
                                <p class="text-2xs uppercase tracking-wide text-gray-400 font-semibold">Consumed By</p>
                                <p id="odPreparedBy" class="font-semibold text-emerald-600"></p>
                            </div>
                            {{-- Components required for this order — green = enough on hand,
                                 red = short. Quantities are per this line (recipe × qty). --}}
                            <div id="odIngredients" class="mt-3 pt-3 border-t border-gray-100">
                                <p class="text-2xs uppercase tracking-wide text-gray-400 font-semibold mb-2">Ingredients (needed for this order)</p>
                                <div id="odIngredientsBody" class="space-y-2"></div>
                            </div>
                        </div>
                    </div>
                    <div class="kitchen-modal-footer shrink-0">
                        <button type="button" onclick="closeOrderDetails()" class="kitchen-btn-outline">Close</button>
                        <button type="button" id="odMarkPreparedBtn" onclick="markPreparedFromDetails()"
                            class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-md bg-amber-400 hover:bg-amber-500 text-amber-950 text-xs font-bold transition">
                            <i class="fa-solid fa-check"></i> Consume
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== PRODUCTS ===================== --}}
        <section id="kitchen-tab-products" class="kitchen-tab-panel hidden">
            {{-- Filters + actions live on one header row (no separate filter card). --}}
            <div class="shrink-0 flex flex-wrap items-center gap-2.5 mb-4">
                <div class="relative flex-1 min-w-50">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                    <input type="text" id="kpFilterSearch" placeholder="Search by name, code, variant..."
                        class="kitchen-input w-full" style="padding-left:2.2rem;">
                </div>
                <select id="kpFilterType" class="kitchen-input">
                    <option value="">All Types</option>
                    <option value="raw_material">Raw Material</option>
                    <option value="packaging_material">Packaging Material</option>
                </select>
                <select id="kpFilterStock" class="kitchen-input">
                    <option value="">Any Stock</option>
                    <option value="in">In Stock</option>
                    <option value="low">Low Stock</option>
                    <option value="out">Out of Stock</option>
                </select>
                {{-- Show only materials consumed by a chosen dish's recipe (type-to-search). --}}
                <input type="text" id="kpFilterUsedIn" list="kpUsedInList" autocomplete="off"
                    placeholder="Used in — any dish" class="kitchen-input min-w-50">
                <datalist id="kpUsedInList"></datalist>
                <input type="hidden" id="kpFilterUsedInId">

                <div class="flex items-center gap-2 shrink-0 ml-auto">
                    @if (Auth::user()->hasPermission('kitchen.purchase'))
                        <button type="button" onclick="openPurchaseModal()" class="kitchen-btn-dark">
                            <i class="fa-solid fa-cart-plus"></i> <span class="hidden sm:inline">New Purchase</span>
                        </button>
                    @endif
                    @if (Auth::user()->hasPermission('kitchen.product'))
                        {{-- One button — Raw vs Packaging is chosen inside the modal. --}}
                        <button type="button" onclick="openKitchenProductModal(null, 'raw_material')" class="kitchen-btn-outline">
                            <i class="fa-solid fa-plus"></i> <span class="hidden sm:inline">Add Product</span>
                        </button>
                    @endif
                </div>
            </div>

            <div class="kitchen-card kitchen-scroll">
                <table class="w-full text-sm">
                    <thead class="kitchen-thead" style="position: sticky; top: 0; z-index: 1;">
                        <tr>
                            <th class="px-4 py-2.5 text-left">Name</th>
                            <th class="px-4 py-2.5 text-left">Type</th>
                            <th class="px-4 py-2.5 text-left">Stock</th>
                            <th class="px-4 py-2.5 text-right">Needed</th>
                            <th class="px-4 py-2.5 text-left kitchen-col-optional">Where Used</th>
                            <th class="px-4 py-2.5 text-right">Cost / Unit</th>
                            <th class="px-4 py-2.5 text-center kitchen-col-optional">Status</th>
                            <th class="px-4 py-2.5 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="kitchenProductsBody"></tbody>
                </table>
            </div>
            <div id="kitchenProductsPagination" class="shrink-0 flex items-center gap-1.5 mt-4"></div>
        </section>

        {{-- ===================== RECIPE & ATTRIBUTES ===================== --}}
        <section id="kitchen-tab-recipe" class="kitchen-tab-panel">
            <div class="kitchen-scroll">
                <div class="flex items-center justify-between mb-3 gap-3 flex-wrap">
                    <div class="flex items-center gap-3 flex-wrap">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Menu Items</h3>
                        <p class="text-xs text-gray-400">Click a dish to manage its components &amp; add-ons</p>
                    </div>
                    @if (Auth::user()->hasPermission('kitchen.product') || Auth::user()->hasPermission('kitchen.recipe'))
                        <button type="button" onclick="openKitchenProductModal(null, 'cooking_product')" class="kitchen-btn-dark shrink-0">
                            <i class="fa-solid fa-plus"></i> <span class="hidden sm:inline">Add Menu</span>
                        </button>
                    @endif
                </div>
                <div class="shrink-0 kitchen-card p-3 mb-4 flex flex-wrap items-center gap-2.5">
                    <div class="relative flex-1 min-w-50">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                        {{-- pl via inline style: .kitchen-input's `padding` shorthand overrides Tailwind's pl-9 --}}
                        <input type="text" id="menuFilterSearch" placeholder="Search menu items..." class="kitchen-input w-full" style="padding-left:2.2rem;">
                    </div>
                    <select id="menuFilterCategory" class="kitchen-input">
                        <option value="">All Categories</option>
                    </select>
                    <select id="menuFilterRecipe" class="kitchen-input">
                        <option value="">Any Recipe Status</option>
                        <option value="set">Recipe Set</option>
                        <option value="none">No Recipe</option>
                    </select>
                </div>
                <div class="kitchen-card p-4 grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-3 content-start" id="cookingProductPicker">
                    <p class="text-sm text-gray-400 p-3 col-span-full">Loading...</p>
                </div>
                <div id="cookingProductPagination" class="flex items-center gap-1.5 mt-4"></div>
            </div>
        </section>

        {{-- ===================== PURCHASE MODAL (chef-side: raw + packaging only) =====================
             Opened from the Material list (per-row "Buy" or the "New Purchase" button) instead of a tab. --}}
        <div id="purchaseModal" class="fixed inset-0 hidden items-start justify-center backdrop-blur-sm bg-black/60 overflow-y-auto p-4 z-50">
            <div class="relative w-full max-w-5xl my-6">
                <div class="kitchen-modal-card flex flex-col max-h-[90vh]">
                    <div class="kitchen-modal-header shrink-0">
                        <div class="flex items-center gap-3">
                            <span class="h-8 w-8 rounded-md bg-amber-100 border border-amber-200 flex items-center justify-center text-amber-700 text-xs"><i class="fa-solid fa-cart-plus"></i></span>
                            <div>
                                <h3 class="text-[15px] font-semibold text-gray-900 leading-tight">Purchase Material</h3>
                                <p class="text-xs text-gray-500">Buy in any unit the supplier sells — received in the material's base unit.</p>
                            </div>
                        </div>
                        <button type="button" onclick="closePurchaseModal()" class="kitchen-modal-close">✕</button>
                    </div>

                    <div class="p-5 overflow-y-auto flex-1 min-h-0">
                        <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                {{-- GRN date the chef is receiving on (defaults to today). --}}
                                <input type="date" id="purchaseDate" class="kitchen-input" title="GRN / posting date">
                                {{-- GRN number is always auto-generated (GRN26-####) — not manual. --}}
                                {{-- Warehouse to receive into (asked when the chef has more than one). --}}
                                <select id="purchaseWarehouse" class="kitchen-input">
                                    @foreach (Auth::user()->warehouses as $wh)
                                        <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                                    @endforeach
                                </select>
                                <div class="flex items-center gap-1">
                                    <select id="purchaseVendor" class="kitchen-input">
                                        <option value="">— No vendor —</option>
                                    </select>
                                    <button type="button" onclick="openVendorModal()" class="kitchen-btn-outline shrink-0" title="Manage vendors">
                                        <i class="fa-solid fa-user-tie"></i> Vendor
                                    </button>
                                </div>
                                {{-- Prices typed in the chef's currency; server converts to base (USD). --}}
                                <select id="purchaseCurrency" class="kitchen-input" onchange="onPurchaseCurrencyChange()"></select>
                            </div>
                            <div class="flex items-center gap-2">
                                {{-- Pulls every material the pending orders still need into the lines,
                                     each pre-filled at its needed quantity (asks yes/no first). --}}
                                <button type="button" onclick="purchaseGetNeeded()" class="kitchen-btn-outline" title="Add all materials the pending orders still need">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> Get Needed
                                </button>
                                <button type="button" onclick="addPurchaseRow()" class="kitchen-btn-outline">
                                    <i class="fa-solid fa-plus"></i> Add Line
                                </button>
                            </div>
                        </div>

                        <div class="kitchen-card overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="kitchen-thead">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left">Material</th>
                                        <th class="px-4 py-2.5 text-left w-24">On Hand</th>
                                        <th class="px-4 py-2.5 text-left w-24">Qty</th>
                                        <th class="px-4 py-2.5 text-left w-24">Unit</th>
                                        <th class="px-4 py-2.5 text-right w-28">Cost / Unit <span id="purCostCurrency" class="text-amber-700"></span></th>
                                        <th class="px-4 py-2.5 text-right w-32">≈ Receives</th>
                                        <th class="px-4 py-2.5 text-right w-32">Total <span id="purTotalCurrency" class="text-amber-700"></span></th>
                                        <th class="px-4 py-2.5 w-8"></th>
                                    </tr>
                                </thead>
                                <tbody id="purchaseLinesBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="kitchen-modal-footer shrink-0 justify-between!">
                        <input type="text" id="purchaseRemark" placeholder="Remark (optional)" class="kitchen-input flex-1 min-w-50">
                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold">Grand Total</p>
                                <p id="purchaseGrandTotal" class="text-lg font-bold text-gray-900 tabular-nums">0.00</p>
                                <p id="purchaseGrandTotalAlt" class="text-2xs text-gray-500 tabular-nums">&nbsp;</p>
                            </div>
                            <button type="button" onclick="closePurchaseModal()" class="kitchen-btn-outline">Cancel</button>
                            <button type="button" onclick="postKitchenPurchase()" class="kitchen-btn-dark">
                                <i class="fa-solid fa-check"></i> Post Purchase
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== KITCHEN ORDER (consumption + output, exportable) ===================== --}}
        <section id="kitchen-tab-kitchenorder" class="kitchen-tab-panel hidden">
            <div class="shrink-0 flex items-center justify-between mb-4 flex-wrap gap-2">
                <p class="text-xs text-gray-400">Every prepared dish (output) and the materials it consumed.</p>
                <div class="flex items-center gap-2 flex-wrap">
                    <input type="date" id="koFrom" class="kitchen-input">
                    <span class="text-gray-400 text-xs">to</span>
                    <input type="date" id="koTo" class="kitchen-input">
                    <button type="button" onclick="loadKitchenOrders(1)" class="kitchen-btn-dark">Apply</button>
                    <button type="button" onclick="openKitchenSummary()" class="kitchen-btn-outline">
                        <i class="fa-solid fa-chart-pie"></i> Summary
                    </button>
                    <button type="button" onclick="exportKitchenOrders()" class="kitchen-btn-outline">
                        <i class="fa-solid fa-file-csv"></i> Export
                    </button>
                </div>
            </div>

            <div class="kitchen-scroll">
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                    <div class="kitchen-stat-card"><div><p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold mb-0.5">Orders</p><p id="koTotOrders" class="kitchen-stat-value">0</p></div></div>
                    <div class="kitchen-stat-card"><div><p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold mb-0.5">Dishes Produced</p><p id="koTotQty" class="kitchen-stat-value">0</p></div></div>
                    <div class="kitchen-stat-card"><div><p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold mb-0.5">Material Cost</p><p id="koTotMaterial" class="kitchen-stat-value">0.00</p></div></div>
                    <div class="kitchen-stat-card"><div><p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold mb-0.5">FG Cost</p><p id="koTotFg" class="kitchen-stat-value">0.00</p></div></div>
                </div>

                <div class="kitchen-card overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="kitchen-thead">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Date</th>
                                <th class="px-4 py-2.5 text-left">Invoice</th>
                                <th class="px-4 py-2.5 text-left">Dish</th>
                                <th class="px-4 py-2.5 text-right">Qty</th>
                                <th class="px-4 py-2.5 text-right">Material Cost</th>
                                <th class="px-4 py-2.5 text-right">Routing Cost</th>
                                <th class="px-4 py-2.5 text-right">FG Cost</th>
                                <th class="px-4 py-2.5 text-center">Consumed</th>
                            </tr>
                        </thead>
                        <tbody id="kitchenOrdersBody"></tbody>
                    </table>
                </div>
                <div id="kitchenOrdersPagination" class="flex items-center gap-1.5 mt-4"></div>
            </div>
        </section>

        {{-- Kitchen order PERIOD SUMMARY: FG produced + all material consumed --}}
        <div id="kitchenSummaryModal" class="fixed inset-0 hidden items-start justify-center backdrop-blur-sm bg-black/60 overflow-y-auto p-4 z-60">
            <div class="relative w-full max-w-3xl my-6">
                <div class="kitchen-modal-card flex flex-col max-h-[90vh]">
                    <div class="kitchen-modal-header shrink-0">
                        <div class="flex items-center gap-3">
                            <span class="h-9 w-9 rounded-xl bg-amber-100 border border-amber-200 flex items-center justify-center text-amber-700"><i class="fa-solid fa-chart-pie"></i></span>
                            <div>
                                <h3 class="text-[15px] font-semibold text-gray-900 leading-tight">Kitchen Summary</h3>
                                <p id="ksRange" class="text-xs text-gray-500">&nbsp;</p>
                            </div>
                        </div>
                        <button type="button" onclick="closeKitchenSummary()" class="kitchen-modal-close">✕</button>
                    </div>
                    <div class="p-5 overflow-y-auto space-y-5">
                        {{-- Stat tiles --}}
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <div class="rounded-xl border border-gray-200 bg-white p-3 flex items-center gap-3">
                                <span class="h-9 w-9 rounded-lg bg-orange-50 text-orange-600 flex items-center justify-center"><i class="fa-solid fa-utensils"></i></span>
                                <div><p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold">Dishes</p><p id="ksDishes" class="text-lg font-bold text-gray-900 tabular-nums">0</p></div>
                            </div>
                            <div class="rounded-xl border border-gray-200 bg-white p-3 flex items-center gap-3">
                                <span class="h-9 w-9 rounded-lg bg-teal-50 text-teal-600 flex items-center justify-center"><i class="fa-solid fa-boxes-stacked"></i></span>
                                <div><p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold">Materials</p><p id="ksMaterials" class="text-lg font-bold text-gray-900 tabular-nums">0</p></div>
                            </div>
                            <div class="rounded-xl border border-gray-200 bg-white p-3 flex items-center gap-3">
                                <span class="h-9 w-9 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center"><i class="fa-solid fa-arrow-trend-down"></i></span>
                                <div><p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold">Material Cost</p><p id="ksMaterialCost" class="text-lg font-bold text-gray-900 tabular-nums">0.00</p></div>
                            </div>
                            <div class="rounded-xl border border-gray-200 bg-white p-3 flex items-center gap-3">
                                <span class="h-9 w-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center"><i class="fa-solid fa-sack-dollar"></i></span>
                                <div><p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold">FG Cost</p><p id="ksFgCost" class="text-lg font-bold text-gray-900 tabular-nums">0.00</p></div>
                            </div>
                        </div>

                        {{-- Finished goods produced --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 flex items-center gap-1.5"><i class="fa-solid fa-fire text-amber-500"></i> Finished Goods Produced</h4>
                            <div class="kitchen-card overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="kitchen-thead"><tr>
                                        <th class="px-4 py-2 text-left">Dish</th>
                                        <th class="px-4 py-2 text-right">Qty</th>
                                        <th class="px-4 py-2 text-right">Material</th>
                                        <th class="px-4 py-2 text-right">Routing</th>
                                        <th class="px-4 py-2 text-right">FG Cost</th>
                                    </tr></thead>
                                    <tbody id="ksFgBody"></tbody>
                                </table>
                            </div>
                        </div>

                        {{-- All material consumed --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 flex items-center gap-1.5"><i class="fa-solid fa-carrot text-teal-500"></i> Material Consumed (period)</h4>
                            <div class="kitchen-card overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="kitchen-thead"><tr>
                                        <th class="px-4 py-2 text-left">Material</th>
                                        <th class="px-4 py-2 text-right">Qty Used</th>
                                        <th class="px-4 py-2 text-right">Cost</th>
                                    </tr></thead>
                                    <tbody id="ksMatBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Kitchen order detail (consumption lines) --}}
        <div id="kitchenOrderDetailModal" class="fixed inset-0 hidden items-center justify-center backdrop-blur-sm bg-black/60 p-4 z-60">
            <div class="relative w-full max-w-lg">
                <div class="kitchen-modal-card flex flex-col max-h-[85vh]">
                    <div class="kitchen-modal-header shrink-0">
                        <div class="flex items-center gap-3">
                            <span class="h-8 w-8 rounded-md bg-amber-100 border border-amber-200 flex items-center justify-center text-amber-700 text-xs"><i class="fa-solid fa-utensils"></i></span>
                            <div>
                                <h3 id="koDetailTitle" class="text-[15px] font-semibold text-gray-900 leading-tight">Consumption</h3>
                                <p id="koDetailSub" class="text-xs text-gray-500">&nbsp;</p>
                            </div>
                        </div>
                        <button type="button" onclick="closeKitchenOrderDetail()" class="kitchen-modal-close">✕</button>
                    </div>
                    <div class="p-5 overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="kitchen-thead">
                                <tr><th class="px-3 py-2 text-left">Type</th><th class="px-3 py-2 text-left">Material</th><th class="px-3 py-2 text-right">Qty</th><th class="px-3 py-2 text-right">Cost</th></tr>
                            </thead>
                            <tbody id="koDetailBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </main>
    </div>

    {{-- ===================== VENDOR MODAL (shared vendor list, used from Purchase) ===================== --}}
    <div id="kitchenVendorModal" class="fixed inset-0 hidden items-start md:items-center justify-center backdrop-blur-sm bg-black/60 overflow-y-auto p-4 z-50">
        <div class="relative w-full max-w-2xl my-8">
            <div class="kitchen-modal-card flex flex-col max-h-[88vh]">
                <div class="kitchen-modal-header shrink-0">
                    <div class="flex items-center gap-3">
                        <span class="h-8 w-8 rounded-md bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-600 text-xs"><i class="fa-solid fa-user-tie"></i></span>
                        <div>
                            <h3 class="text-[15px] font-semibold text-gray-900 leading-tight">Vendors</h3>
                            <p class="text-xs text-gray-500">The same vendors the cashier / purchasing screen uses.</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeVendorModal()" class="kitchen-modal-close">✕</button>
                </div>
                <div class="p-5 space-y-4 overflow-y-auto">
                    <form id="kitchenVendorForm" class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="kitchen-label">Vendor name *</label>
                            <input type="text" id="kv-name" required placeholder="e.g. Angkor Dairy" class="kitchen-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="kitchen-label">Contact person</label>
                            <input type="text" id="kv-contact" placeholder="Who you deal with" class="kitchen-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="kitchen-label">Email</label>
                            <input type="email" id="kv-email" placeholder="name@company.com" class="kitchen-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="kitchen-label">Phone</label>
                            <input type="text" id="kv-phone" placeholder="0xx…" class="kitchen-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="kitchen-label">Phone 2</label>
                            <input type="text" id="kv-phone2" placeholder="Alternate" class="kitchen-input mt-1 w-full">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="kitchen-label">Address</label>
                            <input type="text" id="kv-address1" placeholder="Street / building" class="kitchen-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="kitchen-label">City</label>
                            <input type="text" id="kv-city" class="kitchen-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="kitchen-label">Country</label>
                            <input type="text" id="kv-country" class="kitchen-input mt-1 w-full">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="kitchen-label">Website</label>
                            <input type="text" id="kv-website" placeholder="https://…" class="kitchen-input mt-1 w-full">
                        </div>
                        <div class="sm:col-span-2 flex justify-end">
                            <button type="submit" class="kitchen-btn-dark shrink-0"><i class="fa-solid fa-plus"></i> Add Vendor</button>
                        </div>
                    </form>
                    <div class="kitchen-card overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="kitchen-thead">
                                <tr><th class="px-4 py-2 text-left">Code</th><th class="px-4 py-2 text-left">Name</th><th class="px-4 py-2 text-left">Contact</th><th class="px-4 py-2 text-left">Phone</th></tr>
                            </thead>
                            <tbody id="kitchenVendorList"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== PRODUCT MODAL ===================== --}}
    <div id="kitchenProductModal" class="fixed inset-0 hidden items-start md:items-center justify-center backdrop-blur-sm bg-black/60 overflow-y-auto p-4 z-50">
        <div class="relative w-full max-w-2xl my-8">
            <form id="kitchenProductForm" class="kitchen-modal-card">
                <input type="hidden" id="kp-id" name="id">
                <div class="kitchen-modal-header">
                    <div class="flex items-center gap-3">
                        <span class="h-8 w-8 rounded-md bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-600 text-xs"><i class="fa-solid fa-box"></i></span>
                        <h3 id="kitchenProductModalTitle" class="text-[15px] font-semibold text-gray-900">Add Product</h3>
                    </div>
                    <button type="button" onclick="closeKitchenProductModal()" class="kitchen-modal-close">✕</button>
                </div>
                <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="kitchen-label">Code *</label>
                            <input type="text" id="kp-code" required class="kitchen-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="kitchen-label">Name *</label>
                            <input type="text" id="kp-name" required class="kitchen-input mt-1 w-full">
                        </div>
                        {{-- FG-only: a material has no sellable variant --}}
                        <div id="kpVariantField">
                            <label class="kitchen-label">Variant</label>
                            <input type="text" id="kp-variant" placeholder="e.g. Small, Large" class="kitchen-input mt-1 w-full">
                        </div>
                        {{-- Materials get a plain Raw/Packaging switch instead of the full
                             product-type dropdown; the type is implied by which button
                             opened this modal. --}}
                        <div id="kpTypeField">
                            <label class="kitchen-label" id="kp-type-label">Type *</label>
                            <select id="kp-type" required class="kitchen-input mt-1 w-full" onchange="updateKitchenUnitFieldsForType()">
                                <option value="cooking_product">Cooking Product (Pizza / Menu Item)</option>
                                <option value="raw_material">Raw Material</option>
                                <option value="packaging_material">Packaging Material</option>
                            </select>
                        </div>
                        <div>
                            {{-- Only categories belonging to the selected type's kind
                                 (fg / rm / pm) are listed — see loadKitchenCategories(). --}}
                            <div class="flex items-center justify-between">
                                <label class="kitchen-label">Category</label>
                                <button type="button" onclick="openCategoryManager()" class="text-2xs text-sky-600 hover:text-sky-800 font-semibold">
                                    <i class="fa-solid fa-gear"></i> Manage
                                </button>
                            </div>
                            <select id="kp-category" class="kitchen-input mt-1 w-full">
                                <option value="">— None —</option>
                            </select>
                        </div>
                        <div id="kpUnitPlainField">
                            <label class="kitchen-label">Unit</label>
                            <input type="text" id="kp-unit" placeholder="e.g. Whole, Plate, Glass" class="kitchen-input mt-1 w-full">
                        </div>
                        <div id="kpUnitStructuredField" class="hidden">
                            <label class="kitchen-label">Base Unit</label>
                            <select id="kp-unit-select" class="kitchen-input mt-1 w-full">
                                <option value="__custom">Custom unit...</option>
                            </select>
                            <input type="text" id="kp-unit-custom" placeholder="Type a custom unit, e.g. Sachet"
                                class="kitchen-input mt-1.5 w-full hidden">
                            {{-- Locked once the material exists: stock, recipes and the ledger
                                 are all recorded in this unit. --}}
                            <p id="kpUnitLockedHint" class="hidden mt-1 text-2xs text-gray-400">
                                <i class="fa-solid fa-lock"></i> Base unit can't change after creation — disable this material and create a new one instead.
                            </p>
                        </div>
                        {{-- FG-only: materials are bought, not sold --}}
                        <div id="kpSellPriceField">
                            <label class="kitchen-label">Sell Price</label>
                            <input type="number" step="0.01" id="kp-sell-price" class="kitchen-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="kitchen-label" id="kp-cost-label">Cost</label>
                            <input type="number" step="0.000001" id="kp-cost" class="kitchen-input mt-1 w-full">
                        </div>
                        {{-- Material-only: drives the Low Stock badge on the Material tab --}}
                        <div id="kpMinStockField" class="hidden">
                            <label class="kitchen-label">Min Stock (low-stock alert)</label>
                            <input type="number" step="0.0001" min="0" id="kp-min-stock" class="kitchen-input mt-1 w-full" placeholder="0">
                        </div>
                        <div id="kpStatusField" class="flex items-center gap-2 pt-6">
                            <input type="checkbox" id="kp-status" checked class="h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                            <label for="kp-status" class="text-sm text-gray-700">Active</label>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="kitchen-label">Description / Note</label>
                            <textarea id="kp-description" rows="2" placeholder="Optional note — brand, supplier detail, storage…"
                                class="kitchen-input mt-1 w-full"></textarea>
                        </div>
                        {{-- Menu-item photo (cooking products only — raw/packaging materials
                             are never shown to the cashier, so this is hidden for them). --}}
                        <div id="kpImageField" class="sm:col-span-2">
                            <label class="kitchen-label">Image</label>
                            <div class="mt-1 flex items-center gap-3">
                                <div id="kpImagePreview" class="h-16 w-16 rounded-lg border border-gray-200 bg-gray-50 overflow-hidden flex items-center justify-center text-gray-300 shrink-0">
                                    <i class="fa-solid fa-image"></i>
                                </div>
                                <input type="file" id="kp-image" accept="image/*" class="kitchen-input flex-1">
                            </div>
                            <p class="mt-1 text-2xs text-gray-400">Shown to the cashier on the menu. Optional.</p>
                        </div>
                    </div>

                    {{-- Alternate units (e.g. buy in "kg", stock/recipe stays in "g") — raw
                         material / packaging material only. Cooking products (finished
                         dishes) are always sold as one whole unit, no conversions needed. --}}
                    <div id="kpUnitConversions" class="kitchen-subcard hidden">
                        <div class="flex items-center gap-2 mb-3">
                            <i class="fa-solid fa-scale-balanced text-gray-400 text-sm"></i>
                            <p class="text-sm font-bold text-gray-700">Alternate Units</p>
                            <span class="text-xs text-gray-400">— e.g. purchase in kg, base unit stays g</span>
                        </div>
                        <div id="kpConversionsList" class="space-y-1.5 mb-3"></div>
                        <div class="flex flex-wrap gap-2">
                            <select id="kpConversionUnit" class="kitchen-input flex-1 min-w-32"></select>
                            <input type="number" step="0.000001" id="kpConversionFactor" placeholder="factor, e.g. 1000" class="kitchen-input w-36">
                            <button type="button" onclick="addKitchenUnitConversion()" class="kitchen-btn-outline shrink-0">
                                <i class="fa-solid fa-plus text-xs"></i> Add
                            </button>
                        </div>
                    </div>
                </div>
                <div class="kitchen-modal-footer">
                    <button type="button" onclick="closeKitchenProductModal()" class="kitchen-btn-outline">Cancel</button>
                    <button type="submit" class="kitchen-btn-dark">Save</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===================== CATEGORY MANAGER MODAL =====================
         Add / rename / remove categories (scoped by kind: fg / rm / pm).
         Deleting a category never touches the products in it. --}}
    <div id="categoryManagerModal" class="fixed inset-0 hidden items-center justify-center backdrop-blur-sm bg-black/60 p-4 z-60">
        <div class="relative w-full max-w-lg">
            <div class="kitchen-modal-card flex flex-col max-h-[85vh]">
                <div class="kitchen-modal-header shrink-0">
                    <div class="flex items-center gap-3">
                        <span class="h-8 w-8 rounded-md bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-600 text-xs"><i class="fa-solid fa-tags"></i></span>
                        <h3 class="text-[15px] font-semibold text-gray-900 leading-tight">Manage Categories</h3>
                    </div>
                    <button type="button" onclick="closeCategoryManager()" class="kitchen-modal-close">✕</button>
                </div>
                <div class="p-5 space-y-4 overflow-y-auto">
                    <div class="flex items-center gap-2">
                        <label class="kitchen-label shrink-0">Kind</label>
                        <select id="cmKind" class="kitchen-input" onchange="loadCategoryManager()">
                            <option value="fg">Cooking Product (FG)</option>
                            <option value="rm">Raw Material</option>
                            <option value="pm">Packaging</option>
                        </select>
                    </div>
                    {{-- Add a new category --}}
                    <form id="cmAddForm" class="flex items-end gap-2">
                        <div class="flex-1">
                            <label class="kitchen-label">New category name</label>
                            <input type="text" id="cmNewName" placeholder="e.g. Dairy, Boxes…" class="kitchen-input mt-1 w-full">
                        </div>
                        <button type="submit" class="kitchen-btn-dark shrink-0"><i class="fa-solid fa-plus"></i> Add</button>
                    </form>
                    <div class="kitchen-card overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="kitchen-thead">
                                <tr><th class="px-4 py-2 text-left">Name</th><th class="px-4 py-2 text-right">Products</th><th class="px-4 py-2 text-center w-24">Action</th></tr>
                            </thead>
                            <tbody id="cmList"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== RECIPE MODAL ===================== --}}
    {{-- Overlay itself never scrolls and the card is a fixed height, so the modal
         stays put — switching tabs / variant bar showing doesn't resize + recenter
         it (that was the "run around"). The inner body scrolls instead. --}}
    <div id="default-modal-recipe" class="recipe-modal-overlay fixed inset-0 hidden items-center justify-center backdrop-blur-sm bg-black/60 p-4 z-50">
        <div class="relative w-full max-w-4xl lg:w-220">
            <div class="kitchen-modal-card recipe-modal-card flex flex-col">
                <div class="kitchen-modal-header shrink-0">
                    <div class="flex items-center gap-3 min-w-0">
                        {{-- Dish photo (same image the cashier sees). Falls back to an icon. --}}
                        <div id="recipeDishImg" class="h-11 w-11 rounded-lg bg-gray-100 border border-gray-200 overflow-hidden flex items-center justify-center text-gray-400 shrink-0">
                            <i class="fa-solid fa-clipboard-list"></i>
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-[15px] font-semibold text-gray-900 leading-tight">Manage Recipe</h3>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <p id="recipeProductLabel" class="text-xs text-gray-500 truncate">&nbsp;</p>
                                {{-- Rename just the variant label (e.g. "M" → "Medium"); the dish name
                                     and its grouping stay the same. --}}
                                <button type="button" id="recipeRenameBtn" onclick="renameVariant()"
                                    title="Rename this variant"
                                    class="inline-flex items-center gap-1 text-2xs px-2 py-0.5 rounded-md border border-gray-300 bg-white text-gray-600 hover:text-sky-600 hover:border-sky-300 font-semibold">
                                    <i class="fa-solid fa-pen"></i> Rename
                                </button>
                                {{-- Edit the menu item's info + photo — reuses the full product form. --}}
                                <button type="button" id="recipeEditInfoBtn" onclick="editMenuInfoFromRecipe()"
                                    title="Edit this menu item's name, photo, price & details"
                                    class="inline-flex items-center gap-1 text-2xs px-2 py-0.5 rounded-md border border-gray-300 bg-white text-gray-600 hover:text-amber-600 hover:border-amber-300 font-semibold">
                                    <i class="fa-solid fa-sliders"></i> Edit info
                                </button>
                            </div>
                        </div>
                    </div>
                    <button type="button" onclick="closeRecipeModal()" class="kitchen-modal-close">✕</button>
                </div>
                <input type="hidden" id="recipeProductId">

                {{-- Variant switcher — one pill per size of this dish. Each variant has
                     its own components/add-ons, so switching reloads that variant's BOM.
                     Arrows reorder them (S, M, L, XL) and persist to sort_order. --}}
                <div id="recipeVariantBar" class="hidden shrink-0 items-center gap-2 px-6 py-2.5 bg-gray-50 border-b border-gray-200">
                    <span class="text-2xs uppercase tracking-wider text-gray-500 font-semibold shrink-0">Variants</span>
                    <div id="recipeVariantPills" class="flex items-center gap-1.5 overflow-x-auto flex-1"></div>
                    <button type="button" id="recipeVariantSortBtn" onclick="toggleVariantSortMode()"
                        class="shrink-0 text-2xs px-2 py-1 rounded-md border border-gray-300 bg-white text-gray-600 hover:bg-gray-100">
                        <i class="fa-solid fa-arrow-up-wide-short"></i> Reorder
                    </button>
                </div>

                {{-- Cost strip: computed material + routing vs the chef's manual cost --}}
                <div class="shrink-0 grid grid-cols-4 gap-px bg-gray-200 border-b border-gray-200 text-center">
                    <div class="bg-amber-50 py-2">
                        <p class="text-2xs uppercase tracking-wider text-amber-700 font-semibold">Avg Material Cost</p>
                        <p id="recipeAvgCost" class="text-sm font-bold text-amber-800 tabular-nums">—</p>
                    </div>
                    {{-- Material + routing = what the finished good actually costs to make --}}
                    <div class="bg-amber-100 py-2">
                        <p class="text-2xs uppercase tracking-wider text-amber-800 font-semibold">Total AVG Cost</p>
                        <p id="recipeTotalCost" class="text-sm font-bold text-amber-900 tabular-nums">—</p>
                    </div>
                    <div class="bg-white py-2 px-2">
                        <p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold">Estimated Cost</p>
                        <input type="number" step="0.0001" min="0" id="recipeChefCost"
                            class="w-full text-center text-sm font-bold text-gray-800 tabular-nums border border-transparent hover:border-gray-300 focus:border-sky-400 rounded-md py-0.5 outline-none">
                    </div>
                    <div class="bg-white py-2 px-2">
                        <p class="text-2xs uppercase tracking-wider text-gray-500 font-semibold">Sell Price</p>
                        <input type="number" step="0.01" min="0" id="recipeSellPrice"
                            class="w-full text-center text-sm font-bold text-gray-800 tabular-nums border border-transparent hover:border-gray-300 focus:border-sky-400 rounded-md py-0.5 outline-none">
                    </div>
                </div>

                {{-- Shown when the open variant is live on the menu: its recipe/price is
                     read-only until the chef disables it (prevents editing a dish
                     while customers can still order it). --}}
                <div id="recipeLockBanner" class="hidden shrink-0 items-center gap-2 px-6 py-2 bg-amber-50 border-b border-amber-200 text-xs text-amber-800">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>This variant is <b>locked</b>. To edit its recipe, add-ons or routing, set the status to <b>Under development</b> above first.</span>
                </div>

                {{-- Internal tabs — Components (always consumed) vs Add-ons (optional extras) --}}
                <div class="shrink-0 flex items-center gap-1 px-6 pt-3 border-b border-gray-100">
                    <button type="button" class="recipe-modal-tab-btn active" data-recipe-tab="components" onclick="switchRecipeModalTab('components')">
                        <i class="fa-solid fa-flask"></i> Components
                    </button>
                    <button type="button" class="recipe-modal-tab-btn" data-recipe-tab="addons" onclick="switchRecipeModalTab('addons')">
                        <i class="fa-solid fa-circle-plus"></i> Add-ons
                    </button>
                    <button type="button" class="recipe-modal-tab-btn" data-recipe-tab="routine" onclick="switchRecipeModalTab('routine')">
                        <i class="fa-solid fa-list-ol"></i> Routine
                    </button>
                </div>

                <div id="recipeModalBody" class="p-6 flex-1 min-h-0 overflow-y-auto">
                    <div id="recipeModalTab-components" class="recipe-modal-tab-panel">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs text-gray-400">Always consumed per 1 unit sold — quantity in the unit you pick, deducted in the material's base unit.</p>
                            <button type="button" onclick="addRecipeRow('component')" class="kitchen-btn-dark px-3! py-1.5! text-xs!">
                                + Add Component
                            </button>
                        </div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 text-xs uppercase tracking-wide">
                                    <th class="py-2">Raw / Packaging Material</th>
                                    <th class="py-2 w-24">Qty</th>
                                    <th class="py-2 w-24">Unit</th>
                                    <th class="py-2 w-28 text-right">≈ Base Qty</th>
                                    <th class="py-2 w-24 text-right">Avg Cost</th>
                                    <th class="py-2 w-8"></th>
                                </tr>
                            </thead>
                            <tbody id="recipeComponentsBody"></tbody>
                        </table>
                    </div>

                    <div id="recipeModalTab-addons" class="recipe-modal-tab-panel hidden">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs text-gray-400">Optional extras on this variant — e.g. "Add Mushroom" −5 g, "Extra Mushroom" −8 g. Consumed only when the order asks for it.</p>
                            <button type="button" onclick="addRecipeRow('add_on')" class="kitchen-btn-dark px-3! py-1.5! text-xs!">
                                + Add Add-on
                            </button>
                        </div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 text-xs uppercase tracking-wide">
                                    <th class="py-2 w-36">Add-on Name</th>
                                    <th class="py-2">Material</th>
                                    <th class="py-2 w-20">Qty</th>
                                    <th class="py-2 w-24">Unit</th>
                                    <th class="py-2 w-24 text-right">≈ Material Cost</th>
                                    <th class="py-2 w-24 text-right">Extra Price</th>
                                    <th class="py-2 w-8"></th>
                                </tr>
                            </thead>
                            <tbody id="recipeAddonsBody"></tbody>
                        </table>
                    </div>

                    <div id="recipeModalTab-routine" class="recipe-modal-tab-panel hidden">
                        <div class="flex items-center justify-between gap-3 mb-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2">
                            <div>
                                <label class="text-2xs uppercase tracking-wider text-amber-700 font-semibold block">Routing / Labor Cost <span class="normal-case text-amber-600">(per unit, this variant)</span></label>
                                <p class="text-2xs text-amber-600/80">Added to material cost at consumption to get the finished-good cost.</p>
                            </div>
                            <input type="number" step="0.0001" min="0" id="recipeRoutingCost" placeholder="0.0000"
                                class="w-32 text-right text-sm font-bold text-amber-800 tabular-nums border border-amber-300 rounded-md px-2 py-1.5 outline-none focus:border-amber-500">
                        </div>
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs text-gray-400">Ordered prep steps for <span class="font-semibold" id="routineVariantLabel">this variant</span> — add in order. Each size can have its own routine.</p>
                            <button type="button" onclick="addRoutineStep()" class="kitchen-btn-dark px-3! py-1.5! text-xs!">
                                + Add Step
                            </button>
                        </div>
                        <div class="flex items-center gap-2 px-1 pb-1 text-2xs uppercase tracking-wider text-gray-400 font-semibold">
                            <span class="w-6 shrink-0"></span>
                            <span class="flex-1">Step</span>
                            <span class="w-24 shrink-0 text-right">Cost</span>
                            <span class="w-4 shrink-0"></span>
                        </div>
                        <div id="recipeRoutineBody" class="space-y-2"></div>
                    </div>
                </div>
                <div class="kitchen-modal-footer justify-between!">
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="openVariantModal()" class="kitchen-btn-outline">
                            <i class="fa-solid fa-clone"></i> Add Variant
                        </button>
                        {{-- 3-state status. Enable = on the menu (recipe locked while live);
                             Disable / Under development = hidden from the cashier and editable.
                             Under development means the chef is still testing before publishing. --}}
                        <div class="flex items-center gap-1.5">
                            <span class="text-2xs uppercase tracking-wider text-gray-400 font-semibold">Status</span>
                            <select id="recipeStatusSelect" onchange="setVariantStatus(this.value)"
                                class="kitchen-input py-1.5 text-xs font-semibold">
                                <option value="1">Enable (on sale)</option>
                                <option value="2">Disable</option>
                                <option value="3">Under development</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="closeRecipeModal()" class="kitchen-btn-outline">Cancel</button>
                        <button type="button" id="btnSaveRecipe" onclick="saveRecipe()" class="kitchen-btn-dark">Save Recipe</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== ADD VARIANT MODAL ===================== --}}
    {{-- Clones the open dish: everything is copied (category, image, VAT, and the
         whole component + add-on recipe) — only the variant name, sell price and
         baseline cost differ. Actual cost still comes from real material usage. --}}
    <div id="variantModal" class="fixed inset-0 hidden items-center justify-center backdrop-blur-sm bg-black/60 p-4 z-60">
        <div class="relative w-full max-w-md">
            <div class="kitchen-modal-card">
                <div class="kitchen-modal-header">
                    <div class="flex items-center gap-3">
                        <span class="h-8 w-8 rounded-md bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-600 text-xs"><i class="fa-solid fa-clone"></i></span>
                        <div>
                            <h3 class="text-[15px] font-semibold text-gray-900 leading-tight">Add Variant</h3>
                            <p id="variantSourceLabel" class="text-xs text-gray-500">&nbsp;</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeVariantModal()" class="kitchen-modal-close">✕</button>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="kitchen-label">Variant Name *</label>
                        <input type="text" id="variantName" placeholder="e.g. Large, Pan, Family Size" class="kitchen-input mt-1 w-full">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="kitchen-label">Sell Price</label>
                            <input type="number" step="0.01" min="0" id="variantSellPrice" class="kitchen-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="kitchen-label">Baseline Cost</label>
                            <input type="number" step="0.000001" min="0" id="variantCost" class="kitchen-input mt-1 w-full">
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" id="variantCopyRecipe" checked class="h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                        Copy the components &amp; add-ons too
                    </label>
                    <p class="text-2xs text-gray-400 bg-gray-50 border border-gray-200 rounded-lg p-2.5">
                        Baseline cost is only a starting figure — the real cost is calculated
                        automatically from the raw &amp; packaging material this variant consumes.
                    </p>
                </div>
                <div class="kitchen-modal-footer">
                    <button type="button" onclick="closeVariantModal()" class="kitchen-btn-outline">Cancel</button>
                    <button type="button" onclick="saveVariant()" class="kitchen-btn-dark">Create Variant</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== MATERIAL USAGE MODAL ===================== --}}
    {{-- Where a raw/packaging material is used: dish + variant, component vs
         add-on, quantity per unit sold in the material's BASE unit, and its cost. --}}
    <div id="materialUsageModal" class="fixed inset-0 hidden items-center justify-center backdrop-blur-sm bg-black/60 p-4 z-60">
        <div class="relative w-full max-w-2xl">
            <div class="kitchen-modal-card flex flex-col max-h-[85vh]">
                <div class="kitchen-modal-header shrink-0">
                    <div class="flex items-center gap-3">
                        <span class="h-8 w-8 rounded-md bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-600 text-xs"><i class="fa-solid fa-list-ul"></i></span>
                        <div>
                            <h3 class="text-[15px] font-semibold text-gray-900 leading-tight">Material Usage</h3>
                            <p id="materialUsageLabel" class="text-xs text-gray-500">&nbsp;</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeMaterialUsage()" class="kitchen-modal-close">✕</button>
                </div>
                <div class="p-0 flex-1 min-h-0 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="kitchen-thead" style="position: sticky; top: 0;">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Dish</th>
                                <th class="px-4 py-2.5 text-left">Use</th>
                                <th class="px-4 py-2.5 text-right">Qty (base)</th>
                                <th class="px-4 py-2.5 text-right">Cost</th>
                            </tr>
                        </thead>
                        <tbody id="materialUsageBody"></tbody>
                    </table>
                </div>
                <div class="kitchen-modal-footer shrink-0">
                    <button type="button" onclick="closeMaterialUsage()" class="kitchen-btn-outline">Close</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* ============================================================
           Chef page skin — deliberately the SAME language as the rest of
           the app (Sale screen): amber-400 chrome with white pill chips,
           rounded-xl amber primary buttons, white cards with gray-200
           hairlines, gray-50 uppercase table heads, amber-50 row hovers.
           No custom fonts, no custom neutrals — app.css owns those.
           ============================================================ */

        /* Frosted pill track on the amber bar — same as the Sale screen's .tab-track
           (style.css isn't loaded on this standalone page, so the recipe is inlined). */
        .tab-track { background: rgba(255, 255, 255, .32); border: 1px solid rgba(255, 255, 255, .5); border-radius: 13px; box-shadow: inset 0 1px 2px rgba(120, 53, 15, .06); scrollbar-width: thin; scrollbar-color: rgba(0, 0, 0, .2) transparent; scroll-behavior: smooth; }
        .tab-track::-webkit-scrollbar { height: 3px; }
        .tab-track::-webkit-scrollbar-track { background: transparent; }
        .tab-track::-webkit-scrollbar-thumb { background: rgba(0, 0, 0, .15); border-radius: 2px; }

        /* ---- Top-bar pills (segmented control on the amber bar) ----
           Refined per Emil Kowalski's design-engineering principles (animations.dev):
           press feedback via scale(0.97), a strong custom ease-out curve, specific
           (never "all") transition props, and hover gated to fine pointers so a
           tapped tab on the kitchen touchscreen doesn't stick in :hover. */
        .kitchen-tab-btn { white-space: nowrap; padding: .55rem 1.2rem; border-radius: 9px; font-size: .82rem; font-weight: 600; letter-spacing: .01em; color: #7c2d12; background: transparent; border: 1px solid transparent; transition: background .18s cubic-bezier(.23, 1, .32, 1), color .18s ease, box-shadow .18s cubic-bezier(.23, 1, .32, 1), transform .1s ease-out; cursor: pointer; display: inline-flex; align-items: center; }
        @media (hover: hover) and (pointer: fine) {
            .kitchen-tab-btn:hover { background: rgba(255, 255, 255, .5); color: #431407; }
        }
        .kitchen-tab-btn:active { transform: scale(.97); }
        .kitchen-tab-btn.active { color: #0f172a; background: #ffffff; border-color: rgba(0, 0, 0, .05); box-shadow: 0 2px 8px rgba(120, 53, 15, .22), 0 1px 2px rgba(0, 0, 0, .06); }
        .kitchen-tab-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 1.125rem; height: 1.125rem; padding: 0 .3rem; border-radius: 9999px; background: #d97706; color: #fff; font-size: .6875rem; font-weight: 700; font-variant-numeric: tabular-nums; }

        /* ---- White chip actions on the amber bar (Purchase / Sale / Logout) ---- */
        .kitchen-chip-btn { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem .8rem; border-radius: .75rem; background: rgba(255,255,255,.7); border: 1px solid rgba(0,0,0,.05); color: #78350f; font-size: .8rem; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,.05); transition: background .15s ease; }
        .kitchen-chip-btn:hover { background: #ffffff; }

        /* The whole page never scrolls — <main> is a fixed-height flex column and
           whichever tab-panel is active fills it, scrolling only its own inner
           region (a table body, the orders board, etc.) via .kitchen-scroll. */
        .kitchen-tab-panel { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
        .kitchen-tab-panel.hidden { display: none; }
        .kitchen-scroll { flex: 1; min-height: 0; overflow: auto; }

        /* ---- Buttons: same recipes as the Sale screen ---- */
        .kitchen-btn-dark { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem 1rem; border-radius: .75rem; background: #fbbf24; color: #451a03; font-size: .8125rem; font-weight: 700; box-shadow: 0 1px 2px rgba(0,0,0,.05); transition: background .15s ease; }
        .kitchen-btn-dark:hover { background: #f59e0b; }
        .kitchen-btn-outline { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem .9rem; border-radius: .75rem; border: 1px solid #d1d5db; background: #fff; color: #4b5563; font-size: .8125rem; font-weight: 600; transition: background .15s ease, border-color .15s ease; }
        .kitchen-btn-outline:hover { background: #f9fafb; border-color: #9ca3af; }

        /* ---- Surfaces: white card + gray-50 head + amber-50 row hover, as in list view ---- */
        .kitchen-card { background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
        .kitchen-thead { background: #f9fafb; font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; font-weight: 600; border-bottom: 1px solid #e5e7eb; }
        .kitchen-card tbody tr { transition: background .1s ease; }
        .kitchen-card tbody tr:hover { background: #fffbeb; }
        .kitchen-input { border-radius: .75rem; border: 1px solid #d1d5db; padding: .5rem .8rem; font-size: .8125rem; background: #fff; color: #111827; transition: border-color .15s ease, box-shadow .15s ease; }
        .kitchen-input:focus { outline: none; border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.15); }
        .kitchen-label { font-size: .75rem; font-weight: 600; color: #4b5563; }

        /* ---- Modals: white rounded-2xl, light header — same as the Sale screen's pickers ---- */
        .kitchen-modal-card { background: #fff; border-radius: 1rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,.25); overflow: hidden; }
        .kitchen-modal-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; background: #fff; border-bottom: 1px solid #e5e7eb; }
        .kitchen-modal-footer { display: flex; justify-content: flex-end; gap: .5rem; padding: .875rem 1.25rem; background: #f9fafb; border-top: 1px solid #e5e7eb; }
        .kitchen-modal-close { display: flex; height: 1.75rem; width: 1.75rem; align-items: center; justify-content: center; border-radius: .5rem; background: transparent; color: #9ca3af; transition: background .15s ease, color .15s ease; }
        .kitchen-modal-close:hover { background: #f3f4f6; color: #111827; }

        /* ---- Recipe modal internal tabs (Attributes / Ingredients) ---- */
        .recipe-modal-tab-btn { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem .75rem; font-size: .8125rem; font-weight: 600; color: #9ca3af; border-bottom: 2px solid transparent; transition: color .15s ease, border-color .15s ease; }
        .recipe-modal-tab-btn:hover { color: #111827; }
        .recipe-modal-tab-btn.active { color: #111827; border-bottom-color: #f59e0b; }
        .recipe-modal-tab-panel.hidden { display: none; }

        /* ---- Kitchen order ticket ---- */
        /* ---- Order tickets (Pending / Prepared) — professional card, designed with
           the apple-design + emil-design-eng skills: clear hierarchy, a status accent
           rail, consistent heights (meta+action pinned to the bottom), soft depth,
           scale(0.97) press feedback, and hover gated to fine pointers (touchscreen). */
        .kitchen-ticket { position: relative; background: #fff; border: 1px solid #eceef1; border-radius: 14px; padding: .9rem 1rem 1rem 1.1rem; box-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 1px 3px rgba(16, 24, 40, .05); display: flex; flex-direction: column; gap: .6rem; cursor: pointer; transition: box-shadow .2s cubic-bezier(.23, 1, .32, 1), transform .2s cubic-bezier(.23, 1, .32, 1), border-color .2s ease; }
        .kitchen-ticket::before { content: ""; position: absolute; left: 0; top: 12px; bottom: 12px; width: 3px; border-radius: 0 3px 3px 0; background: #f59e0b; }
        .kitchen-ticket.is-out::before { background: #f43f5e; }
        .kitchen-ticket.is-done::before { background: #10b981; }
        @media (hover: hover) and (pointer: fine) { .kitchen-ticket:hover { box-shadow: 0 8px 20px rgba(16, 24, 40, .10), 0 2px 5px rgba(16, 24, 40, .06); transform: translateY(-2px); border-color: #e2e8f0; } }
        .kitchen-ticket:active { transform: translateY(0) scale(.985); }
        .kitchen-ticket.is-selected { border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245, 158, 11, .4), 0 8px 20px rgba(16, 24, 40, .10); }
        .kitchen-ticket.is-done.is-selected { border-color: #10b981; box-shadow: 0 0 0 2px rgba(16, 185, 129, .4), 0 8px 20px rgba(16, 24, 40, .10); }
        .kitchen-ticket-title { font-weight: 700; font-size: .95rem; line-height: 1.2; letter-spacing: -.01em; color: #0f172a; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .kitchen-ticket-variant { margin-top: .3rem; display: inline-flex; align-items: center; font-size: .6875rem; font-weight: 600; padding: .12rem .5rem; border-radius: 999px; background: #fff7ed; color: #b45309; border: 1px solid #fed7aa; }
        .kitchen-ticket.is-done .kitchen-ticket-variant { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .kitchen-ticket-qty { flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; min-width: 2.15rem; height: 2.15rem; padding: 0 .5rem; border-radius: 11px; background: #fff7ed; color: #b45309; border: 1px solid #fed7aa; font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -.02em; }
        .kitchen-ticket.is-done .kitchen-ticket-qty { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .kitchen-ticket-meta { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .6875rem; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: .6rem; }
        .kitchen-ticket-meta i { color: #cbd5e1; margin-right: .25rem; }
        .kitchen-ticket-btn { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: .4rem; padding: .55rem .75rem; border-radius: 10px; border: 1px solid transparent; font-size: .75rem; font-weight: 700; cursor: pointer; transition: background .16s ease, box-shadow .16s ease, transform .1s ease-out; }
        .kitchen-ticket-btn:active { transform: scale(.97); }
        .kitchen-ticket-btn.is-consume { background: #f59e0b; color: #451a03; box-shadow: 0 1px 2px rgba(180, 83, 9, .25); }
        .kitchen-ticket-btn.is-outstock { background: #fff1f2; color: #be123c; border-color: #fecdd3; }
        @media (hover: hover) and (pointer: fine) { .kitchen-ticket-btn.is-consume:hover { background: #f97316; } .kitchen-ticket-btn.is-outstock:hover { background: #ffe4e6; } }

        /* ---- Stat cards: label + number on a plain white card ---- */
        .kitchen-stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; padding: .875rem 1rem; box-shadow: 0 1px 2px rgba(0,0,0,.05); display: flex; align-items: center; gap: .75rem; }
        .kitchen-stat-value { font-size: 1.5rem; font-weight: 700; color: #111827; line-height: 1.15; letter-spacing: -.01em; font-variant-numeric: tabular-nums; }

        /* ---- Product form: Base Unit combo (select drives it; free-text only for "Custom") ---- */
        #kp-unit.is-structured { background: #f9fafb; color: #6b7280; }

        /* ---- Alternate Units card inside the product modal ---- */
        .kitchen-subcard { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: .75rem; padding: .875rem; }

        /* ---- Menu item card: fixed height so rows stay even regardless of image
               size or name/description length. The image is a fixed box and the
               text area gets the remaining space with its own overflow. ---- */
        .kitchen-menu-card { height: 15.5rem; border-radius: .75rem !important; border-color: #e5e7eb !important; box-shadow: 0 1px 2px rgba(0,0,0,.05); transition: border-color .15s ease, box-shadow .15s ease !important; }
        .kitchen-menu-card:hover { border-color: #fbbf24 !important; box-shadow: 0 2px 6px rgba(0,0,0,.08) !important; }
        .kitchen-menu-card > img { height: 6.5rem; width: 100%; object-fit: cover; flex-shrink: 0; }
        .kitchen-menu-card .kmc-body { flex: 1; min-height: 0; overflow: hidden; }
        .kitchen-menu-card .kmc-name { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        /* ---- Products table: keep secondary columns from crowding out small/tablet screens ---- */
        @media (max-width: 1024px) {
            .kitchen-col-optional { display: none; }
        }

        /* ============================================================
           Build-independent fallbacks.
           This page is served with whatever CSS bundle currently exists in
           public/build. Utilities added since the last `npm run build` simply
           don't exist there (a missing z-60 silently becomes z-index:auto,
           which is what put the variant modal *under* the recipe modal).
           These rules are inline, so they always apply — rebuilding assets
           makes them redundant, never wrong.
           ============================================================ */
        #variantModal, #materialUsageModal, #orderDetailsModal, #categoryManagerModal, #kitchenOrderDetailModal, #menuSoldTodayModal, #kitchenSummaryModal { z-index: 60; }  /* above the base modals (z-50) */
        /* Fixed-height, always-centered recipe modal — no jumping as content changes */
        .recipe-modal-card { height: 85vh; max-height: 42rem; }
        #purResultsBox { z-index: 9999; }           /* material typeahead, above everything */
        #whereUsedPopover { z-index: 9999; }
        .kitchen-modal-footer.justify-between\! { justify-content: space-between; }
        .min-w-50 { min-width: 12.5rem; }
        .max-w-56 { max-width: 14rem; }
        .max-w-64 { max-width: 16rem; }
        .line-clamp-1 { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .tabular-nums { font-variant-numeric: tabular-nums; }
        /* text-2xs is a custom step this project never defined anywhere — without
           this the ~50 micro-labels on the page just inherit their parent size. */
        .text-2xs { font-size: 0.6875rem; line-height: 1rem; }
        /* Variant-chip status colours + active ring — not all present in the
           Jul-21 build. Green = on sale, red = disabled, amber ring = editing. */
        .border-emerald-300 { border-color: #6ee7b7; }
        .border-rose-300 { border-color: #fda4af; }
        .hover\:border-emerald-500:hover { border-color: #10b981; }
        .hover\:border-rose-500:hover { border-color: #f43f5e; }
        .hover\:bg-rose-200:hover { background-color: #fecdd3; } /* out-of-stock button hover */
        .ring-2.ring-amber-400 { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #fbbf24; }
        @media (min-width: 1280px) {
            #cookingProductPicker.xl\:grid-cols-8 { grid-template-columns: repeat(8, minmax(0, 1fr)); }
        }

        /* Recipe modal: loading skeleton + content fade-in when switching variants,
           so the switch feels instant instead of freezing on an empty panel. */
        @keyframes ktShimmer { 0% { background-position: -450px 0; } 100% { background-position: 450px 0; } }
        .kt-skeleton {
            background: #eef1f4;
            background-image: linear-gradient(90deg, #eef1f4 0, #f7f8fa 45%, #eef1f4 90%);
            background-size: 900px 100%;
            border-radius: .5rem;
            animation: ktShimmer 1.15s infinite linear;
        }
        @keyframes ktFadeInUp { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        .kt-fade-in { animation: ktFadeInUp .22s ease-out; }
        /* Active variant pill snaps in immediately on click (optimistic), before load. */
        .recipe-variant-loading { opacity: .6; pointer-events: none; }
    </style>

    <script>
        // Gates the per-row "Buy" button in the Material list.
        window.CAN_KITCHEN_PURCHASE = @json(Auth::user()->hasPermission('kitchen.purchase'));
    </script>
    <script src="{{ asset('assets/js/kitchen.js') }}?v={{ filemtime(public_path('assets/js/kitchen.js')) }}"></script>
</body>

</html>
