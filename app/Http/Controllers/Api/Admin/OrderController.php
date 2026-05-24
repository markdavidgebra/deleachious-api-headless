<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyPointSetting;
use Illuminate\Http\Request;
use App\Services\AuditLogService;
class OrderController extends Controller
{
    // GET all orders
    public function index(Request $request)
    {
        $orders = Order::with(['user', 'items.addons', 'transaction'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->type,   fn($q) => $q->where('type',   $request->type))
            ->orderByDesc('created_at')
            ->get();

        return response()->json($orders);
    }

    // CREATE order
    public function store(Request $request)
    {
        $request->validate([
            'user_id'       => 'nullable|exists:users,id',
            'type'          => 'required|in:dine_in,takeout,delivery',
            'notes'         => 'nullable|string',
            'items'         => 'required|array|min:1',
            'items.*.product_id'         => 'required|exists:products,id',
            'items.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity'           => 'required|integer|min:1',
            'items.*.notes'              => 'nullable|string',
            'items.*.addons'             => 'nullable|array',
            'items.*.addons.*.product_addon_id' => 'required|exists:product_addons,id',
        ]);

        // Calculate subtotal
        $subtotal = 0;
        $itemsData = [];

        foreach ($request->items as $item) {
            $product = \App\Models\Product::findOrFail($item['product_id']);
            $variant = isset($item['product_variant_id'])
                ? \App\Models\ProductVariant::find($item['product_variant_id'])
                : null;

            $unitPrice   = $variant ? $variant->price : $product->base_price;
            $itemSubtotal = $unitPrice * $item['quantity'];

            // Calculate addons total
            $addonsData  = [];
            $addonsTotal = 0;

            if (! empty($item['addons'])) {
                foreach ($item['addons'] as $addon) {
                    $addonModel   = \App\Models\ProductAddon::findOrFail($addon['product_addon_id']);
                    $addonsTotal += $addonModel->price;
                    $addonsData[] = [
                        'product_addon_id' => $addonModel->id,
                        'addon_name'       => $addonModel->name,
                        'price'            => $addonModel->price,
                    ];
                }
            }

            $itemSubtotal += $addonsTotal * $item['quantity'];
            $subtotal     += $itemSubtotal;

            $itemsData[] = [
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name'       => $product->name,
                'variant_name'       => $variant?->name,
                'unit_price'         => $unitPrice,
                'quantity'           => $item['quantity'],
                'subtotal'           => $itemSubtotal,
                'notes'              => $item['notes'] ?? null,
                'addons'             => $addonsData,
            ];
        }

        // Calculate points earned
        $settings     = LoyaltyPointSetting::getSettings();
        $pointsEarned = $request->user_id
            ? $settings->calculatePoints($subtotal)
            : 0;

        // Create the order
        $order = Order::create([
            'order_number'  => Order::generateOrderNumber(),
            'user_id'       => $request->user_id,
            'branch_id'     => $request->branch_id,
            'handled_by'    => auth()->id(),
            'type'          => $request->type,
            'status'        => 'pending',
            'subtotal'      => $subtotal,
            'discount'      => 0,
            'total'         => $subtotal,
            'points_earned' => $pointsEarned,
            'notes'         => $request->notes,
        ]);

        // Save items and addons
        foreach ($itemsData as $itemData) {
            $addons = $itemData['addons'];
            unset($itemData['addons']);

            $orderItem = OrderItem::create(array_merge(
                $itemData,
                ['order_id' => $order->id]
            ));

            foreach ($addons as $addon) {
                OrderItemAddon::create(array_merge(
                    $addon,
                    ['order_item_id' => $orderItem->id]
                ));
            }
        }
        AuditLogService::created('order', $order, 'Order created: ' . $order->order_number);
        return response()->json(
            $order->load(['user', 'items.addons', 'transaction']),
            201
        );
    }

    // GET single order
    public function show(Order $order)
    {
        return response()->json(
            $order->load(['user', 'items.addons', 'transaction', 'handledBy'])
        );
    }

    // UPDATE order status
    public function updateStatus(Request $request, Order $order)
    {
        $request->validate([
            'status' => 'required|in:pending,confirmed,preparing,ready,completed,cancelled',
        ]);

        $order->update([
            'status'       => $request->status,
            'completed_at' => $request->status === 'completed' ? now() : null,
        ]);

        AuditLogService::log(
            'updated',
            'order',
            'Order ' . $order->order_number . ' status changed to ' . $request->status,
            $order
        );

        // Award points when order is completed
        if ($request->status === 'completed' && $order->user_id && $order->points_earned > 0) {
            $user = $order->user;

            LoyaltyPoint::create([
                'user_id'        => $user->id,
                'points'         => $order->points_earned,
                'type'           => 'earned',
                'description'    => 'Points earned from Order ' . $order->order_number,
                'reference_type' => Order::class,
                'reference_id'   => $order->id,
            ]);

            $user->increment('points', $order->points_earned);
            $user->updateTier();
        }

        return response()->json([
            'message' => 'Order status updated to ' . $request->status,
            'order'   => $order->load(['user', 'items.addons', 'transaction']),
        ]);
    }

    // CANCEL order
    public function cancel(Order $order)
    {
        if (in_array($order->status, ['completed', 'cancelled'])) {
            return response()->json([
                'message' => 'Cannot cancel a ' . $order->status . ' order.',
            ], 422);
        }

        $order->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Order cancelled successfully']);
    }
}