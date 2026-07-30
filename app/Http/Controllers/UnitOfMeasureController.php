<?php

namespace App\Http\Controllers;

use App\Models\UnitOfMeasure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UnitOfMeasureController extends Controller
{
    // Catalog used to populate the Base Unit / Alternate Unit dropdowns wherever
    // a product's unit is set — not paginated, this list stays small by design.
    public function index()
    {
        return response()->json(
            UnitOfMeasure::where('status', 1)->orderBy('category')->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:20|unique:units_of_measure,code',
            'name' => 'required|string|max:100',
            'category' => 'required|in:weight,volume,count,other',
        ]);

        $unit = UnitOfMeasure::create($data + ['created_by' => Auth::user()->username ?? 'System']);

        return response()->json(['status' => true, 'message' => 'Unit created.', 'unit' => $unit]);
    }
}
