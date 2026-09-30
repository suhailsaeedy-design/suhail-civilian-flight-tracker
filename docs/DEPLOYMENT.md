# Deployment Guide

## Requirements

- PHP 8.1+ (PHP 8.2 recommended)
- Apache or Nginx
- PHP cURL extension recommended
- Internet access for the flight-data API, Leaflet CDN, and OpenStreetMap tiles

No database is required.

## XAMPP on Windows

1. Place the repository folder in:
   `C:\\xampp\\htdocs\\suhail-civilian-flight-tracker`
2. Start Apache.
3. Open:
   `http://localhost/suhail-civilian-flight-tracker/`

The application starts in demo mode.

## Enable live civilian/commercial data

Copy:

`config.example.php`

to:

`config.local.php`

Then set your private API key in `config.local.php`.

Do not commit `config.local.php`.

## Free-plan profile

The public defaults are intentionally conservative:

- refresh: 8 hours
- cache: 7 hours
- maximum successful provider requests/day: 3

This keeps a continuously running installation close to the 100-request/month free allowance.

For a paid API plan, override the values in `config.local.php`.

## Production notes

- Use HTTPS.
- Keep `config.local.php` outside public version control.
- Ensure `storage/cache` is writable by PHP.
- Keep PHP display_errors disabled in production.
- Use a production-appropriate tile provider if traffic becomes significant; do not overload public map tile services.
- Back up configuration separately from the public repository.

## GitHub Pages

GitHub Pages cannot execute PHP. Use Apache/Nginx hosting, XAMPP for local use, or another PHP-capable host.
