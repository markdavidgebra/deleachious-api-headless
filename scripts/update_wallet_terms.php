<?php

use App\Models\WalletSetting;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$settings = WalletSetting::query()->first();
if (! $settings) {
    $settings = WalletSetting::getSettings();
}

$settings->update([
    'terms_and_conditions' => WalletSetting::defaultTerms(),
    'terms_version' => '1.1',
    'terms_updated_at' => now(),
]);

echo "Wallet terms updated to version {$settings->terms_version}\n";
