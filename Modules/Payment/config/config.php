<?php

return [

    'default' => env('PAYMENT_DRIVER', 'zibal'),
    'front_url' => env('FRONT_URL'),
    'drivers' => [

        'zibal' => [

            'merchant' => env('ZIBAL_MERCHANT'),

            'sandbox' => env('ZIBAL_SANDBOX', true),

        ],
        'zarinpal' => [
            'merchant' => env('ZARINPAL_MERCHANT'),
            'sandbox' => env('ZARINPAL_SANDBOX', true),
        ],
    ],

];
