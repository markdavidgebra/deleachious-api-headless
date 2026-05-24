<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    // GET all categories
    public function index()
    {
        $categories = Category::withCount('products')
            ->orderBy('sort_order')
            ->get();

        return response()->json($categories);
    }

    // CREATE category
    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|unique:categories',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
            'sort_order'  => 'integer',
        ]);

        $category = Category::create([
            'name'        => $request->name,
            'slug'        => Str::slug($request->name),
            'description' => $request->description,
            'is_active'   => $request->is_active ?? true,
            'sort_order'  => $request->sort_order ?? 0,
        ]);

        return response()->json($category, 201);
    }

    // GET single category
    public function show(Category $category)
    {
        return response()->json($category->load('products'));
    }

    // UPDATE category
    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name'        => 'sometimes|string|unique:categories,name,' . $category->id,
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
            'sort_order'  => 'integer',
        ]);

        $category->update([
            'name'        => $request->name ?? $category->name,
            'slug'        => Str::slug($request->name ?? $category->name),
            'description' => $request->description ?? $category->description,
            'is_active'   => $request->is_active ?? $category->is_active,
            'sort_order'  => $request->sort_order ?? $category->sort_order,
        ]);

        return response()->json($category);
    }

    // DELETE category
    public function destroy(Category $category)
    {
        $category->delete();

        return response()->json(['message' => 'Category deleted']);
    }
}