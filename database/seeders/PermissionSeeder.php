<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed permission rows per section — only the actions that section
     * actually supports in the app (e.g. Exchange Rate has no create/delete,
     * it's only ever updated; Product/User have no delete route at all).
     * Idempotent (updateOrCreate) — safe to re-run when actions/sections change.
     * Any previously-seeded row for an action a section no longer supports
     * is removed (cascades to permission_user, so it silently drops from
     * any user who had it).
     */
    public function run(): void
    {
        $sectionLabels = [
            'pos_sale'        => 'POS / Sale',
            'warehouse'       => 'Manage Warehouse',
            'product'         => 'Manage Products',
            'category'        => 'Manage Categories',
            'quotation'       => 'Manage Quotes',
            'customer'        => 'Manage Customers',
            'purchasing'      => 'Purchasing',
            'vendor'          => 'Manage Vendors',
            'exchange_rate'   => 'Exchange Rate',
            'user'            => 'Manage Users',
            'company_profile' => 'Company Profile',
            'report'          => 'Reports',
            'kitchen'         => 'Kitchen',
            'print'           => 'Print',
        ];

        // Only the actions each section genuinely has a route/guard for.
        // pos_sale and purchasing use domain-specific actions instead of generic
        // CRUD (e.g. "sell" / "edit_price" / "edit_discount" instead of "create") —
        // matches the real capabilities cashiers/purchasers are granted individually.
        // "report" bundles every report screen as its own individually-grantable
        // action instead of each report being its own top-level section — each
        // action is a distinct report a user can be allowed or not.
        $sectionActions = [
            'pos_sale'        => ['view', 'order', 'sell', 'edit_price', 'edit_discount', 'view_grid', 'view_list'],
            // Standalone "Print" section — each printable document is its own grant.
            'print'           => ['receipt', 'invoice', 'delivery', 'picking_list'],
            'warehouse'       => ['view', 'create', 'edit', 'delete', 'adjustment', 'transfer', 'movement'],
            'product'         => ['view', 'create', 'edit'],
            'category'        => ['view', 'create', 'edit', 'delete'],
            'quotation'       => ['view', 'create', 'edit'],
            'customer'        => ['view', 'create', 'edit', 'delete'],
            'purchasing'      => ['view', 'purchase', 'purchase_return'],
            'vendor'          => ['view', 'create', 'edit'],
            'exchange_rate'   => ['view', 'edit'],
            'user'            => ['view', 'create', 'edit'],
            'company_profile' => ['view', 'edit'],
            'report'          => ['dashboard', 'sales', 'expense', 'stock'],
            // Chef / Supervisor-Chef's dedicated Kitchen interface — separate from
            // pos_sale/product/purchasing so it can be granted without also
            // exposing the Sale screen or general (non-kitchen) product catalog.
            'kitchen'         => ['view', 'prepare', 'purchase', 'recipe', 'product', 'report'],
        ];

        $actionLabels = [
            'view'            => 'View',
            'create'          => 'Create',
            'edit'            => 'Edit',
            'delete'          => 'Delete',
            'order'           => 'Create Order (No Stock Deduction)',
            'sell'            => 'Sell (Checkout)',
            'edit_price'      => 'Edit Price',
            'edit_discount'   => 'Edit Discount',
            'purchase'        => 'Purchase',
            'purchase_return' => 'Purchase Return',
            'view_grid'       => 'Product View: Grid',
            'view_list'       => 'Product View: List',
            'adjustment'      => 'Stock Adjustment',
            'transfer'        => 'Transfer (Warehouse to Warehouse)',
            'movement'        => 'Movement (Bin to Bin)',
            'prepare'         => 'Mark Order Prepared',
            'recipe'          => 'Manage Recipe (BOM)',
            'product'         => 'Set Up Product',
            'receipt'         => 'Receipt',
            'invoice'         => 'Invoice',
            'delivery'        => 'Delivery Note',
            'picking_list'    => 'Picking List',
        ];

        // Overrides for the handful of "{action} {section}" combos that don't
        // read sensibly auto-generated.
        $labelOverrides = [
            'pos_sale.order'              => 'Create Order (Step 1 — No Stock Deduction Yet)',
            'pos_sale.sell'               => 'Sell (Checkout — Order + Deduct Stock in One Step)',
            'pos_sale.edit_price'         => 'Edit Price (POS)',
            'pos_sale.edit_discount'      => 'Edit Discount (POS)',
            'pos_sale.view_grid'          => 'Product View: Grid (POS)',
            'pos_sale.view_list'          => 'Product View: List (POS)',
            'print.receipt'               => 'Print Receipt',
            'print.invoice'               => 'Print Invoice',
            'print.delivery'              => 'Print Delivery Note',
            'print.picking_list'          => 'Print Picking List',
            'purchasing.purchase'         => 'Create Purchase',
            'purchasing.purchase_return'  => 'Purchase Return',
            'warehouse.adjustment'        => 'Stock Adjustment',
            'warehouse.transfer'          => 'Transfer (Different Warehouse)',
            'warehouse.movement'          => 'Movement (Same Warehouse, Between Bins)',
            'vendor.view'                 => 'View Vendors',
            'vendor.create'               => 'Create Vendor',
            'vendor.edit'                 => 'Edit Vendor',
            'report.dashboard'            => 'Dashboard / KPI',
            'report.sales'                => 'Sales Report',
            'report.expense'              => 'Expense Report',
            'report.stock'                => 'Stock In/Out Report',
            'kitchen.view'                => 'View Kitchen Orders',
            'kitchen.prepare'             => 'Mark Order Prepared (Consumes Recipe)',
            'kitchen.purchase'            => 'Purchase Raw/Packaging Material',
            'kitchen.recipe'              => 'Manage Recipe / BOM',
            'kitchen.product'             => 'Set Up Cooking/Raw/Packaging Products',
            'kitchen.report'              => 'View End-of-Day Report (Sales + Material Usage)',
        ];

        $keptIds = [];

        foreach ($sectionActions as $section => $actions) {
            foreach ($actions as $action) {
                $key = "{$section}.{$action}";
                $permission = Permission::updateOrCreate(
                    ['section' => $section, 'action' => $action],
                    [
                        'key'   => $key,
                        'label' => $labelOverrides[$key] ?? "{$actionLabels[$action]} {$sectionLabels[$section]}",
                    ]
                );
                $keptIds[] = $permission->id;
            }
        }

        Permission::whereNotIn('id', $keptIds)->delete();
    }
}
