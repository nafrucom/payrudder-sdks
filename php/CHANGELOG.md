# Changelog

All notable changes to the Payrudder PHP SDK (`payrudder/payrudder-php`). The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), the version numbers follow [Semantic Versioning](https://semver.org/).
The four SDKs share one version number and are released together.

## [Unreleased]

## [0.2.0] - 2026-11-23

### Added
- `connect`: creates or updates a customer by your own id (an organisation with its signed-in member) and returns the link to its customer page.
- `access`: subscription, payment and limits in one check, with `allowed`, `reason` and `notices` to show in your app.
- The client's cache option: `access`, `record_usage`/`recordUsage` and `handle_webhook`/`handleWebhook` on the client go through a `PayrudderCache`, which gains `access` and an access TTL (default 5 s).

### Changed
- `PayrudderCache` also invalidates the customer on `invoice.paid`, `invoice.uncollectible` and `invoice.voided`.

## [0.1.0] - 2026-11-20

### Added
- `Payrudder\Cache\PayrudderCache`: entitlement checks and the catalog kept in a store your workers share (`RedisStore` over Predis or phpredis, or `MemoryStore`), invalidated by webhooks through `handleWebhook`, with usage recorded through it counted into the cache. `Client::environment()` says whether the key is a test or a live one.
- A client for every operation of the tenant API (`/v1/*`): retries with backoff, typed errors, generator pagination, a batching `events->ingest`, and webhook verification. Typed readonly models and backed enums; PHP 8.1 or newer; the built-in transport needs only `ext-curl`.
