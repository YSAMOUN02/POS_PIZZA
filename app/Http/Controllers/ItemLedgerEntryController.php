<?php

namespace App\Http\Controllers;

use App\Models\ItemLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemLedgerEntryController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 50);

        return $this->filtered($request)
            ->orderByDesc('posting_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Plain CSV export of the WHOLE filtered list (not just the visible page,
     * no styling). Streamed row-by-row with a DB cursor, so it stays memory-safe
     * even on a large ledger.
     */
    public function export(Request $request): StreamedResponse
    {
        // Columns to export, in the same order as the on-screen list.
        $cols = [
            'entry_no', 'posting_date', 'document_type', 'document_no', 'source_no',
            'barcode', 'item_code', 'name', 'variant', 'description', 'unit',
            'category_name', 'warehouse_name', 'bin_name', 'lot', 'expire_date',
            'quantity', 'remaining_quantity', 'entry_type',
            'unit_cost', 'cost_amount', 'unit_price', 'sell_price',
            'discount_percent', 'discount_amount', 'vat', 'vat_amount',
            'line_amount', 'net_amount', 'grand_total_amount',
            'customer_id', 'customer_name', 'customer_phone', 'customer_address',
            'vendor_id', 'vendor_name', 'payment_method', 'created_by', 'created_at',
        ];

        $query = $this->filtered($request)
            ->orderByDesc('posting_date')
            ->orderByDesc('id');

        $filename = 'item_ledger_entries_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query, $cols) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM so Excel reads Khmer/riel
            fputcsv($out, $cols);                              // header row
            foreach ($query->cursor() as $row) {
                fputcsv($out, array_map(fn($c) => $row->{$c}, $cols));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The shared filter set for index() and export() — one place so the exported
     * rows always match exactly what the list is showing.
     */
    private function filtered(Request $request)
    {
        $query = ItemLedgerEntry::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('document_no', 'like', "%{$search}%")
                    ->orWhere('source_no', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('category_name', 'like', "%{$search}%");
            });
        }

        // Cashiers only ever see their own entries.
        $user = Auth::user();
        if ($user && $user->role === 'cashier') {
            $query->where('created_user_id', (string) $user->id);
        }

        if ($request->filled('lot')) {
            $query->where('lot', 'like', "%{$request->lot}%");
        }
        if ($request->filled('warehouse')) {
            $query->where('warehouse_name', 'like', "%{$request->warehouse}%");
        }
        if ($request->filled('type')) {
            $query->where('document_type', $request->type);
        }
        if ($request->filled('from')) {
            $query->whereDate('posting_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('posting_date', '<=', $request->to);
        }

        return $query;
    }
}
