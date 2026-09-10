<?php

namespace App\Services;

use App\Models\LoyaltyPoint;
use App\Models\PointItem;
use App\Models\QrCode;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Member point redemption: hold points on request, then fulfill at QR scan.
 * The persisted "approved" status is retained for database compatibility.
 */
class RewardRedemptionService
{
    /**
     * @return array{redemption: Redemption, points_left: int, item: PointItem}
     */
    public function request(User $user, PointItem $item): array
    {
        $this->assertItemRedeemable($item);

        return DB::transaction(function () use ($user, $item) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $item = PointItem::query()->lockForUpdate()->findOrFail($item->id);

            $this->assertItemRedeemable($item);

            if ((int) $locked->points < (int) $item->points_required) {
                throw ValidationException::withMessages([
                    'point_item_id' => ['Not enough points to redeem this item.'],
                ]);
            }

            $pointsUsed = (int) $item->points_required;

            LoyaltyPoint::create([
                'user_id'     => $locked->id,
                'points'      => -$pointsUsed,
                'type'        => 'redeemed',
                'description' => 'Redeemed: '.$item->name,
            ]);

            $locked->decrement('points', $pointsUsed);

            $redemption = Redemption::create([
                'user_id'       => $locked->id,
                'point_item_id' => $item->id,
                'points_used'   => $pointsUsed,
                'status'        => 'pending',
                'redeemed_at'   => null,
            ]);

            $qr = $this->createRedemptionQr($redemption);
            $redemption->setRelation('qrCode', $qr);

            return [
                'redemption'  => $redemption->load('pointItem'),
                'qr_code'     => $qr,
                'points_left' => (int) $locked->fresh()->points,
                'item'        => $item,
                'reward'      => $item,
            ];
        });
    }

    /**
     * @return array{redemption: Redemption, points_left: int, item: PointItem}
     */
    public function redeemApproved(User $user, PointItem $item): array
    {
        $this->assertItemRedeemable($item);

        return DB::transaction(function () use ($user, $item) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $item = PointItem::query()->lockForUpdate()->findOrFail($item->id);

            $this->assertItemRedeemable($item);

            if ((int) $locked->points < (int) $item->points_required) {
                throw ValidationException::withMessages([
                    'point_item_id' => ['Not enough points to redeem this item.'],
                ]);
            }

            $pointsUsed = (int) $item->points_required;

            LoyaltyPoint::create([
                'user_id'     => $locked->id,
                'points'      => -$pointsUsed,
                'type'        => 'redeemed',
                'description' => 'Redeemed: '.$item->name,
            ]);

            $locked->decrement('points', $pointsUsed);

            $redemption = Redemption::create([
                'user_id'       => $locked->id,
                'point_item_id' => $item->id,
                'points_used'   => $pointsUsed,
                'status'        => 'approved',
                'redeemed_at'   => now(),
            ]);

            return [
                'redemption'  => $redemption->load('pointItem'),
                'points_left' => (int) $locked->fresh()->points,
                'item'        => $item,
                'reward'      => $item,
            ];
        });
    }

    public function fulfill(Redemption $redemption): Redemption
    {
        return DB::transaction(function () use ($redemption) {
            /** @var Redemption $locked */
            $locked = Redemption::query()->lockForUpdate()->findOrFail($redemption->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['Only pending redemptions can be fulfilled.'],
                ]);
            }

            $locked->update([
                'status'      => 'approved',
                'redeemed_at' => now(),
            ]);

            $this->deactivateRedemptionQrs($locked);

            return $locked->fresh()->load(['user', 'pointItem']);
        });
    }

    public function reject(Redemption $redemption): Redemption
    {
        return DB::transaction(function () use ($redemption) {
            /** @var Redemption $locked */
            $locked = Redemption::query()->lockForUpdate()->findOrFail($redemption->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['Only pending redemptions can be rejected.'],
                ]);
            }

            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($locked->user_id);
            $points = (int) $locked->points_used;

            if ($points > 0) {
                LoyaltyPoint::create([
                    'user_id'     => $user->id,
                    'points'      => $points,
                    'type'        => 'adjustment',
                    'description' => 'Refund for rejected redemption #'.$locked->id,
                ]);
                $user->increment('points', $points);
            }

            $locked->update([
                'status'      => 'rejected',
                'redeemed_at' => null,
            ]);

            $this->deactivateRedemptionQrs($locked);

            return $locked->fresh()->load(['user', 'pointItem']);
        });
    }

    public function ensureRedemptionQr(Redemption $redemption): ?QrCode
    {
        if ($redemption->status !== 'pending') {
            return $redemption->qrCode;
        }

        $existing = QrCode::query()
            ->where('qrable_type', Redemption::class)
            ->where('qrable_id', $redemption->id)
            ->whereIn('purpose', ['point_redemption', 'reward_redemption'])
            ->latest('id')
            ->first();

        if ($existing && $existing->isValid()) {
            return $existing;
        }

        if ($existing) {
            $existing->update(['is_active' => false]);
        }

        return $this->createRedemptionQr($redemption);
    }

    protected function createRedemptionQr(Redemption $redemption): QrCode
    {
        return QrCode::create([
            'code'        => QrCode::generateCode(),
            'type'        => 'redemption',
            'qrable_type' => Redemption::class,
            'qrable_id'   => $redemption->id,
            'purpose'     => 'point_redemption',
            'is_active'   => true,
            'max_scans'   => 1,
            'expires_at'  => now()->addHours(24),
        ]);
    }

    protected function deactivateRedemptionQrs(Redemption $redemption): void
    {
        QrCode::query()
            ->where('qrable_type', Redemption::class)
            ->where('qrable_id', $redemption->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    protected function assertItemRedeemable(PointItem $item): void
    {
        if (! $item->is_active) {
            throw ValidationException::withMessages([
                'point_item_id' => ['This item is not available.'],
            ]);
        }

        if ($item->expires_at && now()->startOfDay()->gt($item->expires_at->copy()->startOfDay())) {
            throw ValidationException::withMessages([
                'point_item_id' => ['This item has expired.'],
            ]);
        }
    }
}
