import type { EntitlementStatus } from "./generated/types";

/** What reads an entitlement: `payrudder.customers` fits. */
export interface EntitlementSource {
  getEntitlement(externalId: string, meter: string): Promise<EntitlementStatus>;
}

export interface EntitlementCacheOptions {
  /** How long an answer is served without asking again, in milliseconds. Default 5000. A hard limit can be overrun for at most this long (plus `staleMs`). */
  ttlMs?: number;
  /** After `ttlMs`, how long the old answer is still served at once while a new one is fetched in the background. Default 30000; 0 always waits for the new one. */
  staleMs?: number;
  /** The most (customer, meter) answers kept; the least recently used one is dropped. Default 10000. */
  maxEntries?: number;
  /** The clock, for tests. */
  now?: () => number;
}

interface Entry {
  status: EntitlementStatus;
  at: number;
}

/**
 * Entitlement checks answered from the memory of your own process, for a middleware that runs on every request: the first check of a (customer, meter)
 * asks the API, the next ones within `ttlMs` cost no request, and an answer older than that is still returned at once while a fresh one is fetched in
 * the background. Concurrent checks of the same pair share one request.
 *
 * The price is staleness: a customer who reaches a hard limit keeps being allowed until the cached answer is refreshed. Call `invalidate` after you
 * record usage that matters, or lower `ttlMs`. The cache lives in one process (one server, one edge isolate): it is not shared between instances.
 *
 * ```ts
 * const limits = new EntitlementCache(payrudder.customers, { ttlMs: 5_000 });
 * if (!(await limits.check("cus_42", "api_calls")).allowed) return new Response("Quota reached", { status: 429 });
 * ```
 */
export class EntitlementCache {
  private readonly entries = new Map<string, Entry>();
  private readonly inflight = new Map<string, Promise<EntitlementStatus>>();
  private readonly ttlMs: number;
  private readonly staleMs: number;
  private readonly maxEntries: number;
  private readonly now: () => number;

  constructor(
    private readonly source: EntitlementSource,
    options: EntitlementCacheOptions = {},
  ) {
    this.ttlMs = options.ttlMs ?? 5_000;
    this.staleMs = options.staleMs ?? 30_000;
    this.maxEntries = options.maxEntries ?? 10_000;
    this.now = options.now ?? Date.now;
  }

  /** The customer's status on the meter: cached when fresh, the old answer plus a background refresh when stale, a request otherwise. */
  async check(externalId: string, meter: string): Promise<EntitlementStatus> {
    const key = keyOf(externalId, meter);
    const hit = this.entries.get(key);
    if (hit) {
      const age = this.now() - hit.at;
      if (age < this.ttlMs) {
        this.touch(key, hit);
        return hit.status;
      }
      if (age < this.ttlMs + this.staleMs) {
        this.touch(key, hit);
        // The old answer stands if the refresh fails; the next check tries again.
        this.fetch(key, externalId, meter).catch(() => undefined);
        return hit.status;
      }
    }
    return this.fetch(key, externalId, meter);
  }

  /** Forgets what is cached for the customer (one meter, or all of them), so the next check asks the API. */
  invalidate(externalId: string, meter?: string): void {
    if (meter !== undefined) {
      this.entries.delete(keyOf(externalId, meter));
      return;
    }
    const prefix = keyOf(externalId, "");
    for (const key of this.entries.keys()) if (key.startsWith(prefix)) this.entries.delete(key);
  }

  clear(): void {
    this.entries.clear();
  }

  private touch(key: string, entry: Entry): void {
    // A Map keeps insertion order: putting the entry back makes it the most recently used.
    this.entries.delete(key);
    this.entries.set(key, entry);
  }

  private fetch(key: string, externalId: string, meter: string): Promise<EntitlementStatus> {
    const running = this.inflight.get(key);
    if (running) return running;
    const p = this.source
      .getEntitlement(externalId, meter)
      .then((status) => {
        this.entries.delete(key);
        this.entries.set(key, { status, at: this.now() });
        if (this.entries.size > this.maxEntries) {
          const oldest = this.entries.keys().next().value;
          if (oldest !== undefined) this.entries.delete(oldest);
        }
        return status;
      })
      .finally(() => this.inflight.delete(key));
    this.inflight.set(key, p);
    return p;
  }
}

// A customer id cannot hold a NUL, so "a" + "b|c" and "a|b" + "c" never collide.
const keyOf = (externalId: string, meter: string): string => `${externalId}\u0000${meter}`;
