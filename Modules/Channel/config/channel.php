<?php

return [
    'torob' => [
        'expected_audience' => env('TOROB_EXPECTED_AUDIENCE', 'api.mahseti.shop'),
        'enabled'           => env('TOROB_ENABLED', true),
        'per_page'          => 100,
    ],

    // آدرس base فرانت برای ساخت page_url
    'front_url' => env('FRONT_URL', 'https://mahseti.shop'),

    // آدرس base فایل‌های storage_public
    'storage_public_url' => env('STORAGE_PUBLIC_URL', 'https://api.mahseti.shop/storage_public'),
];
