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

function redisClient(): ?Redis
{
    static $client = false;

    if ($client instanceof Redis) {
        return $client;
    }

    if ($client === null || !class_exists('Redis')) {
        return null;
    }

    $url = trim((string)(getenv('REDIS_URL') ?: ''));
    if ($url === '') {
        $client = null;
        return null;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        $client = null;
        return null;
    }

    try {
        $redis = new Redis();
        $host = (string)$parts['host'];
        $port = (int)($parts['port'] ?? 6379);

        if (($parts['scheme'] ?? 'redis') === 'rediss') {
            $host = 'tls://' . $host;
        }

        if (!$redis->connect($host, $port, 2.5)) {
            $client = null;
            return null;
        }

        if (isset($parts['pass']) && $parts['pass'] !== '') {
            $username = isset($parts['user']) && $parts['user'] !== ''
                ? rawurldecode((string)$parts['user'])
                : 'default';
            $password = rawurldecode((string)$parts['pass']);

            if (!$redis->auth([$username, $password])) {
                $client = null;
                return null;
            }
        }

        if (!empty($parts['path']) && $parts['path'] !== '/') {
            $db = (int)ltrim((string)$parts['path'], '/');
            if ($db > 0) {
                $redis->select($db);
            }
        }

        $client = $redis;
        return $client;
    } catch (Throwable) {
        $client = null;
        return null;
    }
}

function cacheDir(): string
{
    return dirname(__DIR__) . '/storage/cache';
}

function cacheFile(): string
{
    return cacheDir() . '/flights-v3.json';
}

function ensureCacheDir(): void
{
    $dir = cacheDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function cachePayloadAge(array $payload): ?int
{
    $cachedAt = $payload['cached_at'] ?? null;
    if (!is_string($cachedAt) || $cachedAt === '') {
        return null;
    }

    $timestamp = strtotime($cachedAt);
    return $timestamp === false ? null : max(0, time() - $timestamp);
}

function readCache(int $maxAge, bool $allowStale = false): ?array
{
    $redis = redisClient();
    if ($redis instanceof Redis) {
        try {
            $raw = $redis->get('flight_tracker:v3:latest_flights');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $age = cachePayloadAge($decoded);
                    if ($allowStale || ($age !== null && $age <= $maxAge)) {
                        $decoded['cache_backend'] = 'redis';
                        return $decoded;
                    }
                }
            }
        } catch (Throwable) {
            // Fall through to local file cache.
        }
    }

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
    if (!is_array($decoded)) {
        return null;
    }

    $decoded['cache_backend'] = 'file';
    return $decoded;
}

function writeCache(array $payload): void
{
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (is_string($encoded)) {
        $redis = redisClient();
        if ($redis instanceof Redis) {
            try {
                $redis->set('flight_tracker:v3:latest_flights', $encoded);
            } catch (Throwable) {
                // Local file fallback still runs below.
            }
        }
    }

    ensureCacheDir();
    @file_put_contents(cacheFile(), (string)$encoded, LOCK_EX);
}

function dailyUsageKey(): string
{
    return 'flight_tracker:provider_usage:' . gmdate('Y-m-d');
}

function dailyUsageFile(): string
{
    return cacheDir() . '/provider-usage-' . gmdate('Y-m-d') . '.json';
}

function dailyUsage(): int
{
    $redis = redisClient();
    if ($redis instanceof Redis) {
        try {
            $value = $redis->get(dailyUsageKey());
            if ($value !== false) {
                return max(0, (int)$value);
            }
        } catch (Throwable) {
            // Fall through to local file counter.
        }
    }

    $path = dailyUsageFile();
    if (!is_file($path)) {
        return 0;
    }

    $decoded = json_decode((string)@file_get_contents($path), true);
    return max(0, (int)($decoded['successful_requests'] ?? 0));
}

function incrementDailyUsage(): void
{
    $redis = redisClient();
    if ($redis instanceof Redis) {
        try {
            $key = dailyUsageKey();
            $redis->incr($key);
            $redis->expire($key, 259200);
            return;
        } catch (Throwable) {
            // Fall through to local file counter.
        }
    }

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

    // Require a named airline and an identifiable flight. Route fields are
    // useful metadata but are not mandatory because valid live records may omit them.
    if ($airlineName === '' || $flightNumber === '') {
        return false;
    }

    if ($airlineIata !== '' && !preg_match('/^[A-Z0-9]{2,3}$/', $airlineIata)) {
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
            'scheduled' => (string)($row['departure']['scheduled'] ?? ''),
            'estimated' => (string)($row['departure']['estimated'] ?? ''),
            'terminal' => (string)($row['departure']['terminal'] ?? ''),
            'gate' => (string)($row['departure']['gate'] ?? ''),
            'timezone' => (string)($row['departure']['timezone'] ?? ''),
            'delay' => is_numeric($row['departure']['delay'] ?? null) ? (int)$row['departure']['delay'] : null,
        ],
        'arrival' => [
            'iata' => (string)($row['arrival']['iata'] ?? ''),
            'airport' => (string)($row['arrival']['airport'] ?? ''),
            'scheduled' => (string)($row['arrival']['scheduled'] ?? ''),
            'estimated' => (string)($row['arrival']['estimated'] ?? ''),
            'terminal' => (string)($row['arrival']['terminal'] ?? ''),
            'gate' => (string)($row['arrival']['gate'] ?? ''),
            'timezone' => (string)($row['arrival']['timezone'] ?? ''),
            'delay' => is_numeric($row['arrival']['delay'] ?? null) ? (int)$row['arrival']['delay'] : null,
        ],
        'aircraft' => [
            'registration' => $registration,
            'type' => $type ?: '—',
        ],
        // Passenger-facing camera feeds are shown only when an airline publishes
        // an official public live feed. No private onboard feeds are accessed.
        'camera' => null,
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

$requestLimit = max(10, min(100, (int)($config['request_limit'] ?? 100)));
$timeout = max(5, min(30, (int)($config['http_timeout_seconds'] ?? 15)));

// The free profile allows 3 successful provider requests/day in this project.
// Use the available daily budget to inspect up to 3 pages (up to 300 active
// records) instead of assuming the first 100 contain enough live coordinates.
$remainingBudget = $dailyLimit > 0 ? max(0, $dailyLimit - $usedToday) : 3;
$pageBudget = max(1, min(3, $remainingBudget));
$providerRows = [];
$requestsThisRefresh = 0;

for ($page = 0; $page < $pageBudget; $page++) {
    $params = http_build_query([
        'access_key' => $key,
        'flight_status' => 'active',
        'limit' => $requestLimit,
        'offset' => $page * $requestLimit,
    ]);

    $url = 'https://api.aviationstack.com/v1/flights?' . $params;
    $response = providerRequest($url, $timeout);

    if (!$response['ok']) {
        if ($providerRows === []) {
            staleFallback(
                'The live flight-data provider could not be reached right now.',
                $cacheSeconds
            );
        }
        break;
    }

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        if ($providerRows === []) {
            staleFallback(
                'The live flight-data provider returned an invalid response.',
                $cacheSeconds
            );
        }
        break;
    }

    if (isset($decoded['error'])) {
        if ($providerRows === []) {
            staleFallback(
                (string)($decoded['error']['message'] ?? 'The live flight-data provider returned an error.'),
                $cacheSeconds
            );
        }
        break;
    }

    $rows = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $providerRows[] = $row;
        }
    }

    incrementDailyUsage();
    $requestsThisRefresh++;

    if (count($rows) < $requestLimit) {
        break;
    }
}

$liveCoordinateRecords = 0;
$commercialIdentityRecords = 0;
$normalized = [];
$seenIds = [];

foreach ($providerRows as $row) {
    $live = is_array($row['live'] ?? null) ? $row['live'] : [];
    if (is_numeric($live['latitude'] ?? null) && is_numeric($live['longitude'] ?? null)) {
        $liveCoordinateRecords++;
    }

    if (isAllowedCivilianFlight($row)) {
        $commercialIdentityRecords++;
    }

    $flight = normalizeFlight($row);
    if ($flight !== null && !isset($seenIds[$flight['id']])) {
        $seenIds[$flight['id']] = true;
        $normalized[] = $flight;
    }
}

$coverageStats = [
    'provider_records' => count($providerRows),
    'live_coordinate_records' => $liveCoordinateRecords,
    'commercial_identity_records' => $commercialIdentityRecords,
    'displayable_records' => count($normalized),
    'requests_this_refresh' => $requestsThisRefresh,
];

error_log('FLIGHT_COVERAGE ' . json_encode($coverageStats, JSON_UNESCAPED_SLASHES));

$payload = [
    'ok' => true,
    'mode' => 'live',
    'provider' => 'aviationstack',
    'provider_plan' => (string)($config['provider_plan'] ?? 'free'),
    'safety' => 'civilian-commercial-only',
    'count' => count($normalized),
    'data' => $normalized,
    'coverage_stats' => $coverageStats,
    'stale' => false,
    'served_from_cache' => false,
    'provider_requests_today' => dailyUsage(),
    'provider_requests_daily_limit' => $dailyLimit,
    'cached_at' => gmdate('c'),
    'message' => count($normalized) > 0
        ? 'Identifiable commercial airline flights with live coordinates are shown.'
        : 'The provider returned active-flight records, but none passed the live-coordinate/commercial display checks.',
];

writeCache($payload);
jsonResponse($payload);
