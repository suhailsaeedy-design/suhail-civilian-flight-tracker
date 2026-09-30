<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$root = dirname(__DIR__);
$config = require $root . '/app_config.php';

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function demoFlights(): array
{
    return [
        [
            'id' => 'demo-EK201',
            'flight_number' => 'EK201',
            'airline' => 'Emirates',
            'airline_iata' => 'EK',
            'status' => 'active',
            'departure' => ['iata' => 'DXB', 'airport' => 'Dubai International'],
            'arrival' => ['iata' => 'JFK', 'airport' => 'John F. Kennedy International'],
            'aircraft' => ['registration' => 'DEMO-001', 'type' => 'Boeing 777'],
            'live' => ['lat' => 43.2, 'lon' => 24.4, 'altitude_m' => 10600, 'speed_kmh' => 890, 'direction' => 305],
            'updated_at' => gmdate('c')
        ],
        [
            'id' => 'demo-TK706',
            'flight_number' => 'TK706',
            'airline' => 'Turkish Airlines',
            'airline_iata' => 'TK',
            'status' => 'active',
            'departure' => ['iata' => 'IST', 'airport' => 'Istanbul Airport'],
            'arrival' => ['iata' => 'KBL', 'airport' => 'Kabul International'],
            'aircraft' => ['registration' => 'DEMO-002', 'type' => 'Airbus A330'],
            'live' => ['lat' => 35.7, 'lon' => 58.2, 'altitude_m' => 10100, 'speed_kmh' => 835, 'direction' => 95],
            'updated_at' => gmdate('c')
        ],
        [
            'id' => 'demo-QR920',
            'flight_number' => 'QR920',
            'airline' => 'Qatar Airways',
            'airline_iata' => 'QR',
            'status' => 'active',
            'departure' => ['iata' => 'DOH', 'airport' => 'Hamad International'],
            'arrival' => ['iata' => 'AKL', 'airport' => 'Auckland International'],
            'aircraft' => ['registration' => 'DEMO-003', 'type' => 'Airbus A350'],
            'live' => ['lat' => 7.4, 'lon' => 88.1, 'altitude_m' => 11200, 'speed_kmh' => 905, 'direction' => 120],
            'updated_at' => gmdate('c')
        ],
        [
            'id' => 'demo-LH430',
            'flight_number' => 'LH430',
            'airline' => 'Lufthansa',
            'airline_iata' => 'LH',
            'status' => 'active',
            'departure' => ['iata' => 'FRA', 'airport' => 'Frankfurt Airport'],
            'arrival' => ['iata' => 'ORD', 'airport' => "Chicago O'Hare International"],
            'aircraft' => ['registration' => 'DEMO-004', 'type' => 'Airbus A340'],
            'live' => ['lat' => 55.5, 'lon' => -22.8, 'altitude_m' => 10850, 'speed_kmh' => 875, 'direction' => 285],
            'updated_at' => gmdate('c')
        ],
    ];
}

function cacheDir(): string
{
    return dirname(__DIR__) . '/storage/cache';
}

function cacheFile(): string
{
    return cacheDir() . '/flights.json';
}

function ensureCacheDir(): void
{
    $dir = cacheDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function readCache(int $maxAge, bool $allowStale = false): ?array
{
    $path = cacheFile();
    if (!is_file($path)) {
        return null;
    }

    if (!$allowStale && (time() - filemtime($path)) > $maxAge) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function writeCache(array $payload): void
{
    ensureCacheDir();
    @file_put_contents(
        cacheFile(),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function dailyUsageFile(): string
{
    return cacheDir() . '/provider-usage-' . gmdate('Y-m-d') . '.json';
}

function dailyUsage(): int
{
    $path = dailyUsageFile();
    if (!is_file($path)) {
        return 0;
    }

    $decoded = json_decode((string)@file_get_contents($path), true);
    return max(0, (int)($decoded['successful_requests'] ?? 0));
}

function incrementDailyUsage(): void
{
    ensureCacheDir();
    $count = dailyUsage() + 1;
    @file_put_contents(
        dailyUsageFile(),
        json_encode([
            'date_utc' => gmdate('Y-m-d'),
            'successful_requests' => $count,
            'updated_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function providerRequest(string $url, int $timeout): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'SuhailCivilianFlightTracker/1.0',
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'ok' => $body !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: SuhailCivilianFlightTracker/1.0\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }
    }

    return [
        'ok' => $body !== false && ($status === 0 || ($status >= 200 && $status < 300)),
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $body === false ? 'HTTP request failed.' : '',
    ];
}

function staleFallback(string $reason, int $cacheSeconds): never
{
    $stale = readCache($cacheSeconds, true);
    if ($stale === null) {
        jsonResponse([
            'ok' => false,
            'error' => $reason,
        ], 503);
    }

    $stale['stale'] = true;
    $stale['warning'] = $reason;
    $stale['message'] = 'Showing the most recent cached civilian flight data.';
    jsonResponse($stale);
}

/**
 * Only keep identifiable commercial airline flights.
 * Unknown, government/military-like, and non-airline targets are intentionally excluded.
 */
function isAllowedCivilianFlight(array $row): bool
{
    $airlineName = trim((string)($row['airline']['name'] ?? ''));
    $airlineIata = strtoupper(trim((string)($row['airline']['iata'] ?? '')));
    $flightNumber = trim((string)($row['flight']['iata'] ?? $row['flight']['number'] ?? ''));
    $dep = strtoupper(trim((string)($row['departure']['iata'] ?? '')));
    $arr = strtoupper(trim((string)($row['arrival']['iata'] ?? '')));

    if ($airlineName === '' || $airlineIata === '' || $flightNumber === '' || $dep === '' || $arr === '') {
        return false;
    }

    if (!preg_match('/^[A-Z0-9]{2,3}$/', $airlineIata)) {
        return false;
    }

    $blockedWords = [
        'military', 'air force', 'army', 'navy', 'government',
        'ministry', 'defence', 'defense', 'police', 'coast guard',
    ];

    $haystack = strtolower($airlineName . ' ' . $flightNumber);
    foreach ($blockedWords as $word) {
        if (str_contains($haystack, $word)) {
            return false;
        }
    }

    return true;
}

function normalizeFlight(array $row): ?array
{
    if (!isAllowedCivilianFlight($row)) {
        return null;
    }

    $live = is_array($row['live'] ?? null) ? $row['live'] : [];
    $lat = $live['latitude'] ?? null;
    $lon = $live['longitude'] ?? null;

    if (!is_numeric($lat) || !is_numeric($lon)) {
        return null;
    }

    $airlineName = (string)($row['airline']['name'] ?? 'Unknown Airline');
    $airlineIata = (string)($row['airline']['iata'] ?? '');
    $flightNumber = (string)($row['flight']['iata'] ?? $row['flight']['number'] ?? '');
    $registration = (string)($row['aircraft']['registration'] ?? '');
    $aircraftIata = (string)($row['aircraft']['iata'] ?? '');
    $aircraftIcao = (string)($row['aircraft']['icao'] ?? '');
    $type = trim($aircraftIata . ($aircraftIcao ? ' / ' . $aircraftIcao : ''));

    return [
        'id' => sha1($airlineIata . '|' . $flightNumber . '|' . $registration),
        'flight_number' => $flightNumber,
        'airline' => $airlineName,
        'airline_iata' => $airlineIata,
        'status' => (string)($row['flight_status'] ?? 'unknown'),
        'departure' => [
            'iata' => (string)($row['departure']['iata'] ?? ''),
            'airport' => (string)($row['departure']['airport'] ?? ''),
        ],
        'arrival' => [
            'iata' => (string)($row['arrival']['iata'] ?? ''),
            'airport' => (string)($row['arrival']['airport'] ?? ''),
        ],
        'aircraft' => [
            'registration' => $registration,
            'type' => $type ?: '—',
        ],
        'live' => [
            'lat' => (float)$lat,
            'lon' => (float)$lon,
            'altitude_m' => is_numeric($live['altitude'] ?? null) ? (float)$live['altitude'] : null,
            'speed_kmh' => is_numeric($live['speed_horizontal'] ?? null) ? (float)$live['speed_horizontal'] : null,
            'direction' => is_numeric($live['direction'] ?? null) ? (float)$live['direction'] : null,
        ],
        'updated_at' => isset($live['updated']) && is_numeric($live['updated'])
            ? gmdate('c', (int)$live['updated'])
            : gmdate('c'),
    ];
}

$mode = (string)($config['mode'] ?? 'demo');

if ($mode !== 'live') {
    $demo = demoFlights();
    jsonResponse([
        'ok' => true,
        'mode' => 'demo',
        'provider' => 'fictional-demo',
        'safety' => 'civilian-commercial-only',
        'count' => count($demo),
        'data' => $demo,
        'stale' => false,
        'message' => 'Demo data is fictionalized and not real-time.',
    ]);
}

$key = trim((string)($config['aviationstack_key'] ?? ''));
if ($key === '') {
    jsonResponse([
        'ok' => false,
        'mode' => 'live',
        'error' => 'Live mode is enabled but aviationstack_key is empty.',
    ], 500);
}

$cacheSeconds = max(60, (int)($config['cache_seconds'] ?? 25200));
$cached = readCache($cacheSeconds);
if ($cached !== null) {
    $cached['served_from_cache'] = true;
    jsonResponse($cached);
}

$dailyLimit = max(0, (int)($config['max_provider_requests_per_day'] ?? 3));
$usedToday = dailyUsage();

if ($dailyLimit > 0 && $usedToday >= $dailyLimit) {
    staleFallback(
        'Daily provider-request guard reached. This protects the configured API quota.',
        $cacheSeconds
    );
}

$params = http_build_query([
    'access_key' => $key,
    'flight_status' => 'active',
    'limit' => max(10, min(100, (int)($config['request_limit'] ?? 100))),
]);

$url = 'https://api.aviationstack.com/v1/flights?' . $params;
$timeout = max(5, min(30, (int)($config['http_timeout_seconds'] ?? 15)));
$response = providerRequest($url, $timeout);

if (!$response['ok']) {
    staleFallback(
        'The live flight-data provider could not be reached right now.',
        $cacheSeconds
    );
}

$decoded = json_decode($response['body'], true);
if (!is_array($decoded)) {
    staleFallback(
        'The live flight-data provider returned an invalid response.',
        $cacheSeconds
    );
}

if (isset($decoded['error'])) {
    staleFallback(
        (string)($decoded['error']['message'] ?? 'The live flight-data provider returned an error.'),
        $cacheSeconds
    );
}

$normalized = [];
foreach (($decoded['data'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }

    $flight = normalizeFlight($row);
    if ($flight !== null) {
        $normalized[] = $flight;
    }
}

incrementDailyUsage();

$payload = [
    'ok' => true,
    'mode' => 'live',
    'provider' => 'aviationstack',
    'provider_plan' => (string)($config['provider_plan'] ?? 'free'),
    'safety' => 'civilian-commercial-only',
    'count' => count($normalized),
    'data' => $normalized,
    'stale' => false,
    'served_from_cache' => false,
    'provider_requests_today' => dailyUsage(),
    'provider_requests_daily_limit' => $dailyLimit,
    'cached_at' => gmdate('c'),
    'message' => 'Only identifiable commercial airline flights with live coordinates are shown.',
];

writeCache($payload);
jsonResponse($payload);
