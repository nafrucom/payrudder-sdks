// RedisStore against a real Redis, through ioredis and node-redis. Skipped unless PAYRUDDER_TEST_REDIS_URL is set, e.g.
// docker run -d --rm -p 6390:6379 redis:7-alpine && PAYRUDDER_TEST_REDIS_URL=redis://localhost:6390 npm test
import { Redis as IORedis } from "ioredis";
import { createClient } from "redis";
import { afterAll, describe, expect, it } from "vitest";
import { PayrudderCache, RedisStore } from "../src/index";
import type { CacheClient, EntitlementStatus } from "../src/index";

const url = process.env.PAYRUDDER_TEST_REDIS_URL;
const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

const clients: Record<string, () => Promise<{ store: RedisStore; close: () => Promise<unknown> }>> = {
  ioredis: () => {
    const c = new IORedis(url!);
    return Promise.resolve({ store: new RedisStore(c), close: () => c.quit() });
  },
  "node-redis": async () => {
    const c = createClient({ url: url! });
    await c.connect();
    return { store: new RedisStore(c), close: () => c.quit() };
  },
};

describe.skipIf(!url).each(Object.keys(clients))("RedisStore on a real Redis through %s", (name) => {
  const closers: (() => Promise<unknown>)[] = [];
  const open = async () => {
    const r = await clients[name]!();
    closers.push(r.close);
    return r.store;
  };
  afterAll(async () => {
    for (const c of closers) await c();
  });
  const key = (k: string) => `payrudder-sdk-test:${name}:${Date.now()}:${k}`;

  it("sets with a TTL, gets, deletes, and an entry expires", async () => {
    const store = await open();
    const k = key("a");
    expect(await store.get(k)).toBeNull();
    await store.set(k, "v1", 60_000);
    expect(await store.get(k)).toBe("v1");
    await store.delete(k);
    expect(await store.get(k)).toBeNull();
    await store.set(k, "short", 150);
    expect(await store.get(k)).toBe("short");
    await sleep(400);
    expect(await store.get(k)).toBeNull();
  });

  it("a webhook invalidates what another instance cached", async () => {
    let used = "8";
    let asked = 0;
    const client = {
      environment: "test",
      customers: {
        getEntitlement: (c: string, m: string) => (asked++, Promise.resolve({ customer_external_id: c, meter_code: m, used } as EntitlementStatus)),
      },
    } as unknown as CacheClient;
    const prefix = key("cache") + ":";
    const one = new PayrudderCache(client, { store: await open(), prefix, onError: (e) => { throw e; } });
    const two = new PayrudderCache(client, { store: await open(), prefix, onError: (e) => { throw e; } });
    expect((await one.entitlement("cus", "api")).used).toBe("8");
    expect((await two.entitlement("cus", "api")).used).toBe("8");
    expect(asked).toBe(1); // the second instance read the first one's entry
    used = "9";
    expect(await two.handleWebhook({ type: "entitlement.updated", data: { customer_external_id: "cus" } })).toBe(true);
    expect((await one.entitlement("cus", "api")).used).toBe("9");
    expect(asked).toBe(2);
  });
});
