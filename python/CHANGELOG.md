# Changelog

All notable changes to the Payrudder Python SDK (`payrudder`). The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), the version numbers follow [Semantic Versioning](https://semver.org/).
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
- `PayrudderCache`: entitlement checks and the catalog kept in a store your instances share (`RedisStore` over redis-py, or `MemoryStore`), invalidated by webhooks through `handle_webhook`, with usage recorded through it counted into the cache. `client.environment` says whether the key is a test or a live one.
- A sync client (`Payrudder`) and an async client (`AsyncPayrudder`) for every operation of the tenant API (`/v1/*`): retries with backoff, typed errors, pagination iterators, a batching `events.ingest`, and webhook verification. Typed (`py.typed`); Python 3.10 or newer; one dependency, `httpx`.
