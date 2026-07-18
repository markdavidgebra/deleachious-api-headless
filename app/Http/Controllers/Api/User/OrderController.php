<?php

namespace App\Http\Controllers\Api\User;

use App\Exceptions\WalletException;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyPointSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductVariant;
use App\Models\Transaction;
use App\Services\LoyaltyPointsService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mobile-app order checkout. Payment is wallet-only (closed-loop).
 */
class OrderController extends Controller
{
    public function __construct(
        protected WalletService $wallet,
        protected LoyaltyPointsService $loyaltyPoints,
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

    // POST /user/orders/checkout
    /**
     * Create an order and pay it immediately from the user's wallet.
     * No other payment methods are accepted on this endpoint.
     */
    public function checkout(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'type'      => 'nullable|in:dine_in,takeout,delivery',
            'notes'     => 'nullable|string|max:500',
            'items'     => 'required|array|min:1',
            'items.*.product_id'         => 'required|exists:products,id',
            'items.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity'           => 'required|integer|min:1|max:99',
            'items.*.notes'              => 'nullable|string|max:255',
            'items.*.addons'             => 'nullable|array',
            'items.*.addons.*.product_addon_id' => 'required|exists:product_addons,id',
        ]);

        $user = $request->user();

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

        try {
            $result = DB::transaction(function () use ($request, $user, $subtotal, $itemsData, $pointsEarned) {
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

                // Debit closed-loop wallet — only payment mode for app checkout.
                $walletResult = $this->wallet->debitPurchase($user, [
                    'amount'          => $subtotal,
                    'branch_id'       => $request->branch_id,
                    'order_id'        => $order->id,
                    'idempotency_key' => $request->header('Idempotency-Key'),
                    'description'     => 'Order '.$order->order_number,
                    'metadata'        => [
                        'ip'         => $request->ip(),
                        'user_agent' => substr((string) $request->userAgent(), 0, 255),
                        'source'     => 'app_checkout',
                    ],
                ]);

                $transaction = Transaction::create([
                    'reference_number' => Transaction::generateReferenceNumber(),
                    'order_id'         => $order->id,
                    'user_id'          => $user->id,
                    'payment_method'   => 'wallet',
                    'status'           => 'paid',
                    'amount'           => $subtotal,
                    'change'           => 0,
                    'paid_at'          => now(),
                ]);

                $order->update(['status' => 'confirmed']);

                return [
                    'order'              => $order->fresh()->load(['items.addons', 'transaction']),
                    'transaction'        => $transaction,
                    'wallet_transaction' => $walletResult['transaction'],
                    'purchase'           => $walletResult['purchase'],
                    'receipt'            => $this->wallet->buildReceipt($walletResult['transaction']),
                ];
            });
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        // Credit rewards after payment succeeds (outside the wallet txn).
        $pointsAward = $this->loyaltyPoints->awardForOrder($result['order']);

        return response()->json([
            'message'            => 'Order placed and paid with wallet.',
            'order'              => $result['order']->fresh()->load(['items.addons', 'transaction']),
            'transaction'        => $result['transaction'],
            'wallet_transaction' => $result['wallet_transaction'],
            'purchase'           => $result['purchase'],
            'receipt'            => $result['receipt'],
            // Always report the order's points amount for UI; `awarded` is whether
            // this request newly credited the member (idempotent on retries).
            'points_awarded'     => (int) $pointsAward['points'],
            'points_newly_credited' => (bool) $pointsAward['awarded'],
            'points_total'       => $pointsAward['total_points'],
        ], 201);
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
