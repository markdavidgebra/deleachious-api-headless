<?php

namespace Database\Seeders;

use App\Models\WalletSetting;
use Illuminate\Database\Seeder;

class WalletSettingSeeder extends Seeder
{
    /**
     * Idempotently ensure a wallet_settings row exists with the
     * recommended initial limits and the default T&Cs.
     */
    public function run(): void
    {
        WalletSetting::getSettings();
    }
}
