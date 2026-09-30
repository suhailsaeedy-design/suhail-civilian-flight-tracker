<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$config = require dirname(__DIR__) . '/app_config.php';

echo json_encode([
    'ok' => true,
    'service' => 'suhail-civilian-flight-tracker',
    'mode' => (string)($config['mode'] ?? 'demo'),
    'provider_configured' => trim((string)($config['aviationstack_key'] ?? '')) !== '',
    'time_utc' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
