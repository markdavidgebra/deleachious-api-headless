<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WalletSetting;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

class WalletSettingController extends Controller
{
    // GET /admin/wallet/settings
    public function show()
    {
        return response()->json(WalletSetting::getSettings());
    }

    // PATCH /admin/wallet/settings
    public function update(Request $request)
    {
        $request->validate([
            'max_balance'                 => 'sometimes|numeric|min:0',
            'max_topup'                   => 'sometimes|numeric|min:0',
            'min_topup'                   => 'sometimes|numeric|min:0',
            'daily_topup_limit'           => 'sometimes|numeric|min:0',
            'daily_purchase_limit'        => 'sometimes|numeric|min:0',
            'max_purchase'                => 'sometimes|numeric|min:0',
            'qr_ttl_seconds'              => 'sometimes|integer|min:30|max:600',
            'failed_topup_threshold'      => 'sometimes|integer|min:1',
            'failed_topup_window_minutes' => 'sometimes|integer|min:1',
            'topup_enabled'               => 'sometimes|boolean',
            'purchase_enabled'            => 'sometimes|boolean',
            'refund_enabled'              => 'sometimes|boolean',
            'terms_and_conditions'        => 'sometimes|string',
            'terms_version'               => 'sometimes|string|max:16',
        ]);

        $settings = WalletSetting::getSettings();
        $old      = $settings->toArray();

        $payload = $request->only([
            'max_balance', 'max_topup', 'min_topup',
            'daily_topup_limit', 'daily_purchase_limit', 'max_purchase',
            'qr_ttl_seconds', 'failed_topup_threshold', 'failed_topup_window_minutes',
            'topup_enabled', 'purchase_enabled', 'refund_enabled',
            'terms_and_conditions', 'terms_version',
        ]);

        if (array_key_exists('terms_and_conditions', $payload)
            || array_key_exists('terms_version', $payload)) {
            $payload['terms_updated_at'] = now();
        }

        $settings->update($payload);

        AuditLogService::log(
            'updated',
            'wallet_settings',
            'Wallet settings updated',
            $settings,
            $old,
            $settings->fresh()->toArray(),
        );

        return response()->json($settings->fresh());
    }
}
