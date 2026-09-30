# Suhail Civilian Flight Tracker

A responsive civilian/commercial flight-tracking web application built with PHP, JavaScript, Leaflet, and OpenStreetMap.

## Project boundary

This application intentionally displays only identifiable commercial airline flights with route metadata and live coordinates. Unknown, government/military-like, and non-airline targets are excluded by backend filtering.

## Features

- Responsive live map
- Civilian/commercial flight count
- Airline count
- Origin and destination
- Altitude, speed, and direction
- Flight / airline / airport search
- Origin, destination, and status filters
- Flight detail panel
- Demo mode with fictionalized positions
- Live mode through Aviationstack
- Server-side API-key protection
- Server-side cache
- Free-tier daily request guard
- Cached-data fallback when the provider is unavailable
- cURL with HTTP-stream fallback
- Mobile and desktop layouts
- Security headers
- GitHub Actions PHP/JavaScript checks

## Requirements

- PHP 8.1+ (8.2 recommended)
- Apache or Nginx
- PHP cURL recommended
- Internet access for live data and online map assets

No database is required.

## Quick start with XAMPP

1. Put the repository in:
   `C:\\xampp\\htdocs\\suhail-civilian-flight-tracker`
2. Start Apache.
3. Open:
   `http://localhost/suhail-civilian-flight-tracker/`

The application works immediately in **DEMO** mode.

## Enable live civilian/commercial data

Copy:

`config.example.php`

to:

`config.local.php`

Then place the private Aviationstack API key in `config.local.php`:

```php
<?php
return [
    'mode' => 'live',
    'provider_plan' => 'free',
    'aviationstack_key' => 'YOUR_PRIVATE_API_KEY',
];
```

`config.local.php` is ignored by Git and must never be committed.

## Free-plan protection

The tracked defaults are intentionally conservative:

- refresh interval: 8 hours
- cache lifetime: 7 hours
- successful provider requests/day: maximum 3

This is designed to keep a continuously running free-plan installation near the provider's 100-request/month allowance.

For a paid API plan, override these values only in `config.local.php`.

## Reliability

If the live provider is temporarily unavailable, the API can serve the most recent cached civilian flight data instead of making the application unusable. The interface marks this state as **CACHED**.

## Security

- No production key is stored in this repository.
- `.env` and `config.local.php` are ignored.
- GitHub Actions checks that tracked `config.php` has no API key.
- The browser interface receives no provider secret.
- See `SECURITY.md` for repository security rules.

## CI

On pushes and pull requests to `main`, GitHub Actions runs:

- PHP syntax checks
- JavaScript syntax check
- application smoke checks
- secret-file safety checks

## Deployment

See:

`docs/DEPLOYMENT.md`

GitHub Pages cannot execute PHP, so the full application needs PHP-capable hosting.

## Project structure

```
suhail-civilian-flight-tracker/
├─ .github/workflows/ci.yml
├─ api/
│  └─ flights.php
├─ assets/
│  ├─ css/style.css
│  └─ js/app.js
├─ docs/
│  └─ DEPLOYMENT.md
├─ storage/cache/
├─ tests/
│  └─ smoke.php
├─ .editorconfig
├─ .gitignore
├─ config.php
├─ config.example.php
├─ config.local.php   # private; create locally
├─ index.php
├─ SECURITY.md
└─ README.md
```

## Data notes

- Demo locations are fictionalized.
- Live data may be delayed or incomplete.
- Flights without enough commercial-airline metadata are omitted.
- Public map tiles should not be treated as an unlimited production tile service.
