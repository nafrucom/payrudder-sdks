import { describe, expect, it } from "vitest";
import { MemoryStore, PayrudderCache, RedisStore } from "../src/index";
import type { CacheClient, CacheStore, EntitlementStatus, IngestEvent } from "../src/index";

const status = (over: Partial<EntitlementStatus> = {}): EntitlementStatus =>
  ({ customer_external_id: "cus", meter_code: "api", used: "8", limit: "10", unlimited: false, allowed: true, mode: "hard", over_limit: false, suspended: false, ...over }) as EntitlementStatus;

function fakeClient(environment: "test" | "live" = "test") {
  const calls: string[] = [];
  let used = "8";
  const client = {
    environment,
    customers: {
      getEntitlement: (c: string, m: string) => (calls.push(`ent ${c} ${m}`), Promise.resolve(status({ customer_external_id: c, meter_code: m, used }))),
      listEntitlements: (c: string) => (calls.push(`ents ${c}`), Promise.resolve({ items: [status({ customer_external_id: c, used })] })),
    },
    plans: {
      listPlans: () => (calls.push("plans"), Promise.resolve({ plans: [{ id: "p1", code: "pro", name: "Pro", latest_version: 2, create_time: null }, { id: "p2", code: "draft", name: "Draft", latest_version: 0, create_time: null }] })),
      getPlanVersion: (code: string, v: string) => (calls.push(`version ${code} ${v}`), Promise.resolve({ plan_code: code, version: Number(v), prices: [] })),
    },
    creditPacks: { listCreditPacks: (q?: { active?: boolean }) => (calls.push(`packs ${q?.active}`), Promise.resolve({ packs: [] })) },
    campaigns: { listCampaigns: (q?: { status?: string }) => (calls.push(`campaigns ${q?.status}`), Promise.resolve({ campaigns: [] })) },
    events: { ingest: (e: readonly IngestEvent[]) => (calls.push(`ingest ${e.length}`), Promise.resolve([{ status: "accepted" as const }])) },
  } as unknown as CacheClient;
  return { client, calls, setUsed: (u: string) => (used = u) };
}

const event = (quantity: string): IngestEvent => ({ customer_external_id: "cus", meter_code: "api", quantity, event_time: new Date() });

describe("PayrudderCache", () => {
  it("asks the API once per entry, and the catalog fetches only published plans with running campaigns and active packs", async () => {
    const { client, calls } = fakeClient();
    const cache = new PayrudderCache(client);
    expect((await cache.entitlement("cus", "api")).used).toBe("8");
    await cache.entitlement("cus", "api");
    await cache.entitlements("cus");
    await cache.entitlements("cus");
    const cat = await cache.catalog();
    await cache.catalog();
    expect(cat.plans.map((p) => p.version.plan_code)).toEqual(["pro"]);
    expect(calls).toEqual(["ent cus api", "ents cus", "plans", "packs true", "campaigns running", "version pro 2"]);
  });

  it("shares one request between concurrent misses", async () => {
    const { client, calls } = fakeClient();
    const cache = new PayrudderCache(client);
    await Promise.all([cache.entitlement("cus", "api"), cache.entitlement("cus", "api"), cache.entitlement("cus", "api")]);
    expect(calls).toEqual(["ent cus api"]);
  });

  it("webhooks invalidate the customer or the catalog", async () => {
    const { client, calls, setUsed } = fakeClient();
    const cache = new PayrudderCache(client);
    await cache.entitlement("cus", "api");
    await cache.entitlement("other", "api");
    await cache.catalog();
    setUsed("9");
    expect(await cache.handleWebhook({ type: "subscription.changed", data: { subscription: { customer_external_id: "cus" } } })).toBe(true);
    expect((await cache.entitlement("cus", "api")).used).toBe("9");
    expect((await cache.entitlement("other", "api")).used).toBe("8");
    expect(await cache.handleWebhook({ type: "entitlement.updated", data: { customer_external_id: "cus", meter_code: "" } })).toBe(true);
    expect(await cache.handleWebhook({ type: "invoice.paid", data: { customer_external_id: "cus" } })).toBe(false);
    expect(await cache.handleWebhook({ type: "customer.blocked", data: {} })).toBe(false);
    calls.length = 0;
    await cache.catalog();
    expect(calls).toEqual([]);
    expect(await cache.handleWebhook({ type: "campaign.started", data: {} })).toBe(true);
    await cache.catalog();
    expect(calls).toContain("plans");
  });

  it("keeps test and live apart in one store", async () => {
    const store = new MemoryStore();
    const test = fakeClient("test");
    const live = fakeClient("live");
    live.setUsed("1");
    const a = new PayrudderCache(test.client, { store });
    const b = new PayrudderCache(live.client, { store });
    expect((await a.entitlement("cus", "api")).used).toBe("8");
    expect((await b.entitlement("cus", "api")).used).toBe("1");
    await a.invalidateCustomer("cus");
    await b.entitlement("cus", "api");
    expect(live.calls).toEqual(["ent cus api"]);
  });

  it("counts recorded usage into the cache, and crossing a hard limit flips allowed", async () => {
    const { client, calls } = fakeClient();
    const cache = new PayrudderCache(client);
    await cache.entitlement("cus", "api");
    await cache.entitlements("cus");
    await cache.recordUsage(event("1.5"));
    let s = await cache.entitlement("cus", "api");
    expect([s.used, s.allowed, s.over_limit]).toEqual(["9.5", true, false]);
    await cache.recordUsage(event("0.5"));
    s = await cache.entitlement("cus", "api");
    expect([s.used, s.allowed, s.over_limit]).toEqual(["10", false, false]);
    const all = await cache.entitlements("cus");
    expect([all[0]?.used, all[0]?.allowed]).toEqual(["10", false]);
    expect(calls.filter((c) => !c.startsWith("ingest"))).toEqual(["ent cus api", "ents cus"]);
  });

  it("drops a cached entry whose shape it cannot update", async () => {
    const { client, calls } = fakeClient();
    const store = new MemoryStore();
    const cache = new PayrudderCache(client, { store });
    await cache.entitlement("cus", "api");
    const gen = store.get("payrudder:test:gen:c:cus");
    store.set(`payrudder:test:ent:cus:${gen}:api`, JSON.stringify({ used: 3 }), 60_000);
    await cache.recordUsage(event("1"));
    await cache.entitlement("cus", "api");
    expect(calls.filter((c) => c.startsWith("ent "))).toHaveLength(2);
  });

  it("reads through to the API when the store fails, and reports it", async () => {
    const { client, calls } = fakeClient();
    const errors: unknown[] = [];
    const broken: CacheStore = {
      get: () => Promise.reject(new Error("down")),
      set: () => Promise.reject(new Error("down")),
      delete: () => Promise.reject(new Error("down")),
    };
    const cache = new PayrudderCache(client, { store: broken, onError: (e) => errors.push(e) });
    expect((await cache.entitlement("cus", "api")).used).toBe("8");
    await cache.recordUsage(event("1"));
    await cache.invalidateCatalog();
    expect(calls).toContain("ent cus api");
    expect(errors.length).toBeGreaterThanOrEqual(3);
  });
});

describe("RedisStore", () => {
  it("passes the TTL the way ioredis and node-redis v4 take it", async () => {
    const seen: unknown[][] = [];
    const base = { get: () => Promise.resolve(null), del: () => Promise.resolve(1), set: (...a: unknown[]) => (seen.push(a), Promise.resolve("OK")) };
    await new RedisStore({ ...base, pexpire: () => 1 } as never).set("k", "v", 500);
    await new RedisStore({ ...base, pExpire: () => 1, isOpen: true } as never).set("k", "v", 500);
    expect(seen).toEqual([
      ["k", "v", "PX", 500],
      ["k", "v", { PX: 500 }],
    ]);
  });
});
