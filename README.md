# Payrudder SDKs

Official client libraries for the [Payrudder](https://payrudder.com) API: send usage events, manage customers, subscriptions and invoices, and verify webhook deliveries.

| Language | Directory | Package |
|---|---|---|
| Go | [`go`](go) | `github.com/nafrucom/payrudder-sdks/go` |
| TypeScript / JavaScript | [`typescript`](typescript) | `@payrudder/sdk` |
| Python | [`python`](python) | `payrudder` |
| PHP | [`php`](php) | `payrudder/payrudder-php` |

All four are generated from the same OpenAPI description, share one version number and follow the same design: one method per API operation, typed models, automatic pagination, retries with idempotency keys, and webhook signature verification. Each directory has its own README, changelog and `examples/`.

> **Pre-release.** No version has been published yet; every package is `0.0.0` until the first release.

## Install (after the first release)

```bash
go get github.com/nafrucom/payrudder-sdks/go
npm install @payrudder/sdk
pip install payrudder
composer require payrudder/payrudder-php
```

## Contributing

This repository is a read-only mirror: the SDKs are developed together with the Payrudder API and every change arrives here from there. Please report bugs and requests as [issues](https://github.com/nafrucom/payrudder-sdks/issues); pull requests cannot be merged directly, but a fix described in an issue is welcome.

## Licence

[Apache License 2.0](LICENSE).
