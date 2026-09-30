# Changelog

## 2026-09-30

### Added
- Initial responsive civilian flight-tracking interface.
- Live Aviationstack integration.
- Commercial/civilian filtering boundary.
- Private local configuration pattern.
- GitHub Actions CI.
- Security policy and deployment guide.
- Free-tier daily provider-request guard.
- Stale-cache fallback for provider outages.
- PHP cURL/HTTP-stream request fallback.
- Smoke checks and secret-file checks.

### Changed
- Default live refresh profile changed from 5 minutes to a free-tier-safe 8 hours.
- Frontend now marks stale provider data as CACHED.
- Added security headers and accessibility improvements.


### Security/config update
- Added `app_config.php` as the central runtime configuration loader.
- Added support for `AVIATIONSTACK_KEY` and related server environment variables.
- Kept all real secret values outside Git repositories.


## 2026-09-30 — V2 mobile / 3D upgrade

### Added
- English-only V2 interface.
- MapLibre GL JS 3D map.
- OpenFreeMap vector-map integration.
- 3D buildings.
- Mobile-first bottom-sheet controls.
- Country / airspace selector.
- Lightweight Natural Earth world-atlas boundaries.
- Point-in-country filtering for current aircraft positions.
- PWA manifest and application icon.
- Service worker for offline app-shell and viewed-map caching.
- 3D / 2D map toggle.
- World reset control.
- Passenger Camera section for official public feeds only.
- Departure/arrival scheduled and estimated times.
- Terminal/gate fields when provider data is available.

### Changed
- Replaced Leaflet raster map with MapLibre/OpenFreeMap.
- Simplified filtering for mobile use.
- Relaxed unnecessary airline-IATA requirement while retaining civilian/commercial identity and safety filters.
- UI now clearly describes results as current free-data coverage instead of claiming every world flight.

### Free-tier limits
- Future Flight / complete future schedule lists are intentionally omitted because they are not part of the current free Aviationstack plan.
- Offline mode caches viewed map areas; live aircraft are online-only.


## 2026-09-30 — Truthful live-data coverage fix
- Production logs confirmed 300 Aviationstack active records, 296 commercial-identifiable records and 0 live coordinate records.
- Added OpenSky Network as a live-position fallback while keeping Aviationstack for commercial metadata.
- Exact callsign/ICAO24 matches are preferred.
- Prefix fallback is restricted to commercial airline ICAO prefixes confirmed by Aviationstack metadata.
- Unknown and military/government-like targets remain excluded.
- UI no longer equates a zero provider result with "no flights in the sky."
- Added explicit LIMITED/coverage messaging and data-source attribution.
- Separated live-position cache timing from Aviationstack metadata cache timing.
