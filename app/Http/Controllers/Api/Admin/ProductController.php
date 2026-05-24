<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductAddon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\AuditLogService;

class ProductController extends Controller
{
    // GET all products
    public function index()
    {
        $products = Product::with(['category', 'variants', 'addons'])
            ->orderBy('sort_order')
            ->get();

        return response()->json($products);
    }

    // CREATE product (supports multipart/form-data when an image is attached)
    public function store(Request $request)
    {
        $request->validate([
            'category_id'      => 'required|exists:categories,id',
            'name'             => 'required|string|unique:products',
            'description'      => 'nullable|string',
            'base_price'       => 'required|numeric|min:0',
            'is_available'     => 'boolean',
            'is_featured'      => 'boolean',
            'image'            => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'variants'         => 'nullable|array',
            'variants.*.name'  => 'required_with:variants|string|max:255',
            'variants.*.price' => 'required_with:variants|numeric|min:0',
            'addons'           => 'nullable|array',
            'addons.*.name'    => 'required_with:addons|string|max:255',
            'addons.*.price'   => 'required_with:addons|numeric|min:0',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product = Product::create([
            'category_id'  => $request->category_id,
            'name'         => $request->name,
            'slug'         => Str::slug($request->name),
            'description'  => $request->description,
            'image'        => $imagePath,
            'base_price'   => $request->base_price,
            'is_available' => $request->boolean('is_available', true),
            'is_featured'  => $request->boolean('is_featured', false),
        ]);

        // Save variants if provided
        if ($request->variants) {
            foreach ($request->variants as $variant) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'name'       => $variant['name'],
                    'price'      => $variant['price'],
                ]);
            }
        }

        // Save add-ons if provided
        if ($request->addons) {
            foreach ($request->addons as $addon) {
                ProductAddon::create([
                    'product_id' => $product->id,
                    'name'       => $addon['name'],
                    'price'      => $addon['price'],
                ]);
            }
        }
        AuditLogService::created('product', $product, 'Product created: ' . $product->name);
        return response()->json($product->load(['category', 'variants', 'addons']), 201);
    }

    // GET single product
    public function show(Product $product)
    {
        return response()->json($product->load(['category', 'variants', 'addons']));
    }

    // UPDATE product (supports multipart via POST with _method=PUT)
    public function update(Request $request, Product $product)
    {
        $request->validate([
            'category_id'      => 'sometimes|exists:categories,id',
            'name'             => 'sometimes|string|unique:products,name,' . $product->id,
            'description'      => 'nullable|string',
            'base_price'       => 'sometimes|numeric|min:0',
            'is_available'     => 'boolean',
            'is_featured'      => 'boolean',
            'image'            => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'remove_image'     => 'sometimes|boolean',
            'variants'         => 'nullable|array',
            'variants.*.name'  => 'required_with:variants|string|max:255',
            'variants.*.price' => 'required_with:variants|numeric|min:0',
            'addons'           => 'nullable|array',
            'addons.*.name'    => 'required_with:addons|string|max:255',
            'addons.*.price'   => 'required_with:addons|numeric|min:0',
        ]);

        $oldValues = $product->toArray();

        $updateData = $request->only([
            'category_id',
            'name',
            'description',
            'base_price',
            'is_available',
            'is_featured',
        ]);

        // Handle image: replace or remove
        if ($request->hasFile('image')) {
            if ($product->image && Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }
            $updateData['image'] = $request->file('image')->store('products', 'public');
        } elseif ($request->boolean('remove_image') && $product->image) {
            if (Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }
            $updateData['image'] = null;
        }

        if ($request->filled('name')) {
            $updateData['slug'] = Str::slug($request->name);
        }

        $product->update($updateData);

        // Sync variants / addons. The admin form always submits the full,
        // current list, so the simplest correct behaviour is "replace all".
        // Only touch the relations when the keys are explicitly present in
        // the request (so partial updates that omit them are non-destructive).
        if ($request->has('variants')) {
            $product->variants()->delete();
            foreach ((array) $request->input('variants', []) as $variant) {
                if (! empty($variant['name']) && isset($variant['price'])) {
                    ProductVariant::create([
                        'product_id' => $product->id,
                        'name'       => $variant['name'],
                        'price'      => $variant['price'],
                    ]);
                }
            }
        }

        if ($request->has('addons')) {
            $product->addons()->delete();
            foreach ((array) $request->input('addons', []) as $addon) {
                if (! empty($addon['name']) && isset($addon['price'])) {
                    ProductAddon::create([
                        'product_id' => $product->id,
                        'name'       => $addon['name'],
                        'price'      => $addon['price'],
                    ]);
                }
            }
        }

        AuditLogService::updated('product', $product, $oldValues, 'Product updated: ' . $product->name);

        return response()->json($product->load(['category', 'variants', 'addons']));
    }

    // DELETE product
    public function destroy(Product $product)
    {
        AuditLogService::deleted('product', $product, 'Product deleted: ' . $product->name);

        // Clean up image file if any
        if ($product->image && Storage::disk('public')->exists($product->image)) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted']);
    }
}
