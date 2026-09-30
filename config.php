<?php
declare(strict_types=1);

// Public defaults only. Never put secrets in this tracked file.
return [
    'app_name' => 'Suhail Civilian Flight Tracker',
    'mode' => 'demo', // demo | live
    'provider' => 'aviationstack',
    'provider_plan' => 'free', // free | paid
    'aviationstack_key' => '',

    // Free-plan-safe defaults: 3 provider calls/day ~= 90 calls/month.
    // Paid plans can lower refresh/cache intervals and set max_provider_requests_per_day to 0.
    'refresh_seconds' => 28800, // 8 hours
    'cache_seconds' => 25200,   // 7 hours
    'max_provider_requests_per_day' => 3,
    'request_limit' => 100,

    'http_timeout_seconds' => 15,
];
