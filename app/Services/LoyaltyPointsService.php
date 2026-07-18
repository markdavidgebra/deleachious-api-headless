<?php

namespace App\Services;

use App\Models\LoyaltyPoint;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Credits loyalty points for paid orders. Idempotent per order so checkout
 * and admin "completed" can both call it without double-awarding.
 */
class LoyaltyPointsService
{
    /**
     * Award points_earned from an order to its member, if not already awarded.
     *
     * @return array{awarded: bool, points: int, total_points: int|null}
     */
    public function awardForOrder(Order $order): array
    {
        if (! $order->user_id || (int) $order->points_earned <= 0) {
            return [
                'awarded'      => false,
                'points'       => 0,
                'total_points' => $order->user?->points,
            ];
        }

        return DB::transaction(function () use ($order) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            $alreadyAwarded = LoyaltyPoint::query()
                ->where('reference_type', Order::class)
                ->where('reference_id', $order->id)
                ->where('type', 'earned')
                ->exists();

            if ($alreadyAwarded) {
                $user = User::find($order->user_id);

                return [
                    'awarded'      => false,
                    'points'       => (int) $order->points_earned,
                    'total_points' => $user?->points,
                ];
            }

            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($order->user_id);

            LoyaltyPoint::create([
                'user_id'        => $user->id,
                'points'         => (int) $order->points_earned,
                'type'           => 'earned',
                'description'    => 'Points earned from Order '.$order->order_number,
                'reference_type' => Order::class,
                'reference_id'   => $order->id,
            ]);

            $user->increment('points', (int) $order->points_earned);
            $user->refresh();

            if (method_exists($user, 'updateTier')) {
                $user->updateTier();
            }

            return [
                'awarded'      => true,
                'points'       => (int) $order->points_earned,
                'total_points' => (int) $user->points,
            ];
        });
    }
}
