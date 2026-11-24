# Changelog

All notable changes to the Payrudder TypeScript / JavaScript SDK (`@payrudder/sdk`). The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), the version numbers follow [Semantic Versioning](https://semver.org/).
The four SDKs share one version number and are released together.

## [Unreleased]

## [0.3.0] - 2026-11-24

### Added
- Credit holds: `reserveCredits` sets credits aside for work that has started, `commitCreditHold` consumes what the work used (one ledger row per line, with its units) and gives the rest back, `releaseCreditHold` gives a hold back whole; `getCreditHold` and `listCreditHolds` read them. A hold that is not settled in time expires.
- `getCreditBalance` answers `reserved` and `available` (balance less what holds set aside); a lot and a ledger entry may have the source `plan`, and a ledger entry names its `hold_id` and `units`.
- A plan version's `included_credits` and `credit_rollover_cap`.

## [0.2.0] - 2026-11-23

### Added
- `connect({ id, name, email, plan, member })`: creates or updates a customer by your own id (an organisation with its signed-in member) and returns the link to its customer page.
- `access(customer, meters, { member })`: subscription, payment and limits in one check, with `allowed`, `reason` and `notices` to show in your app.
- The `cache` client option: `access`, `recordUsage` and `handleWebhook` on the client go through a `PayrudderCache`, which gains `access` and `accessTtlMs`.

### Changed
- `PayrudderCache.handleWebhook` also invalidates the customer on `invoice.paid`, `invoice.uncollectible` and `invoice.voided`.

### Deprecated
- `EntitlementCache`: use `access` with the `cache` option.

## [0.1.0] - 2026-11-20

### Added
- A client for every operation of the tenant API (`/v1/*`): retries with backoff, typed errors, async-iterator pagination, a batching `events.ingest`, and webhook verification (`verifyWebhook`, `constructEvent`). ESM and CommonJS builds with type declarations; Node 20 or newer, browsers and edge runtimes. No runtime dependencies.
- `PayrudderCache`: entitlement checks and the catalog kept in a store your instances share (`RedisStore` over ioredis or node-redis v4, or `MemoryStore`), invalidated by webhooks through `handleWebhook`, with usage recorded through it counted into the cache. `Payrudder.environment` says whether the key is a test or a live one.
- `EntitlementCache`: entitlement checks answered from the process's own memory (fresh for `ttlMs`, then stale-while-revalidate), for a middleware that checks a limit on every request.
