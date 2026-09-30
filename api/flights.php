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

function ensureCacheDir(): void
{
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
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

function cachePath(string $name): string
{
    return dirname(__DIR__) . '/storage/cache/' . $name . '.json';
}

function cacheRead(string $key, string $fileName, int $maxAge, bool $allowStale = false): ?array
{
    $redis = redisClient();

    if ($redis instanceof Redis) {
        try {
            $raw = $redis->get($key);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $cachedAt = strtotime((string)($decoded['cached_at'] ?? ''));
                    $age = $cachedAt === false ? null : max(0, time() - $cachedAt);

                    if ($allowStale || ($age !== null && $age <= $maxAge)) {
                        $decoded['_cache_backend'] = 'redis';
                        return $decoded;
                    }
                }
            }
        } catch (Throwable) {
            // File fallback below.
        }
    }

    $path = cachePath($fileName);
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

    $decoded['_cache_backend'] = 'file';
    return $decoded;
}

function cacheWrite(string $key, string $fileName, array $payload): void
{
    $payload['cached_at'] = $payload['cached_at'] ?? gmdate('c');
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($encoded)) {
        return;
    }

    $redis = redisClient();
    if ($redis instanceof Redis) {
        try {
            $redis->set($key, $encoded);
        } catch (Throwable) {
            // File cache still runs below.
        }
    }

    ensureCacheDir();
    @file_put_contents(cachePath($fileName), $encoded, LOCK_EX);
}

function aviationUsageKey(): string
{
    return 'flight_tracker:v5:aviationstack_usage:' . gmdate('Y-m-d');
}

function aviationUsageFile(): string
{
    return cachePath('aviationstack-usage-v5-' . gmdate('Y-m-d'));
}

function aviationUsage(): int
{
    $redis = redisClient();

    if ($redis instanceof Redis) {
        try {
            $value = $redis->get(aviationUsageKey());
            if ($value !== false) {
                return max(0, (int)$value);
            }
        } catch (Throwable) {
            // File fallback below.
        }
    }

    $path = aviationUsageFile();
    if (!is_file($path)) {
        return 0;
    }

    $decoded = json_decode((string)@file_get_contents($path), true);
    return max(0, (int)($decoded['successful_requests'] ?? 0));
}

function incrementAviationUsage(): void
{
    $redis = redisClient();

    if ($redis instanceof Redis) {
        try {
            $key = aviationUsageKey();
            $redis->incr($key);
            $redis->expire($key, 259200);
            return;
        } catch (Throwable) {
            // File fallback below.
        }
    }

    ensureCacheDir();
    $count = aviationUsage() + 1;
    @file_put_contents(
        aviationUsageFile(),
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
            CURLOPT_USERAGENT => 'SuhailCivilianFlightTracker/2.0',
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
            'header' => "Accept: application/json\r\nUser-Agent: SuhailCivilianFlightTracker/2.0\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches)) {
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

function waitForLiveProviderSlot(int $minIntervalMs = 1200): void
{
    $redis = redisClient();

    if ($redis instanceof Redis) {
        $key = 'flight_tracker:v7:live_provider_lock';
        $deadline = microtime(true) + 4.0;

        while (microtime(true) < $deadline) {
            try {
                $acquired = $redis->set($key, (string)microtime(true), ['nx', 'px' => $minIntervalMs]);
                if ($acquired) {
                    return;
                }
            } catch (Throwable) {
                break;
            }

            usleep(150000);
        }

        return;
    }

    // Conservative fallback for environments without Redis.
    usleep($minIntervalMs * 1000);
}

function fetchRegionalAircraft(array $region, int $timeout): array
{
    $providers = [
        [
            'name' => 'airplanes.live',
            'url' => sprintf(
                'https://api.airplanes.live/v2/point/%s/%s/%s',
                rawurlencode((string)$region['lat']),
                rawurlencode((string)$region['lon']),
                rawurlencode((string)$region['radius_nm'])
            ),
        ],
        [
            'name' => 'adsb.lol',
            'url' => sprintf(
                'https://api.adsb.lol/v2/point/%s/%s/%s',
                rawurlencode((string)$region['lat']),
                rawurlencode((string)$region['lon']),
                rawurlencode((string)$region['radius_nm'])
            ),
        ],
    ];

    $errors = [];

    foreach ($providers as $provider) {
        waitForLiveProviderSlot();
        $response = providerRequest($provider['url'], $timeout);

        if (!$response['ok']) {
            $errors[] = [
                'provider' => $provider['name'],
                'status' => $response['status'] ?? 0,
                'error' => $response['error'] ?? '',
            ];
            continue;
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !is_array($decoded['ac'] ?? null)) {
            $errors[] = [
                'provider' => $provider['name'],
                'status' => $response['status'] ?? 0,
                'error' => 'Invalid live-position payload.',
            ];
            continue;
        }

        // A successful response is accepted even when the region has zero
        // aircraft; that is truthful provider coverage for that query.
        return [
            'ok' => true,
            'provider' => $provider['name'],
            'aircraft' => $decoded['ac'],
            'source_total' => (int)($decoded['total'] ?? count($decoded['ac'])),
            'errors' => $errors,
        ];
    }

    return [
        'ok' => false,
        'provider' => null,
        'aircraft' => [],
        'source_total' => 0,
        'errors' => $errors,
    ];
}

function blockedOperator(string $airlineName, string $flightNumber = ''): bool
{
    $blockedWords = [
        'military', 'air force', 'army', 'navy', 'government',
        'ministry', 'defence', 'defense', 'police', 'coast guard',
    ];

    $haystack = strtolower(trim($airlineName . ' ' . $flightNumber));

    foreach ($blockedWords as $word) {
        if (str_contains($haystack, $word)) {
            return true;
        }
    }

    return false;
}

function isAllowedCivilianFlight(array $row): bool
{
    $airlineName = trim((string)($row['airline']['name'] ?? ''));
    $airlineIata = strtoupper(trim((string)($row['airline']['iata'] ?? '')));
    $flightNumber = trim((string)($row['flight']['iata'] ?? $row['flight']['icao'] ?? $row['flight']['number'] ?? ''));

    if ($airlineName === '' || $flightNumber === '') {
        return false;
    }

    if ($airlineIata !== '' && !preg_match('/^[A-Z0-9]{2,3}$/', $airlineIata)) {
        return false;
    }

    return !blockedOperator($airlineName, $flightNumber);
}

function normalizedToken(?string $value): string
{
    $value = strtoupper(trim((string)$value));
    return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
}

function metadataMatchKeys(array $row): array
{
    $keys = [];

    $flightIcao = normalizedToken((string)($row['flight']['icao'] ?? ''));
    $flightIata = normalizedToken((string)($row['flight']['iata'] ?? ''));
    $flightNumber = normalizedToken((string)($row['flight']['number'] ?? ''));
    $airlineIcao = normalizedToken((string)($row['airline']['icao'] ?? ''));
    $airlineIata = normalizedToken((string)($row['airline']['iata'] ?? ''));

    foreach ([$flightIcao, $flightIata] as $key) {
        if ($key !== '') {
            $keys[$key] = true;
        }
    }

    if ($flightNumber !== '') {
        if ($airlineIcao !== '') {
            $keys[$airlineIcao . $flightNumber] = true;
        }
        if ($airlineIata !== '') {
            $keys[$airlineIata . $flightNumber] = true;
        }
    }

    return array_keys($keys);
}

function adsbAircraftToLive(array $aircraft): ?array
{
    $lat = $aircraft['lat'] ?? null;
    $lon = $aircraft['lon'] ?? null;
    $altitude = $aircraft['alt_geom'] ?? $aircraft['alt_baro'] ?? null;

    if (!is_numeric($lat) || !is_numeric($lon)) {
        return null;
    }

    if (is_string($altitude) && strtolower($altitude) === 'ground') {
        return null;
    }

    return [
        'latitude' => (float)$lat,
        'longitude' => (float)$lon,
        'altitude' => is_numeric($altitude) ? (float)$altitude * 0.3048 : null,
        'speed_horizontal' => is_numeric($aircraft['gs'] ?? null)
            ? (float)$aircraft['gs'] * 1.852
            : null,
        'direction' => is_numeric($aircraft['track'] ?? null)
            ? (float)$aircraft['track']
            : null,
        'updated' => time() - (int)round((float)($aircraft['seen'] ?? 0)),
    ];
}

function requestRegion(): array
{
    $lat = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float)$_GET['lat'] : 34.5553;
    $lon = isset($_GET['lon']) && is_numeric($_GET['lon']) ? (float)$_GET['lon'] : 69.2075;
    $radius = isset($_GET['radius']) && is_numeric($_GET['radius']) ? (float)$_GET['radius'] : 250.0;

    $lat = max(-90.0, min(90.0, $lat));
    $lon = max(-180.0, min(180.0, $lon));
    $radius = max(10.0, min(250.0, $radius));

    return [
        'lat' => round($lat, 3),
        'lon' => round($lon, 3),
        'radius_nm' => round($radius, 1),
    ];
}

function regionCacheId(array $region): string
{
    return sha1(
        number_format((float)$region['lat'], 3, '.', '') . '|' .
        number_format((float)$region['lon'], 3, '.', '') . '|' .
        number_format((float)$region['radius_nm'], 1, '.', '')
    );
}

function normalizeFlight(array $row, string $positionSource): ?array
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
    $airlineIcao = (string)($row['airline']['icao'] ?? '');
    $flightNumber = (string)($row['flight']['iata'] ?? $row['flight']['icao'] ?? $row['flight']['number'] ?? '');
    $registration = (string)($row['aircraft']['registration'] ?? '');
    $aircraftIata = (string)($row['aircraft']['iata'] ?? '');
    $aircraftIcao = (string)($row['aircraft']['icao'] ?? '');
    $icao24 = strtolower((string)($row['aircraft']['icao24'] ?? ''));
    $type = trim($aircraftIata . ($aircraftIcao ? ' / ' . $aircraftIcao : ''));

    return [
        'id' => sha1($airlineIcao . '|' . $flightNumber . '|' . $registration . '|' . $icao24),
        'flight_number' => $flightNumber,
        'airline' => $airlineName,
        'airline_iata' => $airlineIata,
        'airline_icao' => $airlineIcao,
        'status' => (string)($row['flight_status'] ?? 'active'),
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
            'type' => $type ?: 'Commercial aircraft',
            'icao24' => $icao24,
        ],
        'camera' => null,
        'position_source' => $positionSource,
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

function demoFlights(): array
{
    $now = gmdate('c');

    return [[
        'id' => 'demo-EK201',
        'flight_number' => 'EK201',
        'airline' => 'Emirates',
        'airline_iata' => 'EK',
        'airline_icao' => 'UAE',
        'status' => 'active',
        'departure' => ['iata' => 'DXB', 'airport' => 'Dubai International'],
        'arrival' => ['iata' => 'JFK', 'airport' => 'John F. Kennedy International'],
        'aircraft' => ['registration' => 'DEMO-001', 'type' => 'Boeing 777'],
        'camera' => null,
        'position_source' => 'fictional-demo',
        'live' => ['lat' => 43.2, 'lon' => 24.4, 'altitude_m' => 10600, 'speed_kmh' => 890, 'direction' => 305],
        'updated_at' => $now,
    ]];
}

$mode = (string)($config['mode'] ?? 'demo');

if ($mode !== 'live') {
    $demo = demoFlights();
    jsonResponse([
        'ok' => true,
        'mode' => 'demo',
        'provider' => 'fictional-demo',
        'coverage_status' => 'demo',
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
        'error' => 'Live mode is enabled but the Aviationstack API key is missing.',
    ], 500);
}

$finalCacheSeconds = max(30, (int)($config['cache_seconds'] ?? 90));
$metadataCacheSeconds = max(3600, (int)($config['metadata_cache_seconds'] ?? 25200));
$livePositionCacheSeconds = max(30, (int)($config['live_position_cache_seconds'] ?? 90));
$timeout = max(5, min(30, (int)($config['http_timeout_seconds'] ?? 15)));
$requestLimit = max(10, min(100, (int)($config['request_limit'] ?? 100)));
$dailyLimit = max(0, (int)($config['max_provider_requests_per_day'] ?? 3));
$region = requestRegion();
$regionId = regionCacheId($region);

$finalCached = cacheRead(
    'flight_tracker:v7:final:' . $regionId,
    'final-v7-' . $regionId,
    $finalCacheSeconds
);

if ($finalCached !== null) {
    unset($finalCached['_cache_backend']);
    $finalCached['served_from_cache'] = true;
    jsonResponse($finalCached);
}

// 1) Get commercial flight metadata from Aviationstack.
// This data is long-cached because the free plan has only 100 requests/month.
$metadataPayload = cacheRead(
    'flight_tracker:v5:aviation_metadata',
    'aviation-metadata-v5',
    $metadataCacheSeconds
);

if ($metadataPayload === null) {
    $usedToday = aviationUsage();
    $remainingBudget = $dailyLimit > 0 ? max(0, $dailyLimit - $usedToday) : 3;

    if ($remainingBudget <= 0) {
        $metadataPayload = cacheRead(
            'flight_tracker:v5:aviation_metadata',
            'aviation-metadata-v5',
            $metadataCacheSeconds,
            true
        );
    } else {
        $pageBudget = min(3, $remainingBudget);
        $rows = [];

        for ($page = 0; $page < $pageBudget; $page++) {
            $params = http_build_query([
                'access_key' => $key,
                'flight_status' => 'active',
                'limit' => $requestLimit,
                'offset' => $page * $requestLimit,
            ]);

            $response = providerRequest(
                'https://api.aviationstack.com/v1/flights?' . $params,
                $timeout
            );

            if (!$response['ok']) {
                break;
            }

            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded) || isset($decoded['error'])) {
                break;
            }

            $pageRows = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
            foreach ($pageRows as $row) {
                if (is_array($row) && isAllowedCivilianFlight($row)) {
                    $rows[] = $row;
                }
            }

            incrementAviationUsage();

            if (count($pageRows) < $requestLimit) {
                break;
            }
        }

        if ($rows !== []) {
            $metadataPayload = [
                'cached_at' => gmdate('c'),
                'data' => $rows,
            ];

            cacheWrite(
                'flight_tracker:v5:aviation_metadata',
                'aviation-metadata-v5',
                $metadataPayload
            );
        }
    }
}

if (!is_array($metadataPayload) || !is_array($metadataPayload['data'] ?? null)) {
    jsonResponse([
        'ok' => false,
        'mode' => 'live',
        'coverage_status' => 'metadata_unavailable',
        'error' => 'Commercial flight metadata is temporarily unavailable. This does not mean there are no flights.',
    ], 503);
}

$metadataRows = $metadataPayload['data'];
unset($metadataPayload['_cache_backend']);

// 2) Get current regional positions from the free live-provider chain.
// Airplanes.live is primary because it publishes a clear 1 request/second limit.
// ADSB.lol remains a fallback. Raw records are never returned directly.
$livePayload = cacheRead(
    'flight_tracker:v7:live:' . $regionId,
    'live-v7-' . $regionId,
    $livePositionCacheSeconds
);

if ($livePayload === null) {
    $liveResult = fetchRegionalAircraft($region, $timeout);

    if ($liveResult['ok']) {
        $livePayload = [
            'cached_at' => gmdate('c'),
            'provider' => $liveResult['provider'],
            'aircraft' => $liveResult['aircraft'],
            'source_total' => $liveResult['source_total'],
        ];

        cacheWrite(
            'flight_tracker:v7:live:' . $regionId,
            'live-v7-' . $regionId,
            $livePayload
        );

        if (!empty($liveResult['errors'])) {
            error_log('LIVE_PROVIDER_FALLBACK ' . json_encode([
                'region' => $region,
                'selected_provider' => $liveResult['provider'],
                'prior_errors' => $liveResult['errors'],
            ], JSON_UNESCAPED_SLASHES));
        }
    } else {
        error_log('LIVE_PROVIDER_ERROR ' . json_encode([
            'region' => $region,
            'errors' => $liveResult['errors'],
        ], JSON_UNESCAPED_SLASHES));

        $livePayload = cacheRead(
            'flight_tracker:v7:live:' . $regionId,
            'live-v7-' . $regionId,
            $livePositionCacheSeconds,
            true
        );
    }
}

if (!is_array($livePayload) || !is_array($livePayload['aircraft'] ?? null)) {
    jsonResponse([
        'ok' => true,
        'mode' => 'live',
        'provider' => 'aviationstack+regional-adsb',
        'coverage_status' => 'live_positions_unavailable',
        'safety' => 'civilian-commercial-only',
        'region' => $region,
        'count' => 0,
        'data' => [],
        'coverage_stats' => [
            'aviationstack_commercial_records' => count($metadataRows),
            'regional_adsb_records' => 0,
            'displayable_records' => 0,
        ],
        'stale' => false,
        'cached_at' => gmdate('c'),
        'message' => 'Commercial flight metadata exists, but the regional live-position providers are temporarily unavailable. This does not mean there are no real flights.',
    ]);
}

$regionalAircraft = $livePayload['aircraft'];
$liveProviderName = (string)($livePayload['provider'] ?? 'regional-adsb');
unset($livePayload['_cache_backend']);

// Build safe commercial indexes from Aviationstack metadata.
$exactCallsignIndex = [];
$icao24Index = [];
$commercialPrefixIndex = [];

foreach ($metadataRows as $rowIndex => $row) {
    if (!is_array($row) || !isAllowedCivilianFlight($row)) {
        continue;
    }

    foreach (metadataMatchKeys($row) as $keyName) {
        $exactCallsignIndex[$keyName] = $rowIndex;
    }

    $icao24 = strtolower(trim((string)($row['aircraft']['icao24'] ?? '')));
    if ($icao24 !== '') {
        $icao24Index[$icao24] = $rowIndex;
    }

    $airlineIcao = normalizedToken((string)($row['airline']['icao'] ?? ''));
    $airlineName = trim((string)($row['airline']['name'] ?? ''));

    if (strlen($airlineIcao) === 3 && $airlineName !== '' && !blockedOperator($airlineName)) {
        $commercialPrefixIndex[$airlineIcao] = [
            'name' => $airlineName,
            'iata' => (string)($row['airline']['iata'] ?? ''),
            'icao' => (string)($row['airline']['icao'] ?? ''),
        ];
    }
}

$display = [];
$seen = [];
$matchedExact = 0;
$matchedCommercialPrefix = 0;
$directAviationstackLive = 0;

// Keep any Aviationstack record that unexpectedly includes coordinates.
foreach ($metadataRows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $live = is_array($row['live'] ?? null) ? $row['live'] : [];
    if (is_numeric($live['latitude'] ?? null) && is_numeric($live['longitude'] ?? null)) {
        $flight = normalizeFlight($row, 'aviationstack');
        if ($flight !== null && !isset($seen[$flight['id']])) {
            $seen[$flight['id']] = true;
            $display[] = $flight;
            $directAviationstackLive++;
        }
    }
}

// Enrich commercial metadata with regional ADSB.lol live positions.
foreach ($regionalAircraft as $aircraft) {
    if (!is_array($aircraft)) {
        continue;
    }

    // ADSBExchange-compatible dbFlags uses bit 1 for military classification.
    // Reject it before any matching or browser-visible processing.
    $dbFlags = is_numeric($aircraft['dbFlags'] ?? null) ? (int)$aircraft['dbFlags'] : 0;
    if (($dbFlags & 1) === 1) {
        continue;
    }

    $live = adsbAircraftToLive($aircraft);
    if ($live === null) {
        continue;
    }

    $callsign = normalizedToken((string)($aircraft['flight'] ?? ''));
    $icao24 = strtolower(trim((string)($aircraft['hex'] ?? '')));

    if ($callsign === '') {
        continue;
    }

    $rowIndex = null;

    if ($icao24 !== '' && isset($icao24Index[$icao24])) {
        $rowIndex = $icao24Index[$icao24];
    } elseif (isset($exactCallsignIndex[$callsign])) {
        $rowIndex = $exactCallsignIndex[$callsign];
    }

    if ($rowIndex !== null && isset($metadataRows[$rowIndex])) {
        $row = $metadataRows[$rowIndex];
        $row['live'] = $live;

        if (($row['aircraft']['registration'] ?? '') === '' && !empty($aircraft['r'])) {
            $row['aircraft']['registration'] = (string)$aircraft['r'];
        }
        if (($row['aircraft']['icao'] ?? '') === '' && !empty($aircraft['t'])) {
            $row['aircraft']['icao'] = (string)$aircraft['t'];
        }
        $row['aircraft']['icao24'] = $icao24;

        $flight = normalizeFlight($row, $liveProviderName . '+aviationstack');

        if ($flight !== null && !isset($seen[$flight['id']])) {
            $seen[$flight['id']] = true;
            $display[] = $flight;
            $matchedExact++;
        }

        continue;
    }

    // Safe fallback: only allow callsigns whose first three characters are a
    // commercial airline ICAO prefix already confirmed by Aviationstack.
    if (strlen($callsign) < 4) {
        continue;
    }

    $prefix = substr($callsign, 0, 3);
    if (!isset($commercialPrefixIndex[$prefix])) {
        continue;
    }

    $operator = $commercialPrefixIndex[$prefix];

    $syntheticRow = [
        'flight_status' => 'active',
        'airline' => [
            'name' => $operator['name'],
            'iata' => $operator['iata'],
            'icao' => $operator['icao'],
        ],
        'flight' => [
            'iata' => '',
            'icao' => $callsign,
            'number' => substr($callsign, 3),
        ],
        'departure' => [],
        'arrival' => [],
        'aircraft' => [
            'icao24' => $icao24,
            'registration' => (string)($aircraft['r'] ?? ''),
            'iata' => '',
            'icao' => (string)($aircraft['t'] ?? ''),
        ],
        'live' => $live,
    ];

    $flight = normalizeFlight($syntheticRow, $liveProviderName . '-commercial-prefix');

    if ($flight !== null && !isset($seen[$flight['id']])) {
        $seen[$flight['id']] = true;
        $display[] = $flight;
        $matchedCommercialPrefix++;
    }
}

$coverageStats = [
    'aviationstack_commercial_records' => count($metadataRows),
    'aviationstack_direct_live_positions' => $directAviationstackLive,
    'live_position_provider' => $liveProviderName,
    'regional_adsb_records' => count($regionalAircraft),
    'exact_commercial_matches' => $matchedExact,
    'commercial_prefix_matches' => $matchedCommercialPrefix,
    'displayable_records' => count($display),
];

$coverageStatus = count($display) > 0
    ? 'available'
    : 'no_matching_live_positions';

$message = count($display) > 0
    ? 'Showing current positions only for aircraft identified as civilian/commercial airline traffic.'
    : 'No matching live positions are available in the current data coverage. This does not mean there are no flights in the sky.';

$payload = [
    'ok' => true,
    'mode' => 'live',
    'provider' => 'aviationstack+' . $liveProviderName,
    'provider_plan' => (string)($config['provider_plan'] ?? 'free'),
    'coverage_status' => $coverageStatus,
    'safety' => 'civilian-commercial-only',
    'region' => $region,
    'count' => count($display),
    'data' => $display,
    'coverage_stats' => $coverageStats,
    'stale' => false,
    'served_from_cache' => false,
    'aviationstack_requests_today' => aviationUsage(),
    'aviationstack_requests_daily_limit' => $dailyLimit,
    'cached_at' => gmdate('c'),
    'message' => $message,
];

error_log('FLIGHT_COVERAGE_V7 ' . json_encode([
    'region' => $region,
    'stats' => $coverageStats,
], JSON_UNESCAPED_SLASHES));

cacheWrite(
    'flight_tracker:v7:final:' . $regionId,
    'final-v7-' . $regionId,
    $payload
);
jsonResponse($payload);
