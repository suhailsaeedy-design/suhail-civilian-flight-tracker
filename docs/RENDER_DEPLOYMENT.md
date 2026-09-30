# Render Deployment

This repository is ready for deployment as a Render Web Service.

## Recommended service

- Runtime: Docker
- Plan: Free
- Health check: `/api/health.php`
- Public service name: `suhail-civilian-flight-tracker`

## Secret

Set this in the Render service environment:

```
AVIATIONSTACK_KEY=<private key>
```

Do not put the real value in Git.

The application automatically switches to live mode when `AVIATIONSTACK_KEY` is present.

## Free-plan runtime values

The included `render.yaml` sets:

```
AVIATIONSTACK_PLAN=free
FLIGHT_TRACKER_REFRESH_SECONDS=28800
FLIGHT_TRACKER_CACHE_SECONDS=25200
FLIGHT_TRACKER_DAILY_LIMIT=3
```

## Important free-hosting behavior

Render Free Web Services can spin down after inactivity. The application remains deployable and will restart on the next request.

The local filesystem is ephemeral on Free Web Services. Therefore the existing file cache is an optimization, not durable storage. A future production upgrade should move provider-cache and quota counters to a persistent external store before significant public traffic.

## Deployment ownership

The public repository contains only deployable source code and non-secret configuration. Owner-only provider credentials belong in the hosting platform's encrypted environment settings.
