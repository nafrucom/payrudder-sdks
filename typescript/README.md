# Payrudder TypeScript / JavaScript SDK

Client for the tenant API of Payrudder (`/v1/*`, authenticated with an API key) and webhook signature verification.


- Node 20+, browsers and edge runtimes (it only needs the global `fetch`, `crypto.subtle`, `AbortController` and `FormData`).
- ESM and CommonJS builds with type declarations. `strict` TypeScript.
- No runtime dependencies.
- Licence: Apache License 2.0, see [`LICENSE`](LICENSE).

## Install

```sh
npm install @payrudder/sdk
```

Runnable programs (send a usage event, create a customer, list invoices, verify a webhook) are in [`examples/`](examples/README.md).

## Quickstart

```ts
import { Payrudder } from "@payrudder/sdk";

const payrudder = new Payrudder({
  apiKey: process.env.PAYRUDDER_API_KEY, // prk_test_... (sandbox) or prk_live_...
  baseUrl: "http://localhost:42800", // default; PAYRUDDER_BASE_URL is read when not given
});

// Your "billing" endpoint: the customer is created or brought up to date by your own user id, then sent to their page.
const { url } = await payrudder.connect({ id: user.id, name: user.name, email: user.email });

// Your middleware: subscription, payment and the limit in one check.
const access = await payrudder.access(user.id, "api_calls");
if (!access.allowed) return new Response(access.reason, { status: 402 });

await payrudder.recordUsage({ customer_external_id: user.id, meter_code: "api_calls", quantity: "1", event_time: new Date() });
```

The customer chooses a plan, pays and cancels on the page `url` opens; your code never manages a subscription. See [Connecting customers and checking access](#connecting-customers-and-checking-access) for organisations, caching and the notices to show in your app.

Every operation of `api/openapi/v1.yaml` under `/v1/*` is one method, named exactly like its `operationId`, on the namespace of the first path segment (`/v1/credit-notes/...` is `payrudder.creditNotes`, `/v1/e-invoices/...` is `payrudder.eInvoices`). Arguments are, in order: the path parameters, the request body, the query object, then call options.

```ts
payrudder.subscriptions.cancelSubscription(id, { credit_unused: true });
payrudder.customers.setCustomerAddress("cus_42", { country: "TR", city: "Istanbul" });
payrudder.invoices.listInvoices({ customer: "cus_42", status: "paid" });
```

Request and response models are exported as types (`Customer`, `Invoice`, `CreateCustomerRequest`, ...). Field names stay snake_case, as on the wire. A response property is required whenever the API always sends it (every field of a protobuf-backed object, zero values included); a timestamp or nested object that is not set is `null` and typed `T | null`. A request object that is also a response has a twin without the server-filled fields: pass an `AddressInput`, a `CouponInput` or a `PriceInput` where you create or replace one, and read an `Address`, a `Coupon` or a `Price` back. `cancelSubscription` always answers `{ subscription, credited_minor }`.

### Client options

| Option | Default | |
|---|---|---|
| `apiKey` | `PAYRUDDER_API_KEY` | required |
| `baseUrl` | `PAYRUDDER_BASE_URL`, else `http://localhost:42800` | |
| `timeout` | `30000` | milliseconds, per attempt |
| `maxRetries` | `2` | automatic retries after the first attempt |
| `fetch` | global `fetch` | inject your own (tests, proxies) |
| `userAgent` | `payrudder-typescript/0.0.0` | browsers ignore it |
| `cache` | none | `PayrudderCache` options; `access`, `recordUsage` and `handleWebhook` then go through it |

Per call, the last argument accepts `{ idempotencyKey, timeout, maxRetries, signal, headers }`.

## Money and decimals

`*_minor` amounts are int64 and travel as JSON **strings**. The SDK never turns them into a `number`:

- responses give you the string (`invoice.total_minor === "1116"`), use `toBigInt()` to compute with it;
- requests accept a string or a `bigint` (`amount_minor: 1000n`).

Decimal quantities and prices are strings too (`quantity: "1.250"`).

## Ingesting usage events

`events.ingest(events)` is the helper for `POST /v1/events`. It

- splits the input into valid batches (at most 500 events and 1 MiB each);
- fills a generated `idempotency_key` for events that have none (live keys require one). The key is chosen before the first attempt, so the automatic retries of a call can never double-count;
- returns one result per event, in input order. A rejected event is a result (`status: "rejected"`, `error_code`), not an exception. A failure of a whole batch (authentication, quota, network after the retries) throws.

```ts
const results = await payrudder.events.ingest(events);
for (const [i, r] of results.entries()) {
  if (r.status === "rejected") console.warn(events[i], r.error_code, r.error_message);
}
```

If you need to re-submit an input after a crash, give your own `idempotency_key`s: a repeat then comes back as `duplicate` and is not counted twice. The raw one-request operation is still available as `events.ingestEvents({ events })`.

## Connecting customers and checking access

`connect` creates the customer by your own id, or updates its name, e-mail and language when they changed, and returns the link to its customer page (valid for a day: ask for one at every visit). With `plan`, the page opens with that plan chosen, for a customer who picked it on your own pricing page.

```ts
// An organisation: your organisation id, and the signed-in person as the member. The first member manages payment.
const { url, billing_owner } = await payrudder.connect({ id: org.id, name: org.name, member: { id: user.id, name: user.name, email: user.email } });
if (!billing_owner) showMessage("Your organisation's billing owner manages payment."); // url is null for the other members
```

`access(customer, meters?, { member? })` answers whether the customer may use the service now: `allowed`, the `reason` that decided (`ok`, `trialing`, `past_due`, or `no_subscription`, `awaiting_payment`, `unpaid`, `limit_reached`, ...), the subscription and the meters asked for. For an organisation, ask with the organisation id: every member gets the same answer.

`notices` is what to show the customer, ready for a banner, most serious first:

```ts
for (const n of access.notices) banner(n.severity, n.code, n.meter_code, n.at); // e.g. limit_near on api_calls, trial_ending at 2026-11-28
```

| `code` | `severity` | |
|---|---|---|
| `suspended` | critical | you suspended the customer's service |
| `payment_required` | critical | the first payment is not made, or an invoice became uncollectible |
| `payment_overdue` | warning | a renewal is being retried |
| `trial_ending` | info | the trial ends within 7 days (`at`) |
| `subscription_ending` | info | cancelled at the end of the paid period (`at` when known) |
| `limit_reached` | critical | a hard limit of `meter_code` is reached |
| `limit_exceeded` | warning | a soft or notify limit of `meter_code` is passed |
| `limit_near` | warning | 80 % of the limit of `meter_code` is used |

Without a cache every `access` asks the API. To answer most checks from a store, give the client a cache: the options are those of `PayrudderCache` below, plus `accessTtlMs` (default 5 s).

```ts
const payrudder = new Payrudder({ apiKey, cache: { store: new RedisStore(redis) } });
await payrudder.access(user.id, ["api_calls", "seats"]);   // the meters' order does not matter
await payrudder.recordUsage(event);                         // counted into the cached answers: a hard limit holds without a refetch
await payrudder.handleWebhook(await constructEvent(rawBody, signature, secret)); // a paid invoice or a plan change shows at once
```

## Checking a limit on every request (deprecated)

`EntitlementCache` is deprecated: use `access` with the client's `cache` option above. `customers.getEntitlement(customer, meter)` is one request to the API. In a middleware that runs on every request of your app, `EntitlementCache` keeps the answers in your own process, so a check costs a request only when nothing fresh is cached.

```ts
import { EntitlementCache } from "@payrudder/sdk";

const limits = new EntitlementCache(payrudder.customers, { ttlMs: 5_000 });

export async function middleware(req: Request) {
  const status = await limits.check(customerOf(req), "api_calls");
  if (!status.allowed) return new Response("Quota reached", { status: 429 });
}
```

- Within `ttlMs` (default 5 s) the cached answer is returned without a request.
- For `staleMs` after that (default 30 s) the old answer is returned at once and a new one is fetched in the background.
- Concurrent checks of the same customer and meter share one request; at most `maxEntries` answers are kept (default 10,000, least recently used dropped).
- The trade-off is staleness: a customer who reaches a hard limit stays allowed until the answer is refreshed. Call `limits.invalidate(customer, meter)` after recording usage that matters, or lower `ttlMs`.
- The cache lives in one process (one server, one edge isolate) and is not shared between instances.

## Caching entitlements and the catalog in Redis

`PayrudderCache` keeps entitlement checks and the catalog (plans with their current prices, active credit packs, running campaigns) in a store every instance of your app shares. Pass the Redis client you already have (ioredis or node-redis v4); without a store it uses a `MemoryStore` in the process. Forward verified webhooks to it, so a change in Payrudder invalidates the cached answers at once.

```ts
import { PayrudderCache, RedisStore, constructEvent } from "@payrudder/sdk";

const cache = new PayrudderCache(payrudder, { store: new RedisStore(redis), onError: console.warn });

const status = await cache.entitlement(customer, "api_calls"); // also cache.entitlements(customer), cache.catalog()
await cache.recordUsage({ customer_external_id: customer, meter_code: "api_calls", quantity: "1", event_time: new Date() });

// in your webhook route, after verifying the delivery
await cache.handleWebhook(await constructEvent(rawBody, signature, secret));
```

- Entitlements are served for `entitlementTtlMs` (default 60 s), the catalog for `catalogTtlMs` (default 10 min); with webhooks wired a change shows at once, without them it can be that stale.
- `recordUsage` counts the event into the cached entitlement, so a hard limit is enforced without a refetch after every event.
- Keys start with `prefix` (default `payrudder:`) and the key's environment, so test and live never share entries. A store error never fails a call: it reads through to the API and goes to `onError`.

## Errors

Every failed call throws a `PayrudderError` built from the API's `Error` envelope:

```ts
import { PayrudderError, NotFoundError, RateLimitError } from "@payrudder/sdk";

try {
  await payrudder.invoices.getInvoice(id);
} catch (err) {
  if (err instanceof NotFoundError) {
    /* 404 */
  } else if (err instanceof PayrudderError) {
    console.error(err.status, err.code, err.key, err.message, err.requestId, err.details);
  } else throw err;
}
```

| Class | When |
|---|---|
| `AuthenticationError` | 401 |
| `PermissionError` | 403 (missing permission, inactive tenant, sandbox-only route with a live key) |
| `NotFoundError` | 404 |
| `ConflictError` | 409 |
| `ValidationError` | 400, 422 |
| `RateLimitError` | 429 (`retryAfter` in seconds) |
| `ServerError` | 5xx |
| `ConnectionError` / `TimeoutError` | no usable response (`status` 0) |

`key` is the stable, translatable error key (`billing.credit_note.nothing_to_refund`): translate the key, not the message.

## Retries and idempotency

Retried automatically, with exponential backoff and jitter (capped): network errors and timeouts, `429` (the `Retry-After` header is honoured), `502`/`503`/`504`, and `500` only for idempotent operations: `GET`/`PUT`/`DELETE`, and `POST`s that carry an idempotency key. Other 4xx are never retried.

For operations that take an `Idempotency-Key` header (payment links, upload sessions) the SDK sends a generated key, the same one on every retry. For any other `POST`, pass `{ idempotencyKey }` in the call options to make it safe to retry after a `500` and safe for you to repeat after a crash:

```ts
await payrudder.customers.createCustomer(body, { idempotencyKey: "signup-8841" });
```

## Pagination

List operations that return a `next_page_token` have an async iterator under `payrudder.paginate` with the same arguments, yielding **items** across all pages:

```ts
for await (const customer of payrudder.paginate.listCustomers({ page_size: 200 })) {
  console.log(customer.external_id);
}
```

The plain `payrudder.customers.listCustomers({ page_token })` returns a single page.

## Webhooks

Verification uses WebCrypto (`crypto.subtle`), so it is **async** and runs unchanged in Node, browsers and edge runtimes. Pass the **raw** request body: a re-serialised JSON body will not verify.

```ts
import { constructEvent, WebhookEventTypes, WebhookVerificationError } from "@payrudder/sdk";

// Express: app.post("/webhooks/payrudder", express.raw({ type: "application/json" }), handler)
async function handler(req, res) {
  try {
    const event = await constructEvent(req.body, req.header("Payrudder-Signature") ?? "", process.env.PAYRUDDER_WEBHOOK_SECRET!);
    if (event.type === WebhookEventTypes.InvoicePaid) {
      console.log(event.data.invoice_id, event.data.net_minor);
    }
    res.sendStatus(200);
  } catch (err) {
    if (err instanceof WebhookVerificationError) return res.sendStatus(400); // err.reason says why
    throw err;
  }
}
```

```ts
// Cloudflare Workers / any fetch-style runtime
export default {
  async fetch(request: Request, env: { WEBHOOK_SECRET: string }) {
    const event = await constructEvent(await request.arrayBuffer(), request.headers.get("Payrudder-Signature") ?? "", env.WEBHOOK_SECRET);
    return new Response(event.id);
  },
};
```

- `verifyWebhook(payload, header, secret, tolerance = 300)` resolves or throws `WebhookVerificationError` (`reason`: `invalid_header`, `timestamp_outside_tolerance`, `no_matching_signature`). It accepts a string, `Uint8Array` or `ArrayBuffer`.
- `constructEvent(...)` verifies, then parses into a typed `WebhookEvent` you can narrow on `type`.
- During a secret rotation the header carries two `v1` signatures; either one matching is enough.
- `WebhookEventTypes` lists the event type names (`invoice.paid`, `subscription.cancelled`, `credits.low`, ... and `ping`).
- Events are delivered at least once and not in order: dedupe on `event.id`.

## Development

```sh
npm ci
npm run generate        # regenerate src/generated/* from ../../api/openapi/v1.yaml
npm run check           # generate:check, typecheck, lint, tests, build, ESM+CJS smoke
```

- `src/generated/` is produced by `scripts/generate.mjs` from `api/openapi/v1.yaml` and is checked in. A test fails when the checked-in code is stale, when an `operationId` under `/v1/*` has no client method, or when a client method is not an `operationId` (the only hand-written helper is `events.ingest`).
- Tests run against a local fake HTTP server; no network and no Payrudder stack are needed. An optional integration smoke test runs only when `PAYRUDDER_API_KEY` is set (and `PAYRUDDER_BASE_URL` if the gateway is not on `localhost:42800`).
