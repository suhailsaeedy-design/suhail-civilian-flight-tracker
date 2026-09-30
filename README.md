# Suhail Civilian Flight Tracker

**Live site:** https://suhail-civilian-flight-tracker.onrender.com

A free, mobile-first 3D civilian/commercial flight tracker built by Suhail Labs.

## V2 highlights

- English-only interface
- Mobile-first layout with a bottom control sheet
- 3D vector map powered by MapLibre GL JS + OpenFreeMap
- 3D buildings at close zoom levels
- Live commercial/civilian aircraft markers
- Country / airspace filter using Natural Earth country boundaries
- Flight / airline / airport search
- All / Airborne quick filter
- Route, altitude, speed, heading and aircraft details
- Scheduled / estimated departure and arrival information when supplied by the provider
- Terminal and gate details when supplied by the provider
- Installable PWA
- Offline app shell and automatic caching of map areas/resources already viewed online
- Cached-data fallback
- Mobile and desktop support
- GitHub Actions CI and Docker deployment checks

## Data and safety boundary

This project intentionally displays identifiable civilian/commercial airline flights only.

Backend filtering requires:
- a named airline
- an identifiable flight number
- departure and arrival route information
- live coordinates

Government/military-like operator names are excluded.

## Passenger camera

The UI includes a Passenger Camera section, but it only enables a camera link when an airline provides an official public passenger-facing live feed for that flight.

The application does not access private onboard cameras and does not create fake camera streams.

## Free-data architecture and limitations

The live stack is hybrid:

- Aviationstack Free: commercial airline identity, route and schedule metadata
- ADSB.lol: regional live aircraft positions, queried server-side and filtered to commercial/civilian matches only

Aviationstack's free account currently returns active commercial records for this project but, in production testing, returned no latitude/longitude values in the sampled records. The application therefore does not interpret a zero-position response as "there are no flights."

Current free-data constraints include:
- Aviationstack: 100 requests per month
- ADSB.lol: free public API, best-effort regional coverage and no uptime guarantee
- no paid Future Flight / full flight-schedule feature

Because the project must remain free, it does not claim complete worldwide minute-by-minute coverage of every active aircraft. The interface explicitly labels the results as the current free-data coverage.

The free profile protects providers with:
- UI refresh interval: about 2 minutes
- regional live-position cache: about 90 seconds
- Aviationstack metadata cache: about 7 hours
- maximum Aviationstack successful requests per day: 3

## Country filter

Country filtering uses a lightweight Natural Earth / world-atlas boundary dataset in the browser.

Selecting a country centers a regional live-position query on that country and then filters returned commercial aircraft against the country boundary. Large countries can exceed the free regional query radius, so the interface describes this as current coverage rather than claiming complete national coverage.

## 3D map

Map rendering uses:
- MapLibre GL JS
- OpenFreeMap
- OpenStreetMap-derived vector data

The map can switch between 3D and 2D views.

## Offline mode

The project is an installable PWA.

When Offline Map Cache is enabled:
- the app shell is cached
- map libraries/styles/resources are cached as they are used
- previously viewed map areas can remain usable without a connection
- live aircraft data is not available offline

A full downloadable worldwide 3D map package is intentionally not bundled because it would be extremely large. The free version uses a viewed-area cache instead.

## Live provider secret

The browser never receives the Aviationstack API key directly.

Runtime secrets are loaded server-side through:
- Render environment variables in production
- ignored `config.local.php` for local development

## Deployment

Production:
- Render Free Web Service
- Docker / PHP 8.3 / Apache
- Singapore region
- public URL: https://suhail-civilian-flight-tracker.onrender.com

The repository includes:
- `Dockerfile`
- `render.yaml`
- `api/health.php`
- `docs/RENDER_DEPLOYMENT.md`

## CI

GitHub Actions validates:
- PHP syntax
- JavaScript module syntax
- service-worker syntax
- application smoke checks
- secret-file safety
- Docker image build

## Project structure

```
suhail-civilian-flight-tracker/
├─ .github/workflows/ci.yml
├─ api/
│  ├─ flights.php
│  └─ health.php
├─ assets/
│  ├─ css/style.css
│  ├─ icons/app-icon.svg
│  └─ js/app.js
├─ docs/
│  ├─ DEPLOYMENT.md
│  └─ RENDER_DEPLOYMENT.md
├─ storage/cache/
├─ tests/smoke.php
├─ app_config.php
├─ config.php
├─ config.example.php
├─ Dockerfile
├─ index.php
├─ manifest.webmanifest
├─ render.yaml
├─ service-worker.js
├─ SECURITY.md
└─ README.md
```

## Attribution / open data

- OpenFreeMap / OpenStreetMap data for map rendering
- MapLibre GL JS for WebGL mapping
- Natural Earth / world-atlas for lightweight country boundaries
- ADSB.lol (ODbL) for regional live aircraft positions
- Aviationstack for commercial flight metadata

Live aviation data can be delayed, incomplete or unavailable depending on provider coverage. A zero result is explicitly treated as a coverage result, not proof that no real flights exist.
