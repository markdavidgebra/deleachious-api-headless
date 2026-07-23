<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redemption;
use App\Services\RewardRedemptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RedemptionController extends Controller
{
    public function __construct(
        protected RewardRedemptionService $redemptions,
    ) {}

    // GET all redemptions
    public function index()
    {
        $redemptions = Redemption::with(['user', 'reward'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($redemptions);
    }

    // APPROVE or REJECT a redemption
    public function updateStatus(Request $request, Redemption $redemption)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        try {
            $updated = $request->status === 'approved'
                ? $this->redemptions->approve($redemption)
                : $this->redemptions->reject($redemption);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first() ?? 'Unable to update redemption.';

            return response()->json([
                'message' => $first,
                'errors'  => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message'    => 'Redemption '.$request->status,
            'redemption' => $updated,
        ]);
    }
}
