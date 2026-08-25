<?php

namespace App\Http\Controllers\Api\User;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyPointSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductVariant;
use App\Models\Transaction;
use App\Services\OrderPaymentSettlementService;
use App\Services\PayMongoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mobile-app order checkout via PayMongo hosted checkout.
 */
class OrderController extends Controller
{
    public function __construct(
        protected PayMongoService $paymongo,
        protected OrderPaymentSettlementService $settlement,
    ) {}

    // GET /user/orders
    public function index(Request $request)
    {
        $orders = Order::with(['items.addons', 'transaction'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($orders);
    }

    // GET /user/orders/{order}
    public function show(Request $request, Order $order)
    {
        if ((int) $order->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(
            $order->load(['items.addons', 'transaction', 'user'])
        );
    }

    /**
     * Pickup / serve QR for a ready order. Staff scan this to complete it.
     */
    public function pickupQr(Request $request, Order $order)
    {
        if ((int) $order->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->status === 'completed') {
            return response()->json([
                'message' => 'This order is already completed.',
            ], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json([
                'message' => 'This order was cancelled.',
            ], 422);
        }

        if ($order->status !== 'ready') {
            return response()->json([
                'message' => 'Show this QR when your order is ready.',
            ], 422);
        }

        $qr = $order->ensurePickupQr(120);

        return response()->json([
            'qr_code' => [
                'id'         => $qr->id,
                'code'       => $qr->code,
                'type'       => $qr->type,
                'purpose'    => $qr->purpose,
                'is_active'  => $qr->is_active,
                'expires_at' => $qr->expires_at,
            ],
            'order' => $order->only(['id', 'order_number', 'status', 'type', 'total']),
        ]);
    }

    /**
     * Create a pending order + PayMongo checkout session.
     * Client opens checkout_url; payment is confirmed via webhook or confirm endpoint.
     */
    public function checkout(Request $request)
    {
        $request->validate([
            'branch_id'  => 'required|exists:branches,id',
            'type'       => 'nullable|in:dine_in,takeout,delivery',
            'notes'      => 'nullable|string|max:500',
            'channel'    => 'nullable|in:gcash,card,maya,qrph',
            'success_url'=> 'nullable|url|max:500',
            'cancel_url' => 'nullable|url|max:500',
            'items'      => 'required|array|min:1',
            'items.*.product_id'         => 'required|exists:products,id',
            'items.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity'           => 'required|integer|min:1|max:99',
            'items.*.notes'              => 'nullable|string|max:255',
            'items.*.addons'             => 'nullable|array',
            'items.*.addons.*.product_addon_id' => 'required|exists:product_addons,id',
        ]);

        $user    = $request->user();
        $channel = $request->input('channel', 'gcash');

        try {
            $built = $this->buildItemsPayload($request->items);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message'    => $e->getMessage(),
                'error_code' => 'invalid_items',
            ], 422);
        }

        $subtotal     = $built['subtotal'];
        $itemsData    = $built['items'];
        $settings     = LoyaltyPointSetting::getSettings();
        $pointsEarned = $settings->calculatePoints($subtotal);

        $successUrl = $request->input(
            'success_url',
            config('services.paymongo.success_url', 'https://daleachious.app/checkout/success')
        );
        $cancelUrl = $request->input(
            'cancel_url',
            config('services.paymongo.cancel_url', 'https://daleachious.app/checkout/cancel')
        );

        try {
            $result = DB::transaction(function () use (
                $request,
                $user,
                $subtotal,
                $itemsData,
                $pointsEarned,
                $channel,
                $successUrl,
                $cancelUrl,
            ) {
                $order = Order::create([
                    'order_number'  => Order::generateOrderNumber(),
                    'user_id'       => $user->id,
                    'branch_id'     => $request->branch_id,
                    'type'          => $request->input('type', 'takeout'),
                    'status'        => 'pending',
                    'subtotal'      => $subtotal,
                    'discount'      => 0,
                    'total'         => $subtotal,
                    'points_earned' => $pointsEarned,
                    'notes'         => $request->notes,
                ]);

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

                $transaction = Transaction::create([
                    'reference_number' => Transaction::generateReferenceNumber(),
                    'order_id'         => $order->id,
                    'user_id'          => $user->id,
                    'payment_method'   => $channel,
                    'gateway'          => 'paymongo',
                    'status'           => 'pending',
                    'amount'           => $subtotal,
                    'change'           => 0,
                ]);

                $session = $this->paymongo->createOrderCheckout(
                    $transaction->load('order'),
                    $channel,
                    $successUrl,
                    $cancelUrl,
                    $user,
                );

                $transaction->update([
                    'gateway_checkout_id' => $session['id'],
                    'checkout_url'        => $session['checkout_url'],
                ]);

                return [
                    'order'       => $order->fresh()->load(['items.addons', 'transaction']),
                    'transaction' => $transaction->fresh(),
                    'checkout_url'=> $session['checkout_url'],
                ];
            });
        } catch (PaymentException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'message'      => 'Order created. Complete payment to confirm.',
            'order'        => $result['order'],
            'transaction'  => $result['transaction'],
            'checkout_url' => $result['checkout_url'],
        ], 201);
    }

    /**
     * Client poll after returning from PayMongo hosted checkout.
     */
    public function confirm(Request $request, Order $order)
    {
        if ((int) $order->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $transaction = $order->transaction;
        if (! $transaction) {
            return response()->json([
                'message'    => 'No payment found for this order.',
                'error_code' => 'payment_missing',
            ], 404);
        }

        try {
            $result = $this->settlement->settleFromGateway($transaction);
        } catch (PaymentException $e) {
            return $e->toResponse();
        }

        $points = $result['points'];

        return response()->json([
            'paid'                  => (bool) $result['paid'] || ($result['transaction']->status === 'paid'),
            'pending'               => (bool) $result['pending'],
            'order'                 => ($result['order'] ?? $order)->fresh()->load(['items.addons', 'transaction']),
            'transaction'           => $result['transaction'],
            'points_awarded'        => $points ? (int) ($points['points'] ?? 0) : (int) $order->points_earned,
            'points_newly_credited' => $points ? (bool) ($points['awarded'] ?? false) : false,
            'points_total'          => $points['total_points'] ?? null,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float, items: array<int, array<string, mixed>>}
     */
    protected function buildItemsPayload(array $items): array
    {
        $subtotal  = 0.0;
        $itemsData = [];

        foreach ($items as $item) {
            $product = Product::findOrFail($item['product_id']);
            $variant = ! empty($item['product_variant_id'])
                ? ProductVariant::find($item['product_variant_id'])
                : null;

            if ($variant && (int) $variant->product_id !== (int) $product->id) {
                throw new \InvalidArgumentException(
                    "Variant does not belong to product {$product->name}."
                );
            }

            $unitPrice    = $variant ? (float) $variant->price : (float) $product->base_price;
            $itemSubtotal = $unitPrice * (int) $item['quantity'];

            $addonsData  = [];
            $addonsTotal = 0.0;

            if (! empty($item['addons'])) {
                foreach ($item['addons'] as $addon) {
                    $addonModel = ProductAddon::findOrFail($addon['product_addon_id']);
                    if ((int) $addonModel->product_id !== (int) $product->id) {
                        throw new \InvalidArgumentException(
                            "Add-on {$addonModel->name} does not belong to product {$product->name}."
                        );
                    }
                    $addonsTotal += (float) $addonModel->price;
                    $addonsData[] = [
                        'product_addon_id' => $addonModel->id,
                        'addon_name'       => $addonModel->name,
                        'price'            => (float) $addonModel->price,
                    ];
                }
            }

            $itemSubtotal += $addonsTotal * (int) $item['quantity'];
            $subtotal     += $itemSubtotal;

            $itemsData[] = [
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name'       => $product->name,
                'variant_name'       => $variant?->name,
                'unit_price'         => $unitPrice,
                'quantity'           => (int) $item['quantity'],
                'subtotal'           => round($itemSubtotal, 2),
                'notes'              => $item['notes'] ?? null,
                'addons'             => $addonsData,
            ];
        }

        return [
            'subtotal' => round($subtotal, 2),
            'items'    => $itemsData,
        ];
    }
}
