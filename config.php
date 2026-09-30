<?php
declare(strict_types=1);

// Public defaults only. Never put secrets in this tracked file.
return [
    'app_name' => 'Suhail Civilian Flight Tracker',
    'mode' => 'demo', // demo | live
    'provider' => 'aviationstack',
    'provider_plan' => 'free', // free | paid
    'aviationstack_key' => '',

    // Aviationstack metadata stays within its 100-request/month free quota.
    // OpenSky live positions are cached separately and refreshed about every 20 minutes.
    'refresh_seconds' => 1200,
    'cache_seconds' => 1140,
    'metadata_cache_seconds' => 25200,
    'opensky_cache_seconds' => 1140,
    'max_provider_requests_per_day' => 3,
    'request_limit' => 100,

    'http_timeout_seconds' => 15,
];
