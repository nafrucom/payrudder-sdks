# Changelog

All notable changes to the Payrudder Python SDK (`payrudder`). The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), the version numbers follow [Semantic Versioning](https://semver.org/).
The four SDKs share one version number and are released together.

Nothing has been published or tagged yet: the version stays `0.0.0` until the first release, so everything is listed under Unreleased.

## [Unreleased]

### Added
- A sync client (`Payrudder`) and an async client (`AsyncPayrudder`) for every operation of the tenant API (`/v1/*`): retries with backoff, typed errors, pagination iterators, a batching `events.ingest`, and webhook verification. Typed (`py.typed`); Python 3.10 or newer; one dependency, `httpx`.
