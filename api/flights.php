<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
$localConfig = $root . '/config.local.php';
if (is_file($localConfig)) {
    $override = require $localConfig;
    if (is_array($override)) {
        $config = array_replace($config, $override);
    }
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function demoFlights(): array
{
    // Fictionalized civilian-only sample positions for UI testing.
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
        ]
    ];
}

function cacheFile(): string
{
    return dirname(__DIR__) . '/storage/cache/flights.json';
}

function readCache(int $maxAge): ?array
{
    $path = cacheFile();
    if (!is_file($path) || (time() - filemtime($path)) > $maxAge) {
        return null;
    }
    $raw = file_get_contents($path);
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : null;
}

function writeCache(array $payload): void
{
    $path = cacheFile();
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Only keep identifiable commercial airline flights.
 * This intentionally excludes unknown, government/military-like, and non-airline targets.
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

    $blockedWords = [
        'military', 'air force', 'army', 'navy', 'government',
        'ministry', 'defence', 'defense', 'police', 'coast guard'
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
    jsonResponse([
        'ok' => true,
        'mode' => 'demo',
        'safety' => 'civilian-commercial-only',
        'count' => count(demoFlights()),
        'data' => demoFlights(),
        'message' => 'Demo data is fictionalized and not real-time.'
    ]);
}

$key = trim((string)($config['aviationstack_key'] ?? ''));
if ($key === '') {
    jsonResponse([
        'ok' => false,
        'mode' => 'live',
        'error' => 'Live mode is enabled but aviationstack_key is empty.'
    ], 500);
}

$cached = readCache((int)($config['cache_seconds'] ?? 240));
if ($cached !== null) {
    jsonResponse($cached);
}

$params = http_build_query([
    'access_key' => $key,
    'flight_status' => 'active',
    'limit' => max(10, min(100, (int)($config['request_limit'] ?? 100))),
]);

$url = 'https://api.aviationstack.com/v1/flights?' . $params;

$context = stream_context_create([
    'http' => [
        'timeout' => 15,
        'ignore_errors' => true,
        'header' => "User-Agent: SuhailCivilianFlightTracker/1.0\r\n",
    ]
]);

$raw = @file_get_contents($url, false, $context);
if ($raw === false) {
    jsonResponse(['ok' => false, 'error' => 'Could not reach the flight data provider.'], 502);
}

$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    jsonResponse(['ok' => false, 'error' => 'Invalid response from the flight data provider.'], 502);
}

if (isset($decoded['error'])) {
    jsonResponse([
        'ok' => false,
        'error' => (string)($decoded['error']['message'] ?? 'Flight data provider returned an error.')
    ], 502);
}

$normalized = [];
foreach (($decoded['data'] ?? []) as $row) {
    if (!is_array($row)) continue;
    $flight = normalizeFlight($row);
    if ($flight !== null) {
        $normalized[] = $flight;
    }
}

$payload = [
    'ok' => true,
    'mode' => 'live',
    'safety' => 'civilian-commercial-only',
    'count' => count($normalized),
    'data' => $normalized,
    'message' => 'Only identifiable commercial airline flights with live coordinates are shown.'
];

writeCache($payload);
jsonResponse($payload);
