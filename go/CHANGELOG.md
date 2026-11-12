# Changelog

All notable changes to the Payrudder Go SDK and to the `payrudder` command line (`cmd/payrudder`, in the same module). The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), the version numbers follow [Semantic Versioning](https://semver.org/).
The four SDKs and the command line share one version number and are released together.

Nothing has been published or tagged yet: the version stays `0.0.0` until the first release, so everything is listed under Unreleased.

## [Unreleased]

### Added
- A client for every operation of the tenant API (`/v1/*`): retries with backoff, typed errors, `iter.Seq2` pagination, a batching `Events.Ingest`, and webhook verification (`VerifyWebhook`, `ConstructEvent`). Standard library only; Go 1.23 or newer.
- The `payrudder` command line (`go install github.com/nafrucom/payrudder-sdks/go/cmd/payrudder`): `login`, `events send`, `events tail`, `webhooks listen`, `webhooks trigger ping`, `webhooks deliveries`, `webhooks list`, `customers list`, `invoices list`.
- `payrudder webhooks create`, `meters create`, `subscriptions create` and `plans publish -f version.json` (the file is the body of the publish request; an unknown field is refused before anything is sent). The three calls with no idempotency key and no unique key are not retried by the command.

### Security
- `payrudder login` writes the stored key to a new file with a random name (created exclusively, mode 0600) and renames it over `config.json`; it no longer writes through `config.json.tmp`, so a link planted at that name is not followed.
- The command line refuses to send a live key (`prk_live_`) over plain `http://` to any host but this machine (`localhost`, `127.0.0.1`, `::1`), at login and at every command; use `https://`.
