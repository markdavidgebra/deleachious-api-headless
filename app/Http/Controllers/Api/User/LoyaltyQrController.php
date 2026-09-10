<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\QrCode;
use Illuminate\Http\Request;

/**
 * Member loyalty QR for in-store staff scan (earn points / Card top-up).
 */
class LoyaltyQrController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $qr = QrCode::getOrCreateForUser($user);

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
