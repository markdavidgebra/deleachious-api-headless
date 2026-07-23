<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Member loyalty QR for in-store staff scan (earn / redeem_reward).
 */
class LoyaltyQrController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        $qr = QrCode::query()
            ->where('qrable_type', User::class)
            ->where('qrable_id', $user->id)
            ->where('type', 'user')
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (! $qr || ! $qr->isValid()) {
            $qr = QrCode::create([
                'code'        => QrCode::generateCode(),
                'type'        => 'user',
                'qrable_type' => User::class,
                'qrable_id'   => $user->id,
                'purpose'     => 'user_loyalty',
                'is_active'   => true,
                'max_scans'   => null,
                'expires_at'  => null,
            ]);
        }

        return response()->json([
            'qr_code' => [
                'id'         => $qr->id,
                'code'       => $qr->code,
                'type'       => $qr->type,
                'purpose'    => $qr->purpose,
                'is_active'  => $qr->is_active,
                'expires_at' => $qr->expires_at,
            ],
            'user' => $user->only(['id', 'name', 'email', 'points']),
        ]);
    }
}
