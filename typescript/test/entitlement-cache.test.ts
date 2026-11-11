import { describe, expect, it } from "vitest";
import { EntitlementCache } from "../src/index";
import type { EntitlementStatus } from "../src/index";

const status = (used: string, allowed = true): EntitlementStatus => ({ used, allowed }) as EntitlementStatus;

function setup(options: { ttlMs?: number; staleMs?: number; maxEntries?: number } = {}) {
  let clock = 0;
  const calls: string[] = [];
  let next: () => Promise<EntitlementStatus> = () => Promise.resolve(status(String(calls.length)));
  const cache = new EntitlementCache(
    { getEntitlement: (c, m) => (calls.push(`${c}/${m}`), next()) },
    { ttlMs: 1000, staleMs: 5000, ...options, now: () => clock },
  );
  return { cache, calls, tick: (ms: number) => (clock += ms), answer: (f: typeof next) => (next = f) };
}
const settle = () => new Promise((r) => setTimeout(r, 0));

describe("EntitlementCache", () => {
  it("asks once, then answers from memory until the answer is older than ttlMs", async () => {
    const { cache, calls, tick } = setup();
    expect((await cache.check("cus", "api")).used).toBe("1");
    tick(999);
    expect((await cache.check("cus", "api")).used).toBe("1");
    expect(calls).toEqual(["cus/api"]);
    expect((await cache.check("cus", "other")).used).toBe("2");
  });

  it("serves a stale answer at once and refreshes in the background; past staleMs it waits for the new one", async () => {
    const { cache, calls, tick } = setup();
    await cache.check("cus", "api");
    tick(1500);
    expect((await cache.check("cus", "api")).used).toBe("1");
    await settle();
    expect(calls).toHaveLength(2);
    expect((await cache.check("cus", "api")).used).toBe("2");
    tick(6001);
    expect((await cache.check("cus", "api")).used).toBe("3");
  });

  it("shares one request between concurrent checks", async () => {
    const { cache, calls } = setup();
    const all = await Promise.all([cache.check("cus", "api"), cache.check("cus", "api"), cache.check("cus", "api")]);
    expect(calls).toHaveLength(1);
    expect(all.map((s) => s.used)).toEqual(["1", "1", "1"]);
  });

  it("keeps the old answer when a background refresh fails, and throws when there is nothing to fall back on", async () => {
    const { cache, tick, answer } = setup();
    await cache.check("cus", "api");
    answer(() => Promise.reject(new Error("down")));
    tick(1500);
    expect((await cache.check("cus", "api")).used).toBe("1");
    await settle();
    expect((await cache.check("cus", "api")).used).toBe("1");
    await settle();
    await expect(cache.check("new", "api")).rejects.toThrow("down");
  });

  it("invalidate forgets one meter or the whole customer, and never another customer whose id starts the same", async () => {
    const { cache, calls } = setup();
    await cache.check("cus", "a");
    await cache.check("cus", "b");
    await cache.check("cus2", "a");
    cache.invalidate("cus", "a");
    await cache.check("cus", "a");
    expect(calls).toHaveLength(4);
    cache.invalidate("cus");
    await cache.check("cus", "b");
    await cache.check("cus2", "a");
    expect(calls).toHaveLength(5);
  });

  it("drops the least recently used entry beyond maxEntries", async () => {
    const { cache, calls } = setup({ maxEntries: 2 });
    await cache.check("a", "m");
    await cache.check("b", "m");
    await cache.check("a", "m"); // a is now the most recently used
    await cache.check("c", "m"); // b goes
    await cache.check("a", "m");
    expect(calls).toHaveLength(3);
    await cache.check("b", "m");
    expect(calls).toHaveLength(4);
  });
});
