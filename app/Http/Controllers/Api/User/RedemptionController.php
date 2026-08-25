<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Services\RewardRedemptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RedemptionController extends Controller
{
    public function __construct(
        protected RewardRedemptionService $redemptions,
    ) {}

    /**
     * Member's redemption history (newest first).
     */
    public function index(Request $request)
    {
        $items = $request->user()
            ->redemptions()
            ->with('reward:id,name,description,type,discount_value,points_required')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $items->each(function ($item) {
            if ($item->status === 'pending') {
                $item->setRelation('qrCode', $this->redemptions->ensureRedemptionQr($item));
            }
        });

        return response()->json([
            'redemptions' => $items,
            'points'      => (int) ($request->user()->points ?? 0),
        ]);
    }

    /**
     * Request a reward redemption. Points are held immediately; status is pending
     * until staff approves fulfillment in admin.
     */
    public function store(Request $request)
    {
        $request->validate([
            'reward_id' => 'required|exists:rewards,id',
        ]);

        $reward = Reward::findOrFail($request->reward_id);

        try {
            $result = $this->redemptions->request($request->user(), $reward);
        } catch (ValidationException $e) {
            $messages = $e->errors();
            $first = collect($messages)->flatten()->first() ?? 'Unable to redeem reward.';

            return response()->json([
                'message'          => $first,
                'errors'           => $messages,
                'points_required'  => (int) $reward->points_required,
                'points_available' => (int) ($request->user()->fresh()->points ?? 0),
            ], 422);
        }

        return response()->json([
            'message'     => 'Redemption requested. Show this QR at the counter for staff to scan.',
            'redemption'  => $result['redemption']->loadMissing('qrCode'),
            'qr_code'     => $result['qr_code'] ?? $result['redemption']->qrCode,
            'reward'      => $result['reward'],
            'points_used' => (int) $result['redemption']->points_used,
            'points_left' => $result['points_left'],
        ], 201);
    }
}
