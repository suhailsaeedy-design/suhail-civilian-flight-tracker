<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$config = require __DIR__ . '/app_config.php';
$appName = $config['app_name'] ?? 'Suhail Civilian Flight Tracker';
$isDemo = ($config['mode'] ?? 'demo') !== 'live';
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#07111f">
  <meta name="description" content="A mobile-first 3D civilian and commercial flight tracker by Suhail Labs.">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="preconnect" href="https://unpkg.com">
  <link rel="preconnect" href="https://tiles.openfreemap.org">
  <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@6.11.2/dist/maplibre-gl.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <header class="topbar">
    <div class="brand">
      <div class="brand-mark" aria-hidden="true">✈</div>
      <div class="brand-copy">
        <strong><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></strong>
        <small>Live civilian & commercial aviation</small>
      </div>
    </div>

    <div class="top-actions">
      <span id="modeBadge" class="badge <?= $isDemo ? 'warn' : 'ok' ?>"><?= $isDemo ? 'DEMO' : 'LIVE' ?></span>
      <button id="offlineBtn" class="icon-btn" type="button" title="Keep viewed map areas available offline" aria-label="Enable offline map cache">⇩</button>
      <button id="refreshBtn" class="btn primary" type="button">Refresh</button>
    </div>
  </header>

  <main class="workspace">
    <section class="map-wrap">
      <div id="map" aria-label="3D civilian flight map"></div>

      <div class="map-toolbar">
        <button id="toggle3dBtn" class="map-tool active" type="button">3D</button>
        <button id="resetViewBtn" class="map-tool" type="button">World</button>
        <button id="filterToggleBtn" class="map-tool mobile-only" type="button">Filters</button>
      </div>

      <div id="mapStatus" class="map-status">
        <span class="pulse"></span>
        <span id="coverageLabel">Loading live coverage…</span>
      </div>

      <div id="loading" class="loading hidden">Loading live civilian flights…</div>
      <div id="errorBox" class="error-box hidden" role="status" aria-live="polite"></div>
    </section>

    <aside id="controlPanel" class="control-panel">
      <div class="panel-handle mobile-only" aria-hidden="true"></div>

      <section class="hero-panel">
        <div>
          <span class="eyebrow">LIVE COVERAGE</span>
          <h1>Explore civilian flights in 3D</h1>
          <p>Tap any aircraft for route, altitude, speed, schedule and aircraft details.</p>
        </div>
      </section>

      <section class="stats">
        <article class="stat-card">
          <span>Visible flights</span>
          <strong id="totalFlights">0</strong>
        </article>
        <article class="stat-card">
          <span>Airborne</span>
          <strong id="airborneFlights">0</strong>
        </article>
        <article class="stat-card">
          <span>Airlines</span>
          <strong id="airlineCount">0</strong>
        </article>
      </section>

      <section class="filter-card">
        <div class="section-heading">
          <div>
            <span class="eyebrow">FILTER</span>
            <h2>Find flights quickly</h2>
          </div>
          <button id="clearFiltersBtn" class="text-btn" type="button">Clear</button>
        </div>

        <label class="field">
          <span>Search</span>
          <input id="searchInput" type="search" autocomplete="off" placeholder="Flight, airline or airport">
        </label>

        <label class="field">
          <span>Country / airspace</span>
          <select id="countrySelect">
            <option value="">All countries</option>
          </select>
          <small class="field-help">Shows aircraft currently inside the selected country boundary.</small>
        </label>

        <div class="filter-row">
          <button class="chip active" type="button" data-status="">All</button>
          <button class="chip" type="button" data-status="active">Airborne</button>
        </div>
      </section>

      <section class="list-card">
        <div class="section-heading sticky-heading">
          <div>
            <span class="eyebrow">FLIGHTS</span>
            <h2>Current results</h2>
          </div>
          <span id="lastUpdated" class="timestamp">—</span>
        </div>
        <div id="flightList" class="flight-list"></div>
      </section>

      <footer class="sidebar-footer">
        <span>Civilian/commercial flights only.</span>
        <span>3D map: MapLibre + OpenFreeMap.</span>
      </footer>
    </aside>

    <section id="detailCard" class="detail-sheet hidden" aria-live="polite">
      <div class="detail-grabber mobile-only"></div>
      <button id="closeDetailBtn" class="close-btn" type="button" aria-label="Close flight details">×</button>
      <div id="detailContent"></div>
    </section>

    <div id="toast" class="toast hidden" role="status" aria-live="polite"></div>
  </main>
</div>

<script>
window.APP_CONFIG = {
  endpoint: 'api/flights.php',
  demo: <?= $isDemo ? 'true' : 'false' ?>,
  refreshSeconds: <?= max(60, (int)($config['refresh_seconds'] ?? 28800)) ?>,
  mapStyle: 'https://tiles.openfreemap.org/styles/liberty',
  countryGeoJson: 'https://raw.githubusercontent.com/datasets/geo-countries/master/data/countries.geojson'
};
</script>
<script type="module" src="assets/js/app.js"></script>
</body>
</html>
