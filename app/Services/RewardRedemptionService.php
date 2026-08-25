<?php

namespace App\Services;

use App\Models\LoyaltyPoint;
use App\Models\QrCode;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Member reward redemption: hold points on request (pending), fulfill on approve,
 * refund on reject. In-store QR redeem can still create an approved redemption.
 */
class RewardRedemptionService
{
    /**
     * Request a redemption for a member. Deducts points immediately and
     * creates a pending redemption for staff to fulfill/approve.
     *
     * @return array{redemption: Redemption, points_left: int, reward: Reward}
     */
    public function request(User $user, Reward $reward): array
    {
        $this->assertRewardRedeemable($reward);

        return DB::transaction(function () use ($user, $reward) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $reward = Reward::query()->lockForUpdate()->findOrFail($reward->id);

            $this->assertRewardRedeemable($reward);

            if ((int) $locked->points < (int) $reward->points_required) {
                throw ValidationException::withMessages([
                    'reward_id' => ['Not enough points to redeem this reward.'],
                ]);
            }

            $pointsUsed = (int) $reward->points_required;

            LoyaltyPoint::create([
                'user_id'     => $locked->id,
                'points'      => -$pointsUsed,
                'type'        => 'redeemed',
                'description' => 'Redeemed: '.$reward->name,
            ]);

            $locked->decrement('points', $pointsUsed);

            $redemption = Redemption::create([
                'user_id'     => $locked->id,
                'reward_id'   => $reward->id,
                'points_used' => $pointsUsed,
                'status'      => 'pending',
                'redeemed_at' => null,
            ]);

            $qr = $this->createRedemptionQr($redemption);
            $redemption->setRelation('qrCode', $qr);

            return [
                'redemption'  => $redemption->load('reward'),
                'qr_code'     => $qr,
                'points_left' => (int) $locked->fresh()->points,
                'reward'      => $reward,
            ];
        });
    }

    /**
     * Instant in-store redeem (staff QR scan). Deducts points and marks approved.
     *
     * @return array{redemption: Redemption, points_left: int, reward: Reward}
     */
    public function redeemApproved(User $user, Reward $reward): array
    {
        $this->assertRewardRedeemable($reward);

        return DB::transaction(function () use ($user, $reward) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $reward = Reward::query()->lockForUpdate()->findOrFail($reward->id);

            $this->assertRewardRedeemable($reward);

            if ((int) $locked->points < (int) $reward->points_required) {
                throw ValidationException::withMessages([
                    'reward_id' => ['Not enough points to redeem this reward.'],
                ]);
            }

            $pointsUsed = (int) $reward->points_required;

            LoyaltyPoint::create([
                'user_id'     => $locked->id,
                'points'      => -$pointsUsed,
                'type'        => 'redeemed',
                'description' => 'Redeemed: '.$reward->name,
            ]);

            $locked->decrement('points', $pointsUsed);

            $redemption = Redemption::create([
                'user_id'     => $locked->id,
                'reward_id'   => $reward->id,
                'points_used' => $pointsUsed,
                'status'      => 'approved',
                'redeemed_at' => now(),
            ]);

            return [
                'redemption'  => $redemption->load('reward'),
                'points_left' => (int) $locked->fresh()->points,
                'reward'      => $reward,
            ];
        });
    }

    /**
     * Approve a pending redemption (points already held).
     */
    public function approve(Redemption $redemption): Redemption
    {
        return DB::transaction(function () use ($redemption) {
            /** @var Redemption $locked */
            $locked = Redemption::query()->lockForUpdate()->findOrFail($redemption->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['Only pending redemptions can be approved.'],
                ]);
            }

            $locked->update([
                'status'      => 'approved',
                'redeemed_at' => now(),
            ]);

            $this->deactivateRedemptionQrs($locked);

            return $locked->fresh()->load(['user', 'reward']);
        });
    }

    /**
     * Reject a pending redemption and refund held points.
     */
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

            return $locked->fresh()->load(['user', 'reward']);
        });
    }

    /**
     * One-time QR staff scan at the counter to approve this pending request.
     */
    public function ensureRedemptionQr(Redemption $redemption): ?QrCode
    {
        if ($redemption->status !== 'pending') {
            return $redemption->qrCode;
        }

        $existing = QrCode::query()
            ->where('qrable_type', Redemption::class)
            ->where('qrable_id', $redemption->id)
            ->where('purpose', 'reward_redemption')
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
            'purpose'     => 'reward_redemption',
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

    protected function assertRewardRedeemable(Reward $reward): void
    {
        if (! $reward->is_active) {
            throw ValidationException::withMessages([
                'reward_id' => ['This reward is not available.'],
            ]);
        }

        if ($reward->expires_at && now()->startOfDay()->gt($reward->expires_at->copy()->startOfDay())) {
            throw ValidationException::withMessages([
                'reward_id' => ['This reward has expired.'],
            ]);
        }
    }
}
