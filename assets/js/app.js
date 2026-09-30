import * as maplibregl from 'https://unpkg.com/maplibre-gl@6.11.2/dist/maplibre-gl.mjs';

const cfg = window.APP_CONFIG || {};
const $ = id => document.getElementById(id);

const els = {
  total: $('totalFlights'),
  airborne: $('airborneFlights'),
  airlines: $('airlineCount'),
  list: $('flightList'),
  search: $('searchInput'),
  country: $('countrySelect'),
  refresh: $('refreshBtn'),
  clear: $('clearFiltersBtn'),
  lastUpdated: $('lastUpdated'),
  loading: $('loading'),
  error: $('errorBox'),
  detail: $('detailCard'),
  detailContent: $('detailContent'),
  closeDetail: $('closeDetailBtn'),
  modeBadge: $('modeBadge'),
  coverage: $('coverageLabel'),
  toggle3d: $('toggle3dBtn'),
  resetView: $('resetViewBtn'),
  filterToggle: $('filterToggleBtn'),
  controlPanel: $('controlPanel'),
  offline: $('offlineBtn'),
  toast: $('toast')
};

const state = {
  allFlights: [],
  markers: new Map(),
  countries: [],
  countryByCode: new Map(),
  selectedStatus: '',
  map3d: true,
  countryReady: false
};

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[ch]));
}

function number(value, digits = 0) {
  return Number.isFinite(Number(value)) ? Number(value).toFixed(digits) : '—';
}

function statusLabel(status) {
  const labels = {
    active: 'Airborne',
    scheduled: 'Scheduled',
    landed: 'Landed',
    cancelled: 'Cancelled',
    incident: 'Incident',
    diverted: 'Diverted'
  };
  return labels[String(status || '').toLowerCase()] || (status || 'Unknown');
}

function formatTime(value) {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleString([], {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function showMessage(message, kind = 'error') {
  els.error.textContent = message;
  els.error.classList.toggle('warning', kind === 'warning');
  els.error.classList.remove('hidden');
}

function clearMessage() {
  els.error.classList.add('hidden');
  els.error.classList.remove('warning');
}

let toastTimer;
function toast(message) {
  els.toast.textContent = message;
  els.toast.classList.remove('hidden');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => els.toast.classList.add('hidden'), 4200);
}

function setModeBadge(payload) {
  els.modeBadge.classList.remove('ok', 'warn');

  if (payload?.stale) {
    els.modeBadge.textContent = 'CACHED';
    els.modeBadge.classList.add('warn');
    return;
  }

  if (payload?.mode === 'live') {
    els.modeBadge.textContent = 'LIVE';
    els.modeBadge.classList.add('ok');
    return;
  }

  els.modeBadge.textContent = 'DEMO';
  els.modeBadge.classList.add('warn');
}

const map = new maplibregl.Map({
  container: 'map',
  style: cfg.mapStyle,
  center: [35, 28],
  zoom: 2.3,
  pitch: 42,
  bearing: -8,
  minZoom: 1.4,
  maxZoom: 18,
  attributionControl: true,
  canvasContextAttributes: { antialias: true }
});

map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'bottom-right');
map.addControl(new maplibregl.FullscreenControl(), 'bottom-right');

map.on('load', () => {
  add3DBuildings();

  if (!map.getSource('country-highlight')) {
    map.addSource('country-highlight', {
      type: 'geojson',
      data: { type: 'FeatureCollection', features: [] }
    });

    map.addLayer({
      id: 'country-highlight-fill',
      type: 'fill',
      source: 'country-highlight',
      paint: {
        'fill-color': '#0ea5e9',
        'fill-opacity': 0.08
      }
    });

    map.addLayer({
      id: 'country-highlight-line',
      type: 'line',
      source: 'country-highlight',
      paint: {
        'line-color': '#38bdf8',
        'line-width': 2,
        'line-opacity': 0.85
      }
    });
  }
});

function add3DBuildings() {
  if (map.getLayer('3d-buildings')) return;

  const layers = map.getStyle()?.layers || [];
  const labelLayer = layers.find(layer => layer.type === 'symbol' && layer.layout?.['text-field']);

  if (!map.getSource('openfreemap-3d')) {
    map.addSource('openfreemap-3d', {
      type: 'vector',
      url: 'https://tiles.openfreemap.org/planet'
    });
  }

  map.addLayer({
    id: '3d-buildings',
    source: 'openfreemap-3d',
    'source-layer': 'building',
    type: 'fill-extrusion',
    minzoom: 14.5,
    filter: ['!=', ['get', 'hide_3d'], true],
    paint: {
      'fill-extrusion-color': [
        'interpolate',
        ['linear'],
        ['coalesce', ['get', 'render_height'], 0],
        0, '#cbd5e1',
        120, '#93c5fd',
        300, '#38bdf8'
      ],
      'fill-extrusion-height': [
        'interpolate',
        ['linear'],
        ['zoom'],
        14.5, 0,
        16, ['coalesce', ['get', 'render_height'], 12]
      ],
      'fill-extrusion-base': ['coalesce', ['get', 'render_min_height'], 0],
      'fill-extrusion-opacity': 0.74
    }
  }, labelLayer?.id);
}

function clearMarkers() {
  state.markers.forEach(marker => marker.remove());
  state.markers.clear();
}

function createPlaneElement(flight) {
  const el = document.createElement('button');
  el.type = 'button';
  el.className = 'plane-marker';
  el.setAttribute('aria-label', `${flight.flight_number || 'Flight'} ${flight.airline || ''}`);

  const core = document.createElement('span');
  core.className = 'plane-core';
  const direction = Number.isFinite(Number(flight.live?.direction)) ? Number(flight.live.direction) : 0;
  core.style.transform = `rotate(${direction}deg)`;
  core.textContent = '✈';

  const label = document.createElement('span');
  label.className = 'plane-label';
  label.textContent = flight.flight_number || '';

  el.append(core, label);
  el.addEventListener('click', () => showDetail(flight));
  return el;
}

function renderMap(flights, fit = false) {
  clearMarkers();
  const bounds = new maplibregl.LngLatBounds();

  flights.forEach(flight => {
    const lat = Number(flight.live?.lat);
    const lon = Number(flight.live?.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

    const marker = new maplibregl.Marker({
      element: createPlaneElement(flight),
      anchor: 'center'
    })
      .setLngLat([lon, lat])
      .setPopup(new maplibregl.Popup({
        offset: 28,
        closeButton: false,
        className: 'flight-popup'
      }).setHTML(`
        <strong>${esc(flight.flight_number || 'Flight')}</strong>
        <span>${esc(flight.airline || '')}</span>
      `))
      .addTo(map);

    state.markers.set(flight.id, marker);
    bounds.extend([lon, lat]);
  });

  if (fit && !bounds.isEmpty()) {
    map.fitBounds(bounds, {
      padding: window.innerWidth < 760 ? 70 : 100,
      maxZoom: 5.5,
      duration: 850
    });
  }
}

function filters() {
  return {
    q: els.search.value.trim().toLowerCase(),
    country: els.country.value,
    status: state.selectedStatus
  };
}

function filteredFlights() {
  const f = filters();

  return state.allFlights.filter(flight => {
    const haystack = [
      flight.flight_number,
      flight.airline,
      flight.airline_iata,
      flight.departure?.iata,
      flight.departure?.airport,
      flight.arrival?.iata,
      flight.arrival?.airport,
      flight.aircraft?.registration,
      flight.aircraft?.type
    ].join(' ').toLowerCase();

    return (!f.q || haystack.includes(f.q))
      && (!f.country || flight._countryCode === f.country)
      && (!f.status || String(flight.status || '').toLowerCase() === f.status);
  });
}

function renderStats(flights) {
  els.total.textContent = flights.length;
  els.airborne.textContent = flights.filter(f => String(f.status).toLowerCase() === 'active').length;
  els.airlines.textContent = new Set(
    flights.map(f => f.airline_iata || f.airline).filter(Boolean)
  ).size;
}

function renderList(flights) {
  if (!flights.length) {
    els.list.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon">✈</div>
        <strong>No flights found</strong>
        <span>Try another country or clear the search.</span>
      </div>
    `;
    return;
  }

  els.list.innerHTML = flights.map(f => `
    <button class="flight-item" type="button" data-flight-id="${esc(f.id)}">
      <span class="flight-item-main">
        <span class="flight-badge">✈</span>
        <span>
          <strong>${esc(f.flight_number || '—')}</strong>
          <small>${esc(f.airline || 'Unknown airline')}</small>
        </span>
      </span>
      <span class="flight-route">
        <b>${esc(f.departure?.iata || '—')}</b>
        <i></i>
        <b>${esc(f.arrival?.iata || '—')}</b>
      </span>
      <span class="flight-meta">
        <span>${esc(statusLabel(f.status))}</span>
        <span>${number(f.live?.altitude_m)} m</span>
      </span>
    </button>
  `).join('');

  els.list.querySelectorAll('.flight-item').forEach(item => {
    item.addEventListener('click', () => {
      const flight = flights.find(x => x.id === item.dataset.flightId);
      if (!flight) return;

      showDetail(flight);
      const marker = state.markers.get(flight.id);
      if (marker) {
        map.flyTo({
          center: marker.getLngLat(),
          zoom: Math.max(map.getZoom(), 6.5),
          pitch: state.map3d ? 55 : 0,
          duration: 900
        });
        marker.togglePopup();
      }
    });
  });
}

function showDetail(f) {
  const dep = f.departure || {};
  const arr = f.arrival || {};
  const live = f.live || {};
  const aircraft = f.aircraft || {};
  const camera = f.camera || null;

  els.detailContent.innerHTML = `
    <div class="detail-header">
      <div class="detail-plane">✈</div>
      <div>
        <span class="eyebrow">${esc(statusLabel(f.status))}</span>
        <h2>${esc(f.flight_number || 'Unknown flight')}</h2>
        <p>${esc(f.airline || 'Unknown airline')}</p>
      </div>
    </div>

    <div class="route-card">
      <div>
        <strong>${esc(dep.iata || '—')}</strong>
        <span>${esc(dep.airport || 'Unknown departure')}</span>
        <small>${formatTime(dep.scheduled || dep.estimated)}</small>
      </div>
      <div class="route-flight-line"><span>✈</span></div>
      <div>
        <strong>${esc(arr.iata || '—')}</strong>
        <span>${esc(arr.airport || 'Unknown arrival')}</span>
        <small>${formatTime(arr.estimated || arr.scheduled)}</small>
      </div>
    </div>

    <div class="detail-grid">
      <div class="detail-field"><small>Altitude</small><b>${number(live.altitude_m)} m</b></div>
      <div class="detail-field"><small>Speed</small><b>${number(live.speed_kmh)} km/h</b></div>
      <div class="detail-field"><small>Heading</small><b>${number(live.direction)}°</b></div>
      <div class="detail-field"><small>Aircraft</small><b>${esc(aircraft.type || '—')}</b></div>
      <div class="detail-field"><small>Registration</small><b>${esc(aircraft.registration || '—')}</b></div>
      <div class="detail-field"><small>Current country</small><b>${esc(countryName(f._countryCode) || 'Open airspace / unknown')}</b></div>
    </div>

    <div class="schedule-card">
      <div>
        <span>Departure</span>
        <b>${formatTime(dep.scheduled)}</b>
        <small>${dep.terminal ? `Terminal ${esc(dep.terminal)}` : 'Terminal —'}${dep.gate ? ` · Gate ${esc(dep.gate)}` : ''}</small>
      </div>
      <div>
        <span>Arrival</span>
        <b>${formatTime(arr.estimated || arr.scheduled)}</b>
        <small>${arr.terminal ? `Terminal ${esc(arr.terminal)}` : 'Terminal —'}${arr.gate ? ` · Gate ${esc(arr.gate)}` : ''}</small>
      </div>
    </div>

    <div class="camera-card ${camera?.url ? 'available' : ''}">
      <div>
        <span class="eyebrow">PASSENGER CAMERA</span>
        <strong>${camera?.url ? 'Official public feed available' : 'Not available for this flight'}</strong>
        <p>Only an airline's official public passenger-facing live feed can be shown here.</p>
      </div>
      ${camera?.url
        ? `<a class="btn primary camera-link" href="${esc(camera.url)}" target="_blank" rel="noopener noreferrer">Open live camera</a>`
        : '<button class="btn disabled" type="button" disabled>No public feed</button>'}
    </div>
  `;

  els.detail.classList.remove('hidden');
}

function render({ fit = false } = {}) {
  const flights = filteredFlights();
  renderStats(flights);
  renderList(flights);
  renderMap(flights, fit);

  const country = els.country.value;
  if (country) {
    const name = countryName(country);
    els.coverage.textContent = `${flights.length} tracked commercial flights currently over ${name}`;
  } else {
    els.coverage.textContent = `${flights.length} live commercial flight records in current free-data coverage`;
  }
}

function updateTimestamp(payload) {
  const raw = payload?.cached_at
    || payload?.data?.map(f => f.updated_at).filter(Boolean).sort().at(-1)
    || null;

  els.lastUpdated.textContent = raw
    ? new Date(raw).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : 'Just now';
}

function countryName(code) {
  return state.countryByCode.get(code)?.properties?.name || '';
}

function geometryRings(geometry) {
  if (!geometry) return [];
  if (geometry.type === 'Polygon') return [geometry.coordinates];
  if (geometry.type === 'MultiPolygon') return geometry.coordinates;
  return [];
}

function pointInRing(point, ring) {
  const [x, y] = point;
  let inside = false;

  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const [xi, yi] = ring[i];
    const [xj, yj] = ring[j];

    const intersects = ((yi > y) !== (yj > y))
      && (x < ((xj - xi) * (y - yi)) / ((yj - yi) || Number.EPSILON) + xi);

    if (intersects) inside = !inside;
  }

  return inside;
}

function pointInFeature(point, feature) {
  for (const polygon of geometryRings(feature.geometry)) {
    if (!polygon.length) continue;
    if (!pointInRing(point, polygon[0])) continue;

    const inHole = polygon.slice(1).some(hole => pointInRing(point, hole));
    if (!inHole) return true;
  }
  return false;
}

function featureBounds(feature) {
  const bounds = new maplibregl.LngLatBounds();

  const walk = coords => {
    if (!Array.isArray(coords)) return;
    if (typeof coords[0] === 'number' && typeof coords[1] === 'number') {
      bounds.extend(coords);
      return;
    }
    coords.forEach(walk);
  };

  walk(feature.geometry?.coordinates);
  return bounds;
}

function assignCountries() {
  if (!state.countryReady) return;

  state.allFlights.forEach(flight => {
    const lon = Number(flight.live?.lon);
    const lat = Number(flight.live?.lat);
    flight._countryCode = '';

    if (!Number.isFinite(lon) || !Number.isFinite(lat)) return;

    for (const feature of state.countries) {
      if (pointInFeature([lon, lat], feature)) {
        flight._countryCode = feature.properties?.['ISO3166-1-Alpha-2'] || '';
        break;
      }
    }
  });
}

async function loadCountries() {
  els.country.disabled = true;

  try {
    const response = await fetch(cfg.countryGeoJson, { cache: 'force-cache' });
    if (!response.ok) throw new Error('Country boundaries unavailable.');

    const geo = await response.json();
    state.countries = Array.isArray(geo.features)
      ? geo.features.filter(f => f.properties?.['ISO3166-1-Alpha-2'] && f.properties?.['ISO3166-1-Alpha-2'] !== '-99')
      : [];

    state.countryByCode = new Map(
      state.countries.map(feature => [feature.properties['ISO3166-1-Alpha-2'], feature])
    );

    const options = state.countries
      .map(feature => ({
        code: feature.properties['ISO3166-1-Alpha-2'],
        name: feature.properties.name
      }))
      .sort((a, b) => a.name.localeCompare(b.name));

    els.country.insertAdjacentHTML(
      'beforeend',
      options.map(item => `<option value="${esc(item.code)}">${esc(item.name)}</option>`).join('')
    );

    state.countryReady = true;
    assignCountries();
    render();
  } catch {
    showMessage('Country filtering is temporarily unavailable. Live map tracking still works.', 'warning');
  } finally {
    els.country.disabled = false;
  }
}

function highlightCountry(code) {
  const source = map.getSource('country-highlight');
  if (!source) return;

  if (!code) {
    source.setData({ type: 'FeatureCollection', features: [] });
    return;
  }

  const feature = state.countryByCode.get(code);
  if (!feature) return;

  source.setData({ type: 'FeatureCollection', features: [feature] });
  const bounds = featureBounds(feature);

  if (!bounds.isEmpty()) {
    map.fitBounds(bounds, {
      padding: window.innerWidth < 760 ? 38 : 80,
      duration: 900,
      maxZoom: 6
    });
  }
}

async function loadFlights() {
  clearMessage();
  els.loading.classList.remove('hidden');
  els.refresh.disabled = true;

  try {
    const response = await fetch(cfg.endpoint, { cache: 'no-store' });
    const payload = await response.json().catch(() => null);

    if (!response.ok || !payload?.ok) {
      throw new Error(payload?.error || `Flight request failed (${response.status})`);
    }

    state.allFlights = Array.isArray(payload.data) ? payload.data : [];
    assignCountries();
    setModeBadge(payload);
    updateTimestamp(payload);
    render({ fit: !els.country.value });

    if (payload.stale) {
      showMessage(
        payload.warning || 'Showing the latest cached civilian flight data.',
        'warning'
      );
    }
  } catch (error) {
    showMessage(error.message || 'Live flight data could not be loaded.');
  } finally {
    els.loading.classList.add('hidden');
    els.refresh.disabled = false;
  }
}

function resetWorld() {
  els.country.value = '';
  highlightCountry('');
  map.flyTo({
    center: [35, 28],
    zoom: 2.3,
    pitch: state.map3d ? 42 : 0,
    bearing: state.map3d ? -8 : 0,
    duration: 900
  });
  render();
}

function set3D(enabled) {
  state.map3d = enabled;
  els.toggle3d.classList.toggle('active', enabled);
  els.toggle3d.textContent = enabled ? '3D' : '2D';

  if (map.getLayer('3d-buildings')) {
    map.setLayoutProperty('3d-buildings', 'visibility', enabled ? 'visible' : 'none');
  }

  map.easeTo({
    pitch: enabled ? Math.max(42, map.getPitch()) : 0,
    bearing: enabled ? map.getBearing() : 0,
    duration: 550
  });
}

async function enableOfflineMap() {
  if (!('serviceWorker' in navigator) || !('caches' in window)) {
    toast('Offline map caching is not supported by this browser.');
    return;
  }

  try {
    if (navigator.storage?.persist) {
      await navigator.storage.persist();
    }

    localStorage.setItem('suhail-offline-map', 'enabled');
    els.offline.classList.add('active');
    toast('Offline map cache enabled. Areas you view online will be kept for later offline use.');
  } catch {
    toast('Offline map cache could not be enabled.');
  }
}

async function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;

  try {
    await navigator.serviceWorker.register('service-worker.js', { scope: './' });
    if (localStorage.getItem('suhail-offline-map') === 'enabled') {
      els.offline.classList.add('active');
    }
  } catch {
    // The online experience remains fully usable.
  }
}

els.search.addEventListener('input', () => render());
els.country.addEventListener('change', () => {
  highlightCountry(els.country.value);
  render({ fit: false });
});

document.querySelectorAll('[data-status]').forEach(chip => {
  chip.addEventListener('click', () => {
    state.selectedStatus = chip.dataset.status || '';
    document.querySelectorAll('[data-status]').forEach(x => x.classList.remove('active'));
    chip.classList.add('active');
    render();
  });
});

els.clear.addEventListener('click', () => {
  els.search.value = '';
  els.country.value = '';
  state.selectedStatus = '';
  document.querySelectorAll('[data-status]').forEach(x => {
    x.classList.toggle('active', x.dataset.status === '');
  });
  highlightCountry('');
  render({ fit: true });
});

els.refresh.addEventListener('click', loadFlights);
els.closeDetail.addEventListener('click', () => els.detail.classList.add('hidden'));
els.toggle3d.addEventListener('click', () => set3D(!state.map3d));
els.resetView.addEventListener('click', resetWorld);
els.filterToggle.addEventListener('click', () => els.controlPanel.classList.toggle('mobile-open'));
els.offline.addEventListener('click', enableOfflineMap);

map.on('click', event => {
  const target = event.originalEvent?.target;
  if (target?.closest?.('.plane-marker')) return;
  if (window.innerWidth < 760) {
    els.controlPanel.classList.remove('mobile-open');
  }
});

registerServiceWorker();
loadCountries();
loadFlights();

const refreshMs = Math.max(60, Number(cfg.refreshSeconds || 28800)) * 1000;
setInterval(loadFlights, refreshMs);
