<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$config = require __DIR__ . '/config.php';
$localConfig = __DIR__ . '/config.local.php';

if (is_file($localConfig)) {
    $override = require $localConfig;
    if (is_array($override)) {
        $config = array_replace($config, $override);
    }
}

$appName = $config['app_name'] ?? 'Suhail Civilian Flight Tracker';
$isDemo = ($config['mode'] ?? 'demo') !== 'live';
?>
<!doctype html>
<html lang="ps" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0f172a">
  <meta name="description" content="Civilian and commercial flight tracking interface by Suhail Labs.">
  <title><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="preconnect" href="https://unpkg.com">
  <link rel="preconnect" href="https://tile.openstreetmap.org">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">
    <div class="brand-mark">✈</div>
    <div>
      <strong><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></strong>
      <small>Commercial & civilian flights only</small>
    </div>
  </div>

  <div class="top-actions">
    <span id="modeBadge" class="badge <?= $isDemo ? 'warn' : 'ok' ?>"><?= $isDemo ? 'DEMO' : 'LIVE' ?></span>
    <button id="refreshBtn" class="btn primary" type="button">تازه کول</button>
  </div>
</header>

<main class="layout">
  <section class="sidebar">
    <div class="notice">
      <strong>ملکي الوتنې</strong>
      <span>دا سیستم قصداً پوځي او حساس الوتنې نه ښيي.</span>
    </div>

    <div class="stats">
      <article class="stat-card">
        <span>ټولې ښکاره الوتنې</span>
        <strong id="totalFlights">0</strong>
      </article>
      <article class="stat-card">
        <span>په هوا کې</span>
        <strong id="airborneFlights">0</strong>
      </article>
      <article class="stat-card">
        <span>Airlines</span>
        <strong id="airlineCount">0</strong>
      </article>
    </div>

    <div class="panel">
      <h2>لټون او فلټر</h2>

      <label>
        Flight / Airline / Airport
        <input id="searchInput" type="search" autocomplete="off" placeholder="مثلاً EK, Dubai, KBL">
      </label>

      <div class="grid2">
        <label>
          له کوم ځایه
          <input id="originInput" type="text" autocomplete="off" placeholder="IATA / Airport">
        </label>

        <label>
          کوم ځای ته
          <input id="destinationInput" type="text" autocomplete="off" placeholder="IATA / Airport">
        </label>
      </div>

      <label>
        حالت
        <select id="statusSelect">
          <option value="">ټول</option>
          <option value="active">په هوا کې</option>
          <option value="scheduled">Scheduled</option>
          <option value="landed">Landed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </label>

      <button id="clearFiltersBtn" class="btn ghost" type="button">فلټر پاکول</button>
    </div>

    <div class="panel flight-list-panel">
      <div class="panel-heading">
        <h2>الوتنې</h2>
        <span id="lastUpdated">—</span>
      </div>
      <div id="flightList" class="flight-list"></div>
    </div>
  </section>

  <section class="map-wrap">
    <div id="map" aria-label="Civilian flight map"></div>

    <div id="detailCard" class="detail-card hidden">
      <button id="closeDetailBtn" class="close-btn" type="button" aria-label="Close">×</button>
      <div id="detailContent"></div>
    </div>

    <div id="loading" class="loading hidden">د الوتنو معلومات رااخیستل کېږي…</div>
    <div id="errorBox" class="error-box hidden" role="status" aria-live="polite"></div>
  </section>
</main>

<script>
window.APP_CONFIG = {
  endpoint: 'api/flights.php',
  demo: <?= $isDemo ? 'true' : 'false' ?>,
  refreshSeconds: <?= max(60, (int)($config['refresh_seconds'] ?? 28800)) ?>
};
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
