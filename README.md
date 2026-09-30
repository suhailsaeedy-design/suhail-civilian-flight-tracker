# Suhail Civilian Flight Tracker

A safe civilian/commercial flight tracking web application.

## Safety boundary

This project intentionally displays only identifiable commercial airline flights with:
- a named airline
- airline IATA code
- flight number
- departure airport
- arrival airport
- live latitude/longitude

Unknown, government/military-like, and non-airline targets are excluded by the backend filter.

## Features

- Responsive live map (Leaflet + OpenStreetMap)
- Civilian/commercial flight count
- Airline count
- Live aircraft position
- Origin / destination
- Altitude / speed / direction
- Flight, airline and airport search
- Origin, destination and status filters
- Flight detail panel
- Demo mode with fictionalized positions
- Live mode via Aviationstack
- Server-side API-key protection
- Server-side cache to reduce API usage
- Mobile/desktop layout

## Run with XAMPP

1. Copy the folder into `C:\xampp\htdocs\`.
2. Start Apache in XAMPP.
3. Open:
   `http://localhost/suhail-civilian-flight-tracker/`
4. It works immediately in DEMO mode.

## Enable live civilian/commercial flight data

Copy `config.example.php` to `config.local.php`, then add your private settings there:

```php
return [
    'mode' => 'live',
    'aviationstack_key' => 'YOUR_API_KEY',
];
```

`config.local.php` is ignored by Git. Keep the key server-side and never commit it or place it in JavaScript.

The free Aviationstack plan is intended for personal/non-commercial use and has a limited request quota. Review the provider's current license/pricing before deployment.

## Important

- Demo positions are fictionalized and are not real-time.
- Live provider data can be incomplete or delayed.
- A flight may be omitted if it does not contain enough commercial-airline metadata.
- The military/sensitive-flight exclusion is deliberate.
- The default refresh interval is 5 minutes to conserve API quota.

## Project structure

```
suhail-civilian-flight-tracker/
├─ api/
│  └─ flights.php
├─ assets/
│  ├─ css/style.css
│  └─ js/app.js
├─ storage/cache/
├─ config.php
├─ config.example.php
├─ config.local.php   # private, Git-ignored (create locally)
├─ index.php
└─ README.md
```
