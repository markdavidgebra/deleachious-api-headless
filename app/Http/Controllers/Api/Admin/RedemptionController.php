<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redemption;
use Illuminate\Http\Request;

class RedemptionController extends Controller
{
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

        $redemption->update([
            'status'      => $request->status,
            'redeemed_at' => $request->status === 'approved' ? now() : null,
        ]);

        return response()->json([
            'message'    => 'Redemption ' . $request->status,
            'redemption' => $redemption->load(['user', 'reward']),
        ]);
    }
}