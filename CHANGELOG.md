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
