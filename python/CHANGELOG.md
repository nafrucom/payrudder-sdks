# Changelog

All notable changes to the Payrudder Python SDK (`payrudder`). The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), the version numbers follow [Semantic Versioning](https://semver.org/).
The four SDKs share one version number and are released together.

## [Unreleased]

## [0.3.0] - 2026-11-24

### Added
- Credit holds: `reserve_credits` sets credits aside for work that has started, `commit_credit_hold` consumes what the work used (one ledger row per line, with its units) and gives the rest back, `release_credit_hold` gives a hold back whole; `get_credit_hold` and `list_credit_holds` read them. A hold that is not settled in time expires.
- `get_credit_balance` answers `reserved` and `available` (balance less what holds set aside); a lot and a ledger entry may have the source `plan`, and a ledger entry names its `hold_id` and `units`.
- A plan version's `included_credits` and `credit_rollover_cap`.

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
