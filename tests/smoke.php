<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
$runtimeConfig = require $root . '/app_config.php';

$failures = [];

$requiredFiles = [
    'index.php',
    'api/flights.php',
    'assets/css/style.css',
    'assets/js/app.js',
    'config.php',
    'config.example.php',
    'app_config.php',
];

foreach ($requiredFiles as $file) {
    if (!is_file($root . '/' . $file)) {
        $failures[] = "Missing required file: {$file}";
    }
}

if (!is_array($config)) {
    $failures[] = 'config.php must return an array.';
}

if (($config['mode'] ?? null) !== 'demo') {
    $failures[] = 'Tracked config.php must default to demo mode.';
}

if (($config['aviationstack_key'] ?? null) !== '') {
    $failures[] = 'Tracked config.php must never contain a live API key.';
}

if ((int)($config['refresh_seconds'] ?? 0) < 60) {
    $failures[] = 'refresh_seconds must be at least 60 seconds.';
}

if ((int)($config['max_provider_requests_per_day'] ?? -1) < 0) {
    $failures[] = 'max_provider_requests_per_day cannot be negative.';
}

if (!is_array($runtimeConfig)) {
    $failures[] = 'app_config.php must return an array.';
}

if (!array_key_exists('aviationstack_key', $runtimeConfig)) {
    $failures[] = 'Runtime config must expose the aviationstack_key field.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Smoke checks passed." . PHP_EOL;
