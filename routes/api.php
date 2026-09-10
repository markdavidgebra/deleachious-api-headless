<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\MemberController;
use App\Http\Controllers\Api\Admin\LoyaltyPointSettingController;
use App\Http\Controllers\Api\Admin\RewardController;
use App\Http\Controllers\Api\User\AuthController as UserAuthController;
use App\Http\Controllers\Api\Admin\BranchController;
use App\Http\Controllers\Api\Admin\DeveloperPurgeController;
use App\Http\Controllers\Api\Admin\DeveloperStudioController;
use App\Http\Controllers\Api\Admin\StaffController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\QrController;
use App\Http\Controllers\Api\Admin\NotificationController;
use App\Http\Controllers\Api\User\NotificationController as UserNotificationController;
use App\Http\Controllers\Api\Admin\ShopSettingController;
use App\Http\Controllers\Api\User\RewardController as UserRewardController;
use App\Http\Controllers\Api\User\RedemptionController as UserRedemptionController;
use App\Http\Controllers\Api\User\LoyaltyQrController as UserLoyaltyQrController;
use App\Http\Controllers\Api\User\CardController as UserCardController;
use App\Http\Controllers\Api\Admin\CardTopUpController as AdminCardTopUpController;
use App\Http\Controllers\DeleteAccountController;

// ── Account deletion (Google Play User Data policy) ─────────────────
Route::middleware('auth:sanctum')->delete('/account', [DeleteAccountController::class, 'destroy']);

// ── Admin Routes ──────────────────────────────
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::post('/developer/login', [AdminAuthController::class, 'developerLogin']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::patch('/profile', [AdminAuthController::class, 'updateProfile']);
        Route::post('/change-password', [AdminAuthController::class, 'changePassword']);

        Route::get('shop-settings', [ShopSettingController::class, 'show']);
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('permissions', [RoleController::class, 'permissions']);

        Route::middleware('admin.developer')->group(function () {
            Route::get('studio', [DeveloperStudioController::class, 'show']);
            Route::get('studio/activity', [DeveloperStudioController::class, 'activity']);
            Route::delete('members/all', [DeveloperPurgeController::class, 'members']);
            Route::delete('redemptions/all', [DeveloperPurgeController::class, 'redemptions']);
            Route::delete('points/all', [DeveloperPurgeController::class, 'points']);
            Route::delete('rewards/all', [DeveloperPurgeController::class, 'points']);
            Route::delete('members/{user}', [DeveloperPurgeController::class, 'destroyMember']);
            Route::delete('redemptions/{redemption}', [DeveloperPurgeController::class, 'destroyRedemption']);
        });

        Route::middleware('admin.super')->group(function () {
            Route::post('roles', [RoleController::class, 'store']);
            Route::get('roles/{role}', [RoleController::class, 'show']);
            Route::patch('roles/{role}', [RoleController::class, 'update']);
            Route::delete('roles/{role}', [RoleController::class, 'destroy']);
        });

        Route::middleware('admin.can:members')->group(function () {
            Route::get('members', [MemberController::class, 'index']);
            Route::get('members/{user}', [MemberController::class, 'show']);
            Route::get('members/{user}/points-history', [MemberController::class, 'pointsHistory']);
        });
        Route::post('loyalty-points/{user}/adjust', [LoyaltyPointSettingController::class, 'adjustPoints'])
            ->middleware('admin.can:members.adjust');

        Route::middleware('admin.can:loyalty.points,loyalty.rewards,loyalty.manage')->group(function () {
            Route::get('points', [RewardController::class, 'index']);
            Route::get('points/{pointItem}', [RewardController::class, 'show']);
            Route::get('rewards', [RewardController::class, 'index']);
            Route::get('rewards/{pointItem}', [RewardController::class, 'show']);
        });
        Route::middleware('admin.can:loyalty.manage')->group(function () {
            Route::post('points', [RewardController::class, 'store']);
            Route::patch('points/{pointItem}', [RewardController::class, 'update']);
            Route::put('points/{pointItem}', [RewardController::class, 'update']);
            Route::delete('points/{pointItem}', [RewardController::class, 'destroy']);
            Route::post('rewards', [RewardController::class, 'store']);
            Route::patch('rewards/{pointItem}', [RewardController::class, 'update']);
            Route::put('rewards/{pointItem}', [RewardController::class, 'update']);
            Route::delete('rewards/{pointItem}', [RewardController::class, 'destroy']);
        });
        Route::middleware('admin.can:loyalty.settings')->group(function () {
            Route::get('loyalty-points/settings', [LoyaltyPointSettingController::class, 'getSettings']);
            Route::patch('loyalty-points/settings', [LoyaltyPointSettingController::class, 'updateSettings']);
            Route::post('loyalty-points/preview', [LoyaltyPointSettingController::class, 'previewPoints']);
            Route::post('loyalty-points/expire', [LoyaltyPointSettingController::class, 'expirePoints']);
        });
        Route::get('loyalty-points/{user}/history', [LoyaltyPointSettingController::class, 'pointsHistory'])
            ->middleware('admin.can:members,loyalty');

        Route::get('branches', [BranchController::class, 'index']);
        Route::get('locations', [BranchController::class, 'index']);
        Route::middleware('admin.can:branches')->group(function () {
            Route::get('branches/{branch}', [BranchController::class, 'show']);
            Route::get('branches/{branch}/stats', [BranchController::class, 'stats']);
            Route::get('locations/{branch}', [BranchController::class, 'show']);
            Route::get('locations/{branch}/stats', [BranchController::class, 'stats']);
        });
        Route::post('branches', [BranchController::class, 'store'])
            ->middleware('admin.can:branches.create');
        Route::patch('branches/{branch}', [BranchController::class, 'update'])
            ->middleware('admin.can:branches.update');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])
            ->middleware('admin.can:branches.delete');
        Route::post('locations', [BranchController::class, 'store'])
            ->middleware('admin.can:branches.create');
        Route::patch('locations/{branch}', [BranchController::class, 'update'])
            ->middleware('admin.can:branches.update');
        Route::delete('locations/{branch}', [BranchController::class, 'destroy'])
            ->middleware('admin.can:branches.delete');

        Route::middleware('admin.can:staff')->group(function () {
            Route::get('staff', [StaffController::class, 'index']);
            Route::get('staff/{admin}', [StaffController::class, 'show']);
        });
        Route::post('staff', [StaffController::class, 'store'])
            ->middleware('admin.can:staff.create');
        Route::patch('staff/{admin}', [StaffController::class, 'update'])
            ->middleware('admin.can:staff.update');
        Route::patch('staff/{admin}/toggle-status', [StaffController::class, 'toggleStatus'])
            ->middleware('admin.can:staff.update');
        Route::delete('staff/{admin}', [StaffController::class, 'destroy'])
            ->middleware('admin.can:staff.delete');

        Route::post('qr/scan', [QrController::class, 'scan'])
            ->middleware('admin.can:qr.scan');
        Route::get('qr/lookup', [QrController::class, 'lookup'])
            ->middleware('admin.can:qr.scan');
        Route::get('qr/scans', [QrController::class, 'scanHistory'])
            ->middleware('admin.can:qr.history');

        Route::middleware('admin.can:notifications.view,notifications')->group(function () {
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::get('notifications/{notification}', [NotificationController::class, 'show']);
        });
        Route::post('notifications/send', [NotificationController::class, 'send'])
            ->middleware('admin.can:notifications.send');
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])
            ->middleware('admin.can:notifications.delete');

        Route::middleware('admin.can:settings.general,settings.style')->group(function () {
            Route::patch('shop-settings',         [ShopSettingController::class, 'update']);
            Route::post('shop-settings/logo',     [ShopSettingController::class, 'uploadLogo']);
            Route::delete('shop-settings/logo',   [ShopSettingController::class, 'deleteLogo']);
        });

        Route::middleware('admin.can:card')->group(function () {
            Route::get('card-topups', [AdminCardTopUpController::class, 'index']);
            Route::get('card-topups/{reference}', [AdminCardTopUpController::class, 'show']);
        });
        Route::post('card-topups/{reference}/complete', [AdminCardTopUpController::class, 'complete'])
            ->middleware('admin.can:card.confirm');
        Route::post('card-topups/{reference}/fail', [AdminCardTopUpController::class, 'fail'])
            ->middleware('admin.can:card.confirm');
    });
});

// ── User (Mobile) Routes ──────────────────────────────────
Route::prefix('user')->group(function () {
    Route::post('/register/send-code', [UserAuthController::class, 'sendSignupCode'])
        ->middleware('throttle:6,1');
    Route::post('/register/verify-code', [UserAuthController::class, 'verifySignupCode'])
        ->middleware('throttle:10,1');
    Route::post('/register', [UserAuthController::class, 'register'])
        ->middleware('throttle:6,1');
    Route::post('/login',    [UserAuthController::class, 'login']);
    Route::post('/forgot-password', [UserAuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [UserAuthController::class, 'resetPassword']);

    Route::get('/shop-settings',  fn () => response()->json(\App\Models\ShopSetting::getSettings()));

    $activeLocations = fn () => response()->json(
        \App\Models\Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
                'address',
                'city',
                'phone',
                'email',
                'opening_time',
                'closing_time',
                'latitude',
                'longitude',
            ])
    );
    Route::get('/branches', $activeLocations);
    Route::get('/locations', $activeLocations);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [UserAuthController::class, 'logout']);
        Route::get('/me',      [UserAuthController::class, 'me']);
        Route::patch('/profile', [UserAuthController::class, 'updateProfile']);
        Route::post('/change-password', [UserAuthController::class, 'changePassword']);
        Route::post('/avatar', [UserAuthController::class, 'uploadAvatar']);
        Route::delete('/avatar', [UserAuthController::class, 'deleteAvatar']);

        Route::delete('/account', [DeleteAccountController::class, 'destroy']);

        Route::get('notifications',              [UserNotificationController::class, 'index']);
        Route::post('notifications/fcm-token',   [UserNotificationController::class, 'updateFcmToken']);
        Route::patch('notifications/read-all',   [UserNotificationController::class, 'markAllRead']);
        Route::patch('notifications/{notification}/read', [UserNotificationController::class, 'markRead']);

        Route::get('points', [UserRewardController::class, 'index']);
        Route::get('rewards', [UserRewardController::class, 'index']);
        Route::get('redemptions', [UserRedemptionController::class, 'index']);
        Route::middleware('throttle:redemptions')
            ->post('redemptions', [UserRedemptionController::class, 'store']);
        Route::get('loyalty-qr', [UserLoyaltyQrController::class, 'show']);

        Route::get('card', [UserCardController::class, 'show']);
        Route::post('card/topups', [UserCardController::class, 'storeTopUp']);
        Route::get('card/transactions', [UserCardController::class, 'transactions']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/card', [UserCardController::class, 'show']);
    Route::post('/card/topups', [UserCardController::class, 'storeTopUp']);
    Route::get('/card/transactions', [UserCardController::class, 'transactions']);
});
