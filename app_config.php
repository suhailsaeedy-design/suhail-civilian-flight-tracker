<?php
declare(strict_types=1);

/**
 * Central application configuration loader.
 *
 * Precedence:
 * 1. Public defaults from config.php
 * 2. Git-ignored config.local.php
 * 3. Server environment variables
 *
 * No secret value is stored in the public repository.
 */
$config = require __DIR__ . '/config.php';

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $override = require $localConfig;
    if (is_array($override)) {
        $config = array_replace($config, $override);
    }
}

$envString = static function (string $name): ?string {
    $value = getenv($name);
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    return $value !== '' ? $value : null;
};

$envInt = static function (string $name) use ($envString): ?int {
    $value = $envString($name);
    if ($value === null || !preg_match('/^-?\d+$/', $value)) {
        return null;
    }

    return (int)$value;
};

if (($value = $envString('FLIGHT_TRACKER_MODE')) !== null) {
    $config['mode'] = in_array($value, ['demo', 'live'], true) ? $value : $config['mode'];
}

if (($value = $envString('AVIATIONSTACK_KEY')) !== null) {
    $config['aviationstack_key'] = $value;
}

if (($value = $envString('AVIATIONSTACK_PLAN')) !== null) {
    $config['provider_plan'] = in_array($value, ['free', 'paid'], true)
        ? $value
        : $config['provider_plan'];
}

if (($value = $envInt('FLIGHT_TRACKER_REFRESH_SECONDS')) !== null) {
    $config['refresh_seconds'] = max(60, $value);
}

if (($value = $envInt('FLIGHT_TRACKER_CACHE_SECONDS')) !== null) {
    $config['cache_seconds'] = max(60, $value);
}

if (($value = $envInt('FLIGHT_TRACKER_DAILY_LIMIT')) !== null) {
    $config['max_provider_requests_per_day'] = max(0, $value);
}

return $config;
