<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletSetting extends Model
{
    protected $fillable = [
        'max_balance',
        'max_topup',
        'min_topup',
        'daily_topup_limit',
        'daily_purchase_limit',
        'max_purchase',
        'qr_ttl_seconds',
        'failed_topup_threshold',
        'failed_topup_window_minutes',
        'topup_enabled',
        'purchase_enabled',
        'refund_enabled',
        'terms_and_conditions',
        'terms_version',
        'terms_updated_at',
    ];

    protected $casts = [
        'max_balance'                 => 'decimal:2',
        'max_topup'                   => 'decimal:2',
        'min_topup'                   => 'decimal:2',
        'daily_topup_limit'           => 'decimal:2',
        'daily_purchase_limit'        => 'decimal:2',
        'max_purchase'                => 'decimal:2',
        'qr_ttl_seconds'              => 'integer',
        'failed_topup_threshold'      => 'integer',
        'failed_topup_window_minutes' => 'integer',
        'topup_enabled'               => 'boolean',
        'purchase_enabled'            => 'boolean',
        'refund_enabled'              => 'boolean',
        'terms_updated_at'            => 'datetime',
    ];

    /**
     * Single-row settings accessor — mirrors ShopSetting::getSettings().
     * Always returns a row, creating one with sane defaults if needed.
     */
    public static function getSettings(): self
    {
        return static::firstOrCreate([], [
            'max_balance'                 => 10000,
            'max_topup'                   => 5000,
            'min_topup'                   => 50,
            'daily_topup_limit'           => 20000,
            'daily_purchase_limit'        => 20000,
            'max_purchase'                => 10000,
            'qr_ttl_seconds'              => 120,
            'failed_topup_threshold'      => 5,
            'failed_topup_window_minutes' => 15,
            'topup_enabled'               => true,
            'purchase_enabled'            => true,
            'refund_enabled'              => true,
            'terms_version'               => '1.0',
            'terms_and_conditions'        => static::defaultTerms(),
            'terms_updated_at'            => now(),
        ]);
    }

    public static function defaultTerms(): string
    {
        return <<<'MD'
## Daleachious Wallet — Terms & Conditions

1. **Closed-loop wallet.** The Daleachious wallet may be used **only** to pay
   for products and services at official Daleachious coffee-shop branches.
2. **Non-transferable.** Wallet balance cannot be transferred to other users
   or to any external party.
3. **Non-cash redeemable.** Wallet balance cannot be withdrawn as cash, sent
   to a bank, or moved to any other e-wallet.
4. **No interest.** The wallet is **not a bank account** and does not earn
   any form of interest, dividends or yield.
5. **Prepaid store credits.** Balance is prepaid store credit and is the
   property of Daleachious until redeemed for products or services.
6. **Suspension for fraud.** Daleachious may suspend or close any wallet it
   reasonably suspects of fraudulent or abusive activity.
7. **Refunds.** Refunds, when approved, are credited back to the wallet
   balance. Cash refunds are issued only at management's discretion.
8. **Limits.** Maximum balance, top-up and daily transaction limits apply
   and may change from time to time.
MD;
    }
}
