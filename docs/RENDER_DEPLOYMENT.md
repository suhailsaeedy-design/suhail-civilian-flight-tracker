# Render Deployment

This repository is prepared for an online Render deployment.

## Architecture

The included `render.yaml` creates:

1. `suhail-civilian-flight-tracker` — Docker/PHP Free Web Service
2. `suhail-flight-cache` — Free Render Key Value service used as a shared Redis-compatible cache

The web service uses:
- health check: `/api/health.php`
- automatic deploy: only after repository checks pass
- PHP Redis extension
- local-file fallback if Redis is temporarily unavailable

## Owner-only secret

The live provider key is configured as:

```
AVIATIONSTACK_KEY
```

The Blueprint marks it with `sync: false`, so its value is supplied to Render's environment settings and is not committed to Git.

When this variable exists, the application automatically switches to live mode.

## Free-plan runtime values

```
AVIATIONSTACK_PLAN=free
FLIGHT_TRACKER_REFRESH_SECONDS=28800
FLIGHT_TRACKER_CACHE_SECONDS=25200
FLIGHT_TRACKER_DAILY_LIMIT=3
```

## Cache and quota protection

Render Free Web Services have an ephemeral filesystem and can spin down when idle. The application therefore prefers the linked Render Key Value service for:

- the latest provider response cache
- the per-day provider request counter

If Redis is unavailable, the PHP backend falls back to its local file cache.

Important: Render's Free Key Value plan is in-memory and does not provide durable persistence across a Key Value restart. This setup is appropriate for a hobby/public demonstration deployment, but a higher-traffic production deployment should use durable storage and a provider plan with sufficient request quota.

## Deployment ownership

- Public repository: source code and non-secret deployment configuration
- Render environment: private API key
- `projects_information`: project status, architecture, credential metadata and handoff
- Public visitors: view/use the deployed application
- Owner: controls source changes and deployment configuration
