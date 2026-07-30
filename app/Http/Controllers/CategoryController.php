<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
     // Return all categories for select
    public function getCategories(Request $request)
    {
        // `kind` (fg|rm|pm) scopes the list to the product kind being categorised;
        // `for_type` accepts a product type (raw_material/cooking_product/...) and
        // resolves the kind itself, so callers can pass whichever they hold.
        $kind = $request->query('kind');
        if (!$kind && $request->filled('for_type')) {
            $kind = Category::kindForProductType($request->query('for_type'));
        }

        $categories = Category::query()
            ->when($request->filled('active'), function ($q) use ($request) {
                $q->where('status', $request->active); // assuming 'status' column
            })
            ->when($kind && in_array($kind, Category::KINDS), fn($q) => $q->where('kind', $kind))
            ->orderBy('name') // sort alphabetically
            ->get(['id', 'name', 'kind']);

        return response()->json($categories);
    }

    // Full list for the Manage Categories tool (name, kind, description, status, product count)
    public function index(Request $request)
    {
        $categories = Category::query()
            ->withCount('posItems')
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            })
            ->when($request->filled('kind'), fn($q) => $q->where('kind', $request->kind))
            ->orderBy('kind')
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'kind'        => 'nullable|in:fg,rm,pm',
            'description' => 'nullable|string',
            'status'      => 'boolean',
        ]);

        try {
            $category = Category::create([
                'name'        => $data['name'],
                'kind'        => $data['kind'] ?? 'fg',
                'description' => $data['description'] ?? null,
                'status'      => $data['status'] ?? true,
                'created_by'  => Auth::user()->username ?? 'System',
            ]);

            return response()->json([
                'success'  => true,
                'message'  => 'Category created successfully',
                'category' => $category,
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'A category with this name already exists',
            ], 422);
        }
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'kind'        => 'nullable|in:fg,rm,pm',
            'description' => 'nullable|string',
            'status'      => 'boolean',
        ]);

        try {
            $category->update([
                'name'        => $data['name'],
                'kind'        => $data['kind'] ?? $category->kind,
                'description' => $data['description'] ?? null,
                'status'      => $data['status'] ?? $category->status,
            ]);

            return response()->json([
                'success'  => true,
                'message'  => 'Category updated successfully',
                'category' => $category,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'A category with this name already exists',
            ], 422);
        }
    }

    public function destroy($id)
    {
        $category = Category::findOrFail($id);

        if (Product::where('category_id', $category->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a category that still has products assigned to it',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }
}
