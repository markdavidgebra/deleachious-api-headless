<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductAddon;
use App\Services\ProductRewardSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\AuditLogService;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductRewardSyncService $productRewards
    ) {}

    // GET products. Paginate when `page` or `per_page` is sent (admin list).
    // The public /user/products menu still receives the full catalog.
    public function index(Request $request)
    {
        $query = Product::with(['category', 'variants', 'addons', 'reward'])
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min(max($request->integer('per_page', 25), 1), 100);

            return response()->json($query->paginate($perPage)->withQueryString());
        }

        return response()->json($query->get());
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
            'is_redeemable'    => 'boolean',
            'points_required'  => 'nullable|integer|min:1|required_if:is_redeemable,true,1',
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

        $isRedeemable = $request->boolean('is_redeemable', false);

        $product = Product::create([
            'category_id'     => $request->category_id,
            'name'            => $request->name,
            'slug'            => Str::slug($request->name),
            'description'     => $request->description,
            'image'           => $imagePath,
            'base_price'      => $request->base_price,
            'is_available'    => $request->boolean('is_available', true),
            'is_featured'     => $request->boolean('is_featured', false),
            'is_redeemable'   => $isRedeemable,
            'points_required' => $isRedeemable ? (int) $request->points_required : null,
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

        $this->productRewards->sync($product);

        AuditLogService::created('product', $product, 'Product created: ' . $product->name);
        return response()->json($product->load(['category', 'variants', 'addons', 'reward']), 201);
    }

    // GET single product
    public function show(Product $product)
    {
        return response()->json($product->load(['category', 'variants', 'addons', 'reward']));
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
            'is_redeemable'    => 'boolean',
            'points_required'  => 'nullable|integer|min:1|required_if:is_redeemable,true,1',
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

        if ($request->has('is_redeemable')) {
            $isRedeemable = $request->boolean('is_redeemable');
            $updateData['is_redeemable'] = $isRedeemable;
            $updateData['points_required'] = $isRedeemable
                ? (int) $request->input('points_required')
                : null;
        } elseif ($request->has('points_required') && $product->is_redeemable) {
            $updateData['points_required'] = (int) $request->input('points_required');
        }

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

        $this->productRewards->sync($product->fresh());

        AuditLogService::updated('product', $product, $oldValues, 'Product updated: ' . $product->name);

        return response()->json($product->load(['category', 'variants', 'addons', 'reward']));
    }

    // DELETE product
    public function destroy(Product $product)
    {
        AuditLogService::deleted('product', $product, 'Product deleted: ' . $product->name);

        // Clean up image file if any
        if ($product->image && Storage::disk('public')->exists($product->image)) {
            Storage::disk('public')->delete($product->image);
        }

        // Linked reward loses product_id via nullOnDelete; deactivate explicitly.
        if ($reward = $product->reward) {
            $reward->update(['is_active' => false]);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted']);
    }
}
