<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\PointItem;
use App\Services\RewardRedemptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RedemptionController extends Controller
{
    public function __construct(
        protected RewardRedemptionService $redemptions,
    ) {}

    public function index(Request $request)
    {
        $items = $request->user()
            ->redemptions()
            ->with('pointItem:id,name,description,type,discount_value,points_required')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $items->each(function ($item) {
            if ($item->status === 'pending') {
                $item->setRelation('qrCode', $this->redemptions->ensureRedemptionQr($item));
            }
            $item->setRelation('reward', $item->pointItem);
        });

        return response()->json([
            'redemptions' => $items,
            'points'      => (int) ($request->user()->points ?? 0),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'point_item_id' => 'required_without:reward_id|exists:point_items,id',
            'reward_id'     => 'required_without:point_item_id|exists:point_items,id',
        ]);

        $item = PointItem::findOrFail($request->point_item_id ?? $request->reward_id);

        try {
            $result = $this->redemptions->request($request->user(), $item);
        } catch (ValidationException $e) {
            $messages = $e->errors();
            $first = collect($messages)->flatten()->first() ?? 'Unable to redeem this item.';

            return response()->json([
                'message'          => $first,
                'errors'           => $messages,
                'points_required'  => (int) $item->points_required,
                'points_available' => (int) ($request->user()->fresh()->points ?? 0),
            ], 422);
        }

        $redemption = $result['redemption']->loadMissing('qrCode');
        $redemption->setRelation('reward', $redemption->pointItem);

        return response()->json([
            'message'       => 'Redemption requested. Show this QR at the counter for staff to scan.',
            'redemption'    => $redemption,
            'qr_code'       => $result['qr_code'] ?? $redemption->qrCode,
            'item'          => $result['item'],
            'reward'        => $result['item'],
            'points_used'   => (int) $result['redemption']->points_used,
            'points_left'   => $result['points_left'],
        ], 201);
    }
}
