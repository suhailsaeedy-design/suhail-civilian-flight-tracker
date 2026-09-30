<?php
declare(strict_types=1);

// Public defaults only. Never put secrets in this tracked file.
return [
    'app_name' => 'Suhail Civilian Flight Tracker',
    'mode' => 'demo', // demo | live
    'aviationstack_key' => '',
    'refresh_seconds' => 300,
    'cache_seconds' => 240,
    'request_limit' => 100,
];
