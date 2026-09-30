<?php
declare(strict_types=1);

// Copy this file to config.local.php for local/private settings.
// config.local.php is ignored by Git and must never be committed.
return [
    'mode' => 'live',
    'provider_plan' => 'free',
    'aviationstack_key' => 'YOUR_API_KEY',

    // Keep these defaults for the Aviationstack free tier.
    'refresh_seconds' => 28800,
    'cache_seconds' => 25200,
    'max_provider_requests_per_day' => 3,

    // For a paid plan you may lower the intervals and disable the daily guard:
    // 'provider_plan' => 'paid',
    // 'refresh_seconds' => 300,
    // 'cache_seconds' => 240,
    // 'max_provider_requests_per_day' => 0,
];
