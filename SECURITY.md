# Security Policy

## Supported code

Security fixes are applied to the current `main` branch.

## Secrets

Never commit:

- `config.local.php`
- `.env` files
- Aviationstack API keys
- passwords, tokens, or private credentials

The tracked `config.php` contains public defaults only. Private runtime values belong in `config.local.php`, which is ignored by Git.

## Reporting a security issue

Do not publish credentials or sensitive details in a public GitHub issue. Remove or rotate any exposed credential immediately before continuing development.

## Application boundary

This project is designed for civilian/commercial flight information only. Unknown, government/military-like, and non-airline targets are excluded by backend filtering.
