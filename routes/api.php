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
use App\Http\Controllers\Api\Admin\StaffController;
use App\Http\Controllers\Api\Admin\QrController;
use App\Http\Controllers\Api\Admin\NotificationController;
use App\Http\Controllers\Api\User\NotificationController as UserNotificationController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\ShopSettingController;
use App\Http\Controllers\Api\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\Api\Admin\WalletSettingController;
use App\Http\Controllers\Api\User\OrderController as UserOrderController;
use App\Http\Controllers\Api\User\WalletController as UserWalletController;
use App\Http\Controllers\Api\Webhook\PaymongoWebhookController;

// ── Admin Routes ──────────────────────────────
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);

        // Menu & Products
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('products', ProductController::class);

        // Members
        Route::get('members', [MemberController::class, 'index']);
        Route::get('members/{user}', [MemberController::class, 'show']);
        Route::get('members/{user}/points-history', [MemberController::class, 'pointsHistory']);

        // Loyalty Point Settings
        Route::get('loyalty-points/settings', [LoyaltyPointSettingController::class, 'getSettings']);
        Route::patch('loyalty-points/settings', [LoyaltyPointSettingController::class, 'updateSettings']);
        Route::post('loyalty-points/preview', [LoyaltyPointSettingController::class, 'previewPoints']);
        Route::post('loyalty-points/expire', [LoyaltyPointSettingController::class, 'expirePoints']);
        Route::post('loyalty-points/{user}/adjust', [LoyaltyPointSettingController::class, 'adjustPoints']);
        Route::get('loyalty-points/{user}/history', [LoyaltyPointSettingController::class, 'pointsHistory']);

        // Rewards
        Route::apiResource('rewards', RewardController::class);

        // Redemptions
        Route::get('redemptions', [RedemptionController::class, 'index']);
        Route::patch('redemptions/{redemption}/status', [RedemptionController::class, 'updateStatus']);

        // Orders
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
        Route::patch('orders/{order}/cancel', [OrderController::class, 'cancel']);

        // Transactions
        Route::get('transactions', [TransactionController::class, 'index']);
        Route::post('transactions', [TransactionController::class, 'store']);
        Route::get('transactions/summary', [TransactionController::class, 'summary']);
        Route::get('transactions/{transaction}', [TransactionController::class, 'show']);
        Route::patch('transactions/{transaction}/refund', [TransactionController::class, 'refund']);

        // Branches
        Route::apiResource('branches', BranchController::class);
        Route::get('branches/{branch}/stats', [BranchController::class, 'stats']);

        // Staff
        Route::get('staff', [StaffController::class, 'index']);
        Route::post('staff', [StaffController::class, 'store']);
        Route::get('staff/{admin}', [StaffController::class, 'show']);
        Route::patch('staff/{admin}', [StaffController::class, 'update']);
        Route::patch('staff/{admin}/toggle-status', [StaffController::class, 'toggleStatus']);
        Route::delete('staff/{admin}', [StaffController::class, 'destroy']);

        // QR Codes
        Route::get('qr', [QrController::class, 'index']);
        Route::get('qr/scans', [QrController::class, 'scanHistory']);
        Route::post('qr/scan', [QrController::class, 'scan']);
        Route::post('qr/user/{user}', [QrController::class, 'generateUserQr']);
        Route::get('qr/user/{user}', [QrController::class, 'getUserQr']);
        Route::post('qr/order/{order}', [QrController::class, 'generateOrderQr']);
        Route::patch('qr/{qrCode}/deactivate', [QrController::class, 'deactivate']);

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/send', [NotificationController::class, 'send']);
        Route::get('notifications/{notification}', [NotificationController::class, 'show']);
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);

        // Shop Settings (general info + logo)
        Route::get('shop-settings',           [ShopSettingController::class, 'show']);
        Route::patch('shop-settings',         [ShopSettingController::class, 'update']);
        Route::post('shop-settings/logo',     [ShopSettingController::class, 'uploadLogo']);
        Route::delete('shop-settings/logo',   [ShopSettingController::class, 'deleteLogo']);

        // Audit Logs
        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/summary', [AuditLogController::class, 'summary']);
        Route::get('audit-logs/admin/{adminId}', [AuditLogController::class, 'byAdmin']);
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
        Route::delete('audit-logs/cleanup', [AuditLogController::class, 'cleanup']);

        // ── Wallet (back-office) ──────────────────────────────────────
        // All read endpoints share a single rate limiter; money-moving
        // endpoints are individually throttled.
        Route::prefix('wallet')->group(function () {
            Route::middleware('throttle:wallet-read')->group(function () {
                Route::get('summary',                       [AdminWalletController::class, 'summary']);
                Route::get('transactions',                  [AdminWalletController::class, 'transactions']);
                Route::get('transactions/{transaction}',    [AdminWalletController::class, 'showTransaction']);
                Route::get('users/{user}',                  [AdminWalletController::class, 'showUserWallet']);
                Route::get('users/{user}/history',          [AdminWalletController::class, 'userHistory']);
                Route::get('refunds',                       [AdminWalletController::class, 'refundsIndex']);
                Route::get('branches/{branch}/report',      [AdminWalletController::class, 'branchReport']);
                Route::get('settings',                      [WalletSettingController::class, 'show']);
            });

            Route::middleware('throttle:wallet-write')->group(function () {
                Route::post('topup-cash',                          [AdminWalletController::class, 'counterTopup']);
                Route::post('qr/redeem',                           [AdminWalletController::class, 'redeemQr']);
                Route::post('adjust',                              [AdminWalletController::class, 'adjust']);
                Route::post('refunds/{refund}/approve',            [AdminWalletController::class, 'approveRefund']);
                Route::post('refunds/{refund}/reject',             [AdminWalletController::class, 'rejectRefund']);
                Route::patch('wallets/{wallet}/status',            [AdminWalletController::class, 'updateWalletStatus']);
                Route::patch('settings',                           [WalletSettingController::class, 'update']);
            });
        });
    });
});

// ── User (Mobile) Routes ──────────────────────────────────
Route::prefix('user')->group(function () {
    // Public
    Route::post('/register', [UserAuthController::class, 'register']);
    Route::post('/login',    [UserAuthController::class, 'login']);
    Route::get('/products',   [ProductController::class,  'index']);
    Route::get('/categories', [CategoryController::class, 'index']);

    // Public read-only support data for the mobile UI
    Route::get('/shop-settings',  fn () => response()->json(\App\Models\ShopSetting::getSettings()));
    Route::get('/wallet/terms',   [UserWalletController::class, 'terms']);

    // Active store/branch directory shown in the mobile Stores tab.
    // Only public-facing fields are returned (no staff/order counts).
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
        Route::delete('/account', [UserAuthController::class, 'deleteAccount']);

        // Notifications
        Route::get('notifications',              [UserNotificationController::class, 'index']);
        Route::post('notifications/fcm-token',   [UserNotificationController::class, 'updateFcmToken']);
        Route::patch('notifications/read-all',   [UserNotificationController::class, 'markAllRead']);
        Route::patch('notifications/{notification}/read', [UserNotificationController::class, 'markRead']);

        // ── Orders (mobile app — wallet checkout only) ────────────────
        Route::prefix('orders')->group(function () {
            Route::get('/', [UserOrderController::class, 'index']);
            Route::middleware(['throttle:wallet-pay', 'wallet.idempotency:purchase'])
                ->post('checkout', [UserOrderController::class, 'checkout']);
            Route::get('{order}', [UserOrderController::class, 'show']);
        });

        // ── Wallet (mobile app) ───────────────────────────────────────
        Route::prefix('wallet')->group(function () {
            // Reads — light throttling.
            Route::middleware('throttle:wallet-read')->group(function () {
                Route::get('balance', [UserWalletController::class, 'balance']);
                Route::get('history', [UserWalletController::class, 'history']);
            });

            // Top-up: heavy throttle + idempotency required.
            Route::middleware(['throttle:wallet-topup', 'wallet.idempotency:topup'])
                ->post('topup', [UserWalletController::class, 'topup']);

            // Confirm after PayMongo checkout (no new charge — sync only).
            Route::middleware('throttle:wallet-write')
                ->post('topup/{topup}/confirm', [UserWalletController::class, 'confirmTopup']);

            // Pay & QR: medium throttle + idempotency.
            Route::middleware(['throttle:wallet-pay', 'wallet.idempotency:purchase'])->group(function () {
                Route::post('pay',         [UserWalletController::class, 'pay']);
                Route::post('qr/generate', [UserWalletController::class, 'generateQr']);
            });

            // Refund requests: write rate-limited, idempotency optional.
            Route::middleware(['throttle:wallet-write', 'wallet.idempotency:refund'])
                ->post('refund', [UserWalletController::class, 'requestRefund']);
        });
    });
});

// ── Webhooks ──────────────────────────────────────────────────────
// PayMongo posts here. Signature verification middleware runs first,
// then the controller dispatches a queued job and returns 200 OK fast.
Route::prefix('webhooks')->group(function () {
    Route::middleware(['throttle:paymongo-webhook', 'wallet.paymongo.signature'])
        ->post('paymongo', [PaymongoWebhookController::class, 'handle']);
});