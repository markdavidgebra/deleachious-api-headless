<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\MemberController;
use App\Http\Controllers\Api\Admin\LoyaltyPointSettingController;
use App\Http\Controllers\Api\Admin\RewardController;
use App\Http\Controllers\Api\Admin\RedemptionController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\TransactionController;
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
use App\Http\Controllers\Api\User\OrderController as UserOrderController;
use App\Http\Controllers\Api\User\RewardController as UserRewardController;
use App\Http\Controllers\Api\User\RedemptionController as UserRedemptionController;
use App\Http\Controllers\Api\User\LoyaltyQrController as UserLoyaltyQrController;
use App\Http\Controllers\Api\Webhook\PaymongoWebhookController;
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
            Route::get('studio/payments', [DeveloperStudioController::class, 'payments']);
            Route::post('studio/payments/{transaction}/recheck', [DeveloperStudioController::class, 'recheck']);
            Route::delete('orders/all', [DeveloperPurgeController::class, 'orders']);
            Route::delete('transactions/all', [DeveloperPurgeController::class, 'transactions']);
            Route::delete('members/all', [DeveloperPurgeController::class, 'members']);
            Route::delete('redemptions/all', [DeveloperPurgeController::class, 'redemptions']);
            Route::delete('rewards/all', [DeveloperPurgeController::class, 'rewards']);
            Route::delete('orders/{order}', [DeveloperPurgeController::class, 'destroyOrder']);
            Route::delete('transactions/{transaction}', [DeveloperPurgeController::class, 'destroyTransaction']);
            Route::delete('members/{user}', [DeveloperPurgeController::class, 'destroyMember']);
            Route::delete('redemptions/{redemption}', [DeveloperPurgeController::class, 'destroyRedemption']);
        });

        Route::middleware('admin.super')->group(function () {
            Route::post('roles', [RoleController::class, 'store']);
            Route::get('roles/{role}', [RoleController::class, 'show']);
            Route::patch('roles/{role}', [RoleController::class, 'update']);
            Route::delete('roles/{role}', [RoleController::class, 'destroy']);
        });

        Route::middleware('admin.can:products')->group(function () {
            Route::get('categories', [CategoryController::class, 'index']);
            Route::get('categories/{category}', [CategoryController::class, 'show']);
            Route::get('products', [ProductController::class, 'index']);
            Route::get('products/{product}', [ProductController::class, 'show']);
        });
        Route::middleware('admin.can:products.create')->group(function () {
            Route::post('categories', [CategoryController::class, 'store']);
            Route::post('products', [ProductController::class, 'store']);
        });
        Route::middleware('admin.can:products.update')->group(function () {
            Route::patch('categories/{category}', [CategoryController::class, 'update']);
            Route::put('categories/{category}', [CategoryController::class, 'update']);
            Route::patch('products/{product}', [ProductController::class, 'update']);
            Route::put('products/{product}', [ProductController::class, 'update']);
        });
        Route::middleware('admin.can:products.delete')->group(function () {
            Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
            Route::delete('products/{product}', [ProductController::class, 'destroy']);
        });

        Route::middleware('admin.can:members')->group(function () {
            Route::get('members', [MemberController::class, 'index']);
            Route::get('members/{user}', [MemberController::class, 'show']);
            Route::get('members/{user}/points-history', [MemberController::class, 'pointsHistory']);
        });
        Route::post('loyalty-points/{user}/adjust', [LoyaltyPointSettingController::class, 'adjustPoints'])
            ->middleware('admin.can:members.adjust');

        Route::middleware('admin.can:loyalty.rewards,loyalty.manage')->group(function () {
            Route::get('rewards', [RewardController::class, 'index']);
            Route::get('rewards/{reward}', [RewardController::class, 'show']);
        });
        Route::middleware('admin.can:loyalty.manage')->group(function () {
            Route::post('rewards', [RewardController::class, 'store']);
            Route::patch('rewards/{reward}', [RewardController::class, 'update']);
            Route::put('rewards/{reward}', [RewardController::class, 'update']);
            Route::delete('rewards/{reward}', [RewardController::class, 'destroy']);
        });
        Route::middleware('admin.can:loyalty.settings')->group(function () {
            Route::get('loyalty-points/settings', [LoyaltyPointSettingController::class, 'getSettings']);
            Route::patch('loyalty-points/settings', [LoyaltyPointSettingController::class, 'updateSettings']);
            Route::post('loyalty-points/preview', [LoyaltyPointSettingController::class, 'previewPoints']);
            Route::post('loyalty-points/expire', [LoyaltyPointSettingController::class, 'expirePoints']);
        });
        Route::get('loyalty-points/{user}/history', [LoyaltyPointSettingController::class, 'pointsHistory'])
            ->middleware('admin.can:members,loyalty');

        Route::middleware('admin.can:redemptions')->group(function () {
            Route::get('redemptions', [RedemptionController::class, 'index']);
        });
        Route::patch('redemptions/{redemption}/status', [RedemptionController::class, 'updateStatus'])
            ->middleware('admin.can:redemptions.review');

        Route::middleware('admin.can:orders')->group(function () {
            Route::get('orders', [OrderController::class, 'index']);
            Route::get('orders/{order}', [OrderController::class, 'show']);
        });
        Route::post('orders', [OrderController::class, 'store'])
            ->middleware('admin.can:orders.update');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])
            ->middleware('admin.can:orders.update');
        Route::patch('orders/{order}/cancel', [OrderController::class, 'cancel'])
            ->middleware('admin.can:orders.cancel');

        Route::middleware('admin.can:transactions')->group(function () {
            Route::get('transactions', [TransactionController::class, 'index']);
            Route::get('transactions/summary', [TransactionController::class, 'summary']);
            Route::get('transactions/{transaction}', [TransactionController::class, 'show']);
        });
        Route::post('transactions', [TransactionController::class, 'store'])
            ->middleware('admin.can:transactions.create,orders.pay');
        Route::patch('transactions/{transaction}/refund', [TransactionController::class, 'refund'])
            ->middleware('admin.can:transactions.refund');

        Route::get('branches', [BranchController::class, 'index']);
        Route::middleware('admin.can:branches')->group(function () {
            Route::get('branches/{branch}', [BranchController::class, 'show']);
            Route::get('branches/{branch}/stats', [BranchController::class, 'stats']);
        });
        Route::post('branches', [BranchController::class, 'store'])
            ->middleware('admin.can:branches.create');
        Route::patch('branches/{branch}', [BranchController::class, 'update'])
            ->middleware('admin.can:branches.update');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])
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

        Route::middleware('admin.can:qr.generate')->group(function () {
            Route::get('qr', [QrController::class, 'index']);
            Route::post('qr/user/{user}', [QrController::class, 'generateUserQr']);
            Route::get('qr/user/{user}', [QrController::class, 'getUserQr']);
            Route::post('qr/order/{order}', [QrController::class, 'generateOrderQr']);
            Route::patch('qr/{qrCode}/deactivate', [QrController::class, 'deactivate']);
        });
        Route::post('qr/scan', [QrController::class, 'scan'])
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
    });
});

// ── User (Mobile) Routes ──────────────────────────────────
Route::prefix('user')->group(function () {
    // Public
    Route::post('/register', [UserAuthController::class, 'register']);
    Route::post('/login',    [UserAuthController::class, 'login']);
    Route::post('/social-login', [UserAuthController::class, 'socialLogin']);
    Route::post('/forgot-password', [UserAuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [UserAuthController::class, 'resetPassword']);
    Route::get('/products',   [ProductController::class,  'index']);
    Route::get('/categories', [CategoryController::class, 'index']);

    // Public read-only support data for the mobile UI
    Route::get('/shop-settings',  fn () => response()->json(\App\Models\ShopSetting::getSettings()));

    // Active store/branch directory shown in the mobile Stores tab.
    Route::get('/branches', fn () => response()->json(
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
    ));

    // Protected
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [UserAuthController::class, 'logout']);
        Route::get('/me',      [UserAuthController::class, 'me']);
        Route::patch('/profile', [UserAuthController::class, 'updateProfile']);
        Route::post('/change-password', [UserAuthController::class, 'changePassword']);
        Route::post('/avatar', [UserAuthController::class, 'uploadAvatar']);
        Route::delete('/avatar', [UserAuthController::class, 'deleteAvatar']);

        // Backward-compatible alias — prefer DELETE /api/account
        Route::delete('/account', [DeleteAccountController::class, 'destroy']);

        // Notifications
        Route::get('notifications',              [UserNotificationController::class, 'index']);
        Route::post('notifications/fcm-token',   [UserNotificationController::class, 'updateFcmToken']);
        Route::patch('notifications/read-all',   [UserNotificationController::class, 'markAllRead']);
        Route::patch('notifications/{notification}/read', [UserNotificationController::class, 'markRead']);

        // ── Orders (PayMongo checkout) ────────────────────────────────
        Route::prefix('orders')->group(function () {
            Route::get('/', [UserOrderController::class, 'index']);
            Route::middleware('throttle:order-checkout')
                ->post('checkout', [UserOrderController::class, 'checkout']);
            Route::middleware('throttle:order-confirm')
                ->post('{order}/confirm', [UserOrderController::class, 'confirm']);
            Route::get('{order}/pickup-qr', [UserOrderController::class, 'pickupQr']);
            Route::get('{order}', [UserOrderController::class, 'show']);
        });

        // ── Rewards / redemptions ─────────────────────────────────────
        Route::get('rewards', [UserRewardController::class, 'index']);
        Route::get('redemptions', [UserRedemptionController::class, 'index']);
        Route::middleware('throttle:order-checkout')
            ->post('redemptions', [UserRedemptionController::class, 'store']);
        Route::get('loyalty-qr', [UserLoyaltyQrController::class, 'show']);
    });
});

// ── Webhooks ──────────────────────────────────────────────────────
Route::prefix('webhooks')->group(function () {
    Route::middleware(['throttle:paymongo-webhook', 'paymongo.signature'])
        ->post('paymongo', [PaymongoWebhookController::class, 'handle']);
});
