import { afterEach, describe, expect, it } from "vitest";
import { Payrudder } from "../src/index";
import { json, startServer, type FakeServer } from "./helpers";

const access = (over: Record<string, unknown> = {}) => ({
  customer_external_id: "u1",
  allowed: true,
  reason: "ok",
  subscription: { id: "s1", state: "SUBSCRIPTION_STATE_ACTIVE", plan_code: "pro", trial_ends_at: null, cancel_at_period_end: false },
  meters: [{ customer_external_id: "u1", meter_code: "api", used: "9", limit: "10", unlimited: false, allowed: true, mode: "hard", over_limit: false, suspended: false }],
  notices: [{ code: "limit_near", severity: "warning", meter_code: "api", at: null }],
  ...over,
});

let server: FakeServer | undefined;
afterEach(async () => {
  await server?.close();
  server = undefined;
});

async function client(cache?: object) {
  server = await startServer((req, res) => {
    if (req.url.includes("/session")) return json(res, 200, { url: "https://x/c/tok", expires_at: "2026-11-22T00:00:00Z", customer: { external_id: "u1" }, created: true, billing_owner: true });
    if (req.url.startsWith("/v1/events")) return json(res, 200, { results: [{ status: "accepted" }] });
    return json(res, 200, access());
  });
  return new Payrudder({ apiKey: "prk_test_x", baseUrl: server.url, maxRetries: 0, ...(cache ? { cache } : {}) });
}

const accessCalls = () => server!.requests.filter((r) => r.url.includes("/access")).length;

describe("connect and access", () => {
  it("connect sends PUT /v1/customers/u1/session with plan_code and member", async () => {
    const p = await client();
    const out = await p.connect({ id: "u1", name: "Acme", plan: "pro", member: { id: "m1", name: "Olga" } });
    const req = server!.requests[0]!;
    expect(req.method).toBe("PUT");
    expect(req.url).toBe("/v1/customers/u1/session");
    expect(JSON.parse(req.body)).toEqual({ name: "Acme", plan_code: "pro", member: { id: "m1", name: "Olga" } });
    expect(out.url).toBe("https://x/c/tok");
  });

  it("access without cache asks the API every time", async () => {
    const p = await client();
    await p.access("u1", ["a", "b"]);
    await p.access("u1", ["a", "b"]);
    expect(accessCalls()).toBe(2);
    expect(server!.requests[0]!.url).toBe("/v1/customers/u1/access?meters=a%2Cb");
  });

  it("access with cache answers the second call from the store", async () => {
    const p = await client({});
    await p.access("u1", "api");
    expect((await p.access("u1", "api")).allowed).toBe(true);
    expect(accessCalls()).toBe(1);
  });

  it("access cache key does not depend on meter order", async () => {
    const p = await client({});
    await p.access("u1", ["a", "b"]);
    await p.access("u1", ["b", "a"]);
    expect(accessCalls()).toBe(1);
  });

  it("handleWebhook invoice.paid invalidates the customer's access", async () => {
    const p = await client({});
    await p.access("u1", "api");
    expect(await p.handleWebhook({ type: "invoice.paid", data: { customer_external_id: "u1" } })).toBe(true);
    await p.access("u1", "api");
    expect(accessCalls()).toBe(2);
  });

  it("recordUsage counts into a cached access answer and flips it to limit_reached", async () => {
    const p = await client({});
    await p.access("u1", "api");
    await p.recordUsage({ customer_external_id: "u1", meter_code: "api", quantity: "1", event_time: new Date() });
    const a = await p.access("u1", "api");
    expect(a.allowed).toBe(false);
    expect(a.reason).toBe("limit_reached");
    expect(a.meters[0]!.used).toBe("10");
    expect(a.notices).toEqual([{ code: "limit_reached", severity: "critical", meter_code: "api", at: null }]);
    expect(accessCalls()).toBe(1);
  });
});
