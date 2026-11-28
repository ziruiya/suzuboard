<?php

return [
    'counter_path' => storage_path('framework/cache/log0'),
    'probe_keys' => [
        'cn-shanghai' => env('LOG0_KEY_CN_SHANGHAI'),
        'cn-alt' => env('LOG0_KEY_CN_ALT'),
        'overseas-hk' => env('LOG0_KEY_OVERSEAS_HK'),
        'overseas-jp' => env('LOG0_KEY_OVERSEAS_JP'),
    ],
];
