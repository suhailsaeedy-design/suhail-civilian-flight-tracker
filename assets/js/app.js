(() => {
  'use strict';

  const cfg = window.APP_CONFIG || {};
  const els = {
    total: document.getElementById('totalFlights'),
    airborne: document.getElementById('airborneFlights'),
    airlines: document.getElementById('airlineCount'),
    list: document.getElementById('flightList'),
    search: document.getElementById('searchInput'),
    origin: document.getElementById('originInput'),
    destination: document.getElementById('destinationInput'),
    status: document.getElementById('statusSelect'),
    refresh: document.getElementById('refreshBtn'),
    clear: document.getElementById('clearFiltersBtn'),
    lastUpdated: document.getElementById('lastUpdated'),
    loading: document.getElementById('loading'),
    error: document.getElementById('errorBox'),
    detail: document.getElementById('detailCard'),
    detailContent: document.getElementById('detailContent'),
    closeDetail: document.getElementById('closeDetailBtn')
  };

  const map = L.map('map', {
    worldCopyJump: true,
    zoomControl: true,
    minZoom: 2
  }).setView([29, 45], 3);

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  let allFlights = [];
  let markers = new Map();

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
      '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
    }[ch]));
  }

  function num(value, digits = 0) {
    return Number.isFinite(Number(value)) ? Number(value).toFixed(digits) : '—';
  }

  function statusLabel(status) {
    const map = {
      active: 'په هوا کې',
      scheduled: 'Scheduled',
      landed: 'Landed',
      cancelled: 'Cancelled',
      incident: 'Incident',
      diverted: 'Diverted'
    };
    return map[String(status || '').toLowerCase()] || (status || 'Unknown');
  }

  function showError(message) {
    els.error.textContent = message;
    els.error.classList.remove('hidden');
  }

  function clearError() {
    els.error.classList.add('hidden');
  }

  function filters() {
    return {
      q: els.search.value.trim().toLowerCase(),
      origin: els.origin.value.trim().toLowerCase(),
      destination: els.destination.value.trim().toLowerCase(),
      status: els.status.value.trim().toLowerCase()
    };
  }

  function filteredFlights() {
    const f = filters();
    return allFlights.filter(x => {
      const haystack = [
        x.flight_number, x.airline, x.airline_iata,
        x.departure?.iata, x.departure?.airport,
        x.arrival?.iata, x.arrival?.airport,
        x.aircraft?.registration, x.aircraft?.type
      ].join(' ').toLowerCase();

      const originHaystack = [x.departure?.iata, x.departure?.airport].join(' ').toLowerCase();
      const destinationHaystack = [x.arrival?.iata, x.arrival?.airport].join(' ').toLowerCase();

      return (!f.q || haystack.includes(f.q))
        && (!f.origin || originHaystack.includes(f.origin))
        && (!f.destination || destinationHaystack.includes(f.destination))
        && (!f.status || String(x.status || '').toLowerCase() === f.status);
    });
  }

  function renderStats(flights) {
    els.total.textContent = flights.length;
    els.airborne.textContent = flights.filter(f => String(f.status).toLowerCase() === 'active').length;
    els.airlines.textContent = new Set(flights.map(f => f.airline_iata || f.airline).filter(Boolean)).size;
  }

  function makePlaneIcon(direction) {
    const dir = Number.isFinite(Number(direction)) ? Number(direction) : 0;
    return L.divIcon({
      className: 'plane-icon',
      html: `<div class="plane-marker" style="transform:rotate(${dir}deg)">✈</div>`,
      iconSize: [34, 34],
      iconAnchor: [17, 17]
    });
  }

  function showDetail(f) {
    const dep = f.departure || {};
    const arr = f.arrival || {};
    const live = f.live || {};
    const aircraft = f.aircraft || {};
    els.detailContent.innerHTML = `
      <div class="detail-title">
        <div>
          <strong>${esc(f.flight_number || '—')}</strong>
          <span>${esc(f.airline || '—')}</span>
        </div>
      </div>
      <div class="detail-route">
        <b>${esc(dep.iata || '—')}</b> — ${esc(dep.airport || 'Unknown')}<br>
        <span>→</span><br>
        <b>${esc(arr.iata || '—')}</b> — ${esc(arr.airport || 'Unknown')}
      </div>
      <div class="detail-grid">
        <div class="detail-field"><small>حالت</small><b>${esc(statusLabel(f.status))}</b></div>
        <div class="detail-field"><small>Aircraft</small><b>${esc(aircraft.type || '—')}</b></div>
        <div class="detail-field"><small>ارتفاع</small><b>${num(live.altitude_m)} m</b></div>
        <div class="detail-field"><small>سرعت</small><b>${num(live.speed_kmh)} km/h</b></div>
        <div class="detail-field"><small>Direction</small><b>${num(live.direction)}°</b></div>
        <div class="detail-field"><small>Registration</small><b>${esc(aircraft.registration || '—')}</b></div>
      </div>`;
    els.detail.classList.remove('hidden');
  }

  function renderMap(flights) {
    markers.forEach(marker => map.removeLayer(marker));
    markers.clear();

    const bounds = [];

    flights.forEach(f => {
      const lat = Number(f.live?.lat);
      const lon = Number(f.live?.lon);
      if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

      const marker = L.marker([lat, lon], { icon: makePlaneIcon(f.live?.direction) }).addTo(map);
      marker.bindTooltip(`${esc(f.flight_number)} · ${esc(f.airline)}`, { direction: 'top' });
      marker.on('click', () => showDetail(f));
      markers.set(f.id, marker);
      bounds.push([lat, lon]);
    });

    if (bounds.length > 1) map.fitBounds(bounds, { padding: [45, 45], maxZoom: 5 });
    else if (bounds.length === 1) map.setView(bounds[0], 6);
  }

  function renderList(flights) {
    if (!flights.length) {
      els.list.innerHTML = '<div class="empty">د دې فلټر لپاره الوتنه ونه موندل شوه.</div>';
      return;
    }

    els.list.innerHTML = flights.map(f => `
      <div class="flight-item" data-flight-id="${esc(f.id)}">
        <div class="flight-head">
          <div class="flight-no"><span class="status-dot"></span>${esc(f.flight_number || '—')}</div>
          <small>${esc(statusLabel(f.status))}</small>
        </div>
        <div class="airline">${esc(f.airline || '—')}</div>
        <div class="route">
          <b>${esc(f.departure?.iata || '—')}</b>
          <span class="route-line"></span>
          <b>${esc(f.arrival?.iata || '—')}</b>
        </div>
      </div>
    `).join('');

    els.list.querySelectorAll('.flight-item').forEach(item => {
      item.addEventListener('click', () => {
        const f = flights.find(x => x.id === item.dataset.flightId);
        if (!f) return;
        showDetail(f);
        const marker = markers.get(f.id);
        if (marker) {
          map.flyTo(marker.getLatLng(), Math.max(map.getZoom(), 5), { duration: 0.8 });
          marker.openTooltip();
        }
      });
    });
  }

  function render() {
    const flights = filteredFlights();
    renderStats(flights);
    renderList(flights);
    renderMap(flights);
  }

  async function loadFlights() {
    clearError();
    els.loading.classList.remove('hidden');
    els.refresh.disabled = true;

    try {
      const response = await fetch(cfg.endpoint, { cache: 'no-store' });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) {
        throw new Error(payload?.error || `Request failed (${response.status})`);
      }
      allFlights = Array.isArray(payload.data) ? payload.data : [];
      els.lastUpdated.textContent = new Date().toLocaleString();
      render();
    } catch (error) {
      showError(error.message || 'د معلوماتو په اخیستلو کې ستونزه راغله.');
    } finally {
      els.loading.classList.add('hidden');
      els.refresh.disabled = false;
    }
  }

  [els.search, els.origin, els.destination].forEach(el => el.addEventListener('input', render));
  els.status.addEventListener('change', render);

  els.clear.addEventListener('click', () => {
    els.search.value = '';
    els.origin.value = '';
    els.destination.value = '';
    els.status.value = '';
    render();
  });

  els.refresh.addEventListener('click', loadFlights);
  els.closeDetail.addEventListener('click', () => els.detail.classList.add('hidden'));

  loadFlights();

  const refreshMs = Math.max(60, Number(cfg.refreshSeconds || 300)) * 1000;
  setInterval(loadFlights, refreshMs);
})();
