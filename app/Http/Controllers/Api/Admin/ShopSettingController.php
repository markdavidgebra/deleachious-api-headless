<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopSetting;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ShopSettingController extends Controller
{
    // GET /admin/shop-settings
    public function show()
    {
        return response()->json(ShopSetting::getSettings());
    }

    // PATCH /admin/shop-settings
    public function update(Request $request)
    {
        $data = $request->validate([
            'shop_name'    => 'sometimes|string|max:120',
            'tagline'      => 'sometimes|nullable|string|max:255',
            'address'      => 'sometimes|nullable|string|max:255',
            'city'         => 'sometimes|nullable|string|max:120',
            'phone'        => 'sometimes|nullable|string|max:60',
            'email'        => 'sometimes|nullable|email|max:120',
            'opening_time' => 'sometimes|nullable|date_format:H:i',
            'closing_time' => 'sometimes|nullable|date_format:H:i',
            'currency'     => 'sometimes|string|max:8',
            'timezone'     => 'sometimes|string|max:64',

            // Style settings (Settings → Style tab)
            'font_family'  => 'sometimes|string|max:64',
            'sidebar_bg'   => 'sometimes|string|max:32',
            'header_bg'    => 'sometimes|string|max:32',
            'content_bg'   => 'sometimes|string|max:32',
            'theme_mode'   => 'sometimes|in:lighter,darker',
            'nav_layout'   => 'sometimes|in:sidebar,header',
        ]);

        $settings = ShopSetting::getSettings();
        $old      = $settings->toArray();

        $settings->update($data);

        AuditLogService::updated('settings', $settings, $old, 'Shop settings updated');

        return response()->json([
            'message'  => 'Shop settings updated successfully',
            'settings' => $settings->fresh(),
        ]);
    }

    // POST /admin/shop-settings/logo  (multipart form-data, field: "logo")
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,jpg,png,webp,svg|max:2048',
        ]);

        $settings = ShopSetting::getSettings();

        // Remove the old logo, if any, so we don't accumulate orphaned files
        if ($settings->logo_path && Storage::disk('public')->exists($settings->logo_path)) {
            Storage::disk('public')->delete($settings->logo_path);
        }

        $path = $request->file('logo')->store('logos', 'public');
        $settings->update(['logo_path' => $path]);

        AuditLogService::log('updated', 'settings', 'Shop logo updated', $settings);

        return response()->json([
            'message'  => 'Logo uploaded successfully',
            'settings' => $settings->fresh(),
        ]);
    }

    // DELETE /admin/shop-settings/logo
    public function deleteLogo()
    {
        $settings = ShopSetting::getSettings();

        if ($settings->logo_path) {
            if (Storage::disk('public')->exists($settings->logo_path)) {
                Storage::disk('public')->delete($settings->logo_path);
            }
            $settings->update(['logo_path' => null]);

            AuditLogService::log('deleted', 'settings', 'Shop logo removed', $settings);
        }

        return response()->json([
            'message'  => 'Logo removed successfully',
            'settings' => $settings->fresh(),
        ]);
    }
}
