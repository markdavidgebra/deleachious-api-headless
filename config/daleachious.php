<?php

return [
    'card' => [
        'minimum_topup' => (float) env('DALEACHIOUS_CARD_MIN_TOPUP', 100),
        'maximum_topup' => (float) env('DALEACHIOUS_CARD_MAX_TOPUP', 10000),
        'presets' => [100, 200, 500, 1000],
    ],
    'points' => [
        // Pay with a registered Daleachious Card.
        'card_peso_per_point' => (float) env('DALEACHIOUS_CARD_PESO_PER_POINT', 25),
        // Cash, credit/debit, or selected e-wallets after scanning the member QR.
        'other_peso_per_point' => (float) env('DALEACHIOUS_OTHER_PESO_PER_POINT', 50),
    ],
    'purchase_payment_methods' => [
        'daleachious_card' => 'Daleachious Card',
        'cash' => 'Cash',
        'credit_debit' => 'Credit / Debit',
        'gcash' => 'GCash',
        'maya' => 'Maya',
    ],
];
