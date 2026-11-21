import type { EventsResource, IngestEvent } from "./events";
import type { CampaignsResource, CreditPacksResource, CustomersResource, PlansResource } from "./generated/resources";
import type { Campaign, CreditPack, CustomerAccess, EntitlementStatus, EventResult, Plan, PlanVersion } from "./generated/types";

/**
 * Where `PayrudderCache` keeps its entries: three methods, sync or async. `MemoryStore` (the default) and `RedisStore` ship with the SDK; anything
 * else (Memcached, a KV namespace) fits by implementing these.
 */
export interface CacheStore {
  get(key: string): Promise<string | null> | string | null;
  set(key: string, value: string, ttlMs: number): Promise<void> | void;
  delete(key: string): Promise<void> | void;
}

/** A least-recently-used store in the memory of this process. Not shared between instances; use `RedisStore` for that. */
export class MemoryStore implements CacheStore {
  private readonly entries = new Map<string, { value: string; until: number }>();

  constructor(
    private readonly maxEntries = 10_000,
    private readonly now: () => number = Date.now,
  ) {}

  get(key: string): string | null {
    const hit = this.entries.get(key);
    if (!hit) return null;
    this.entries.delete(key);
    if (hit.until <= this.now()) return null;
    this.entries.set(key, hit); // a Map keeps insertion order: re-inserting makes it the most recently used
    return hit.value;
  }

  set(key: string, value: string, ttlMs: number): void {
    this.entries.delete(key);
    this.entries.set(key, { value, until: this.now() + ttlMs });
    if (this.entries.size > this.maxEntries) {
      const oldest = this.entries.keys().next().value;
      if (oldest !== undefined) this.entries.delete(oldest);
    }
  }

  delete(key: string): void {
    this.entries.delete(key);
  }
}

/** The part of an ioredis or node-redis v4 client `RedisStore` uses. */
export interface RedisLike {
  get(key: string): Promise<string | null>;
  set(key: string, value: string, ...args: never[]): Promise<unknown>;
  del(key: string): Promise<unknown>;
}

/**
 * Wraps the Redis client you already have (ioredis or node-redis v4); the SDK gains no dependency. node-redis is told apart by its camelCase
 * `pExpire` (or `isOpen`): it takes `set(k, v, { PX })`, ioredis takes `set(k, v, "PX", ms)`.
 */
export class RedisStore implements CacheStore {
  private readonly nodeRedis: boolean;

  constructor(private readonly client: RedisLike) {
    const c = client as unknown as Record<string, unknown>;
    this.nodeRedis = typeof c.pExpire === "function" || "isOpen" in c;
  }

  get(key: string): Promise<string | null> {
    return this.client.get(key);
  }

  async set(key: string, value: string, ttlMs: number): Promise<void> {
    const set = this.client.set.bind(this.client) as (...a: unknown[]) => Promise<unknown>;
    await (this.nodeRedis ? set(key, value, { PX: ttlMs }) : set(key, value, "PX", ttlMs));
  }

  async delete(key: string): Promise<void> {
    await this.client.del(key);
  }
}

/** The parts of the client `PayrudderCache` uses; a `Payrudder` fits. */
export interface CacheClient {
  readonly environment: "test" | "live";
  customers: Pick<CustomersResource, "getEntitlement" | "listEntitlements" | "getCustomerAccess">;
  plans: Pick<PlansResource, "listPlans" | "getPlanVersion">;
  creditPacks: Pick<CreditPacksResource, "listCreditPacks">;
  campaigns: Pick<CampaignsResource, "listCampaigns">;
  events: Pick<EventsResource, "ingest">;
}

export interface PayrudderCacheOptions {
  /** Default: a `MemoryStore`. */
  store?: CacheStore;
  /** Default `"payrudder:"`. The API key's environment follows it, so test and live never share entries. */
  prefix?: string;
  /** How long an entitlement answer is served without asking again. Default 60000. */
  entitlementTtlMs?: number;
  /** How long an access answer is served without asking again. Default 5000: short, so a cached answer stays close to live. */
  accessTtlMs?: number;
  /** How long the catalog is served without asking again. Default 600000. */
  catalogTtlMs?: number;
  /** Called with every store error; the call itself reads through to the API instead of failing. */
  onError?: (err: unknown) => void;
}

/** A plan with its current (latest published) version and prices. */
export interface CatalogPlan {
  plan: Plan;
  version: PlanVersion;
}

export interface Catalog {
  plans: CatalogPlan[];
  /** Active credit packs. */
  packs: CreditPack[];
  /** Running campaigns. */
  campaigns: Campaign[];
}

/** A delivered webhook event, already verified (`constructEvent`). Only `type` and `data` are read. */
export interface CacheWebhookEvent {
  type: string;
  data?: unknown;
}

const CUSTOMER_EVENTS = new Set(["entitlement.updated", "customer.blocked", "customer.unblocked", "credits.purchased", "invoice.paid", "invoice.uncollectible", "invoice.voided"]);
const CATALOG_EVENTS = new Set(["plan.created", "plan.version_published", "campaign.started", "campaign.ended"]);

/**
 * Entitlement checks and the catalog answered from a store you provide (Redis, or memory), shared by every instance of your app. Payrudder stays the
 * source of truth: wire `handleWebhook` and a change invalidates the cached answers at once; without it they are refreshed after the TTL.
 *
 * Invalidation is by generation: each customer and the catalog have a generation key, entries are stored under the generation they were read at, and
 * invalidating writes a new random generation, so the store needs only `get`, `set` with a TTL and `delete`.
 *
 * ```ts
 * const cache = new PayrudderCache(payrudder, { store: new RedisStore(redis) });
 * if (!(await cache.entitlement("cus_42", "api_calls")).allowed) return new Response("Quota reached", { status: 429 });
 * ```
 */
export class PayrudderCache {
  private readonly store: CacheStore;
  private readonly base: string;
  private readonly entTtl: number;
  private readonly catTtl: number;
  private readonly accTtl: number;
  private readonly genTtl: number;
  private readonly onError: (err: unknown) => void;
  private readonly inflight = new Map<string, Promise<unknown>>();

  constructor(
    private readonly client: CacheClient,
    options: PayrudderCacheOptions = {},
  ) {
    this.store = options.store ?? new MemoryStore();
    this.base = (options.prefix ?? "payrudder:") + client.environment + ":";
    this.entTtl = options.entitlementTtlMs ?? 60_000;
    this.catTtl = options.catalogTtlMs ?? 600_000;
    this.accTtl = options.accessTtlMs ?? 5_000;
    this.genTtl = 2 * Math.max(this.entTtl, this.catTtl, this.accTtl);
    this.onError = options.onError ?? (() => undefined);
  }

  /** The customer's status on one meter. */
  entitlement(customer: string, meter: string): Promise<EntitlementStatus> {
    return this.cached(this.customerGen(customer), (g) => `ent:${customer}:${g}:${meter}`, this.entTtl, () =>
      this.client.customers.getEntitlement(customer, meter),
    );
  }

  /** The customer's status on every meter they have an entitlement on. */
  entitlements(customer: string): Promise<EntitlementStatus[]> {
    return this.cached(this.customerGen(customer), (g) => `ents:${customer}:${g}`, this.entTtl, async () =>
      (await this.client.customers.listEntitlements(customer)).items,
    );
  }

  /** Whether the customer may use the service now: subscription, payment and the meters asked for. The meters' order does not matter. */
  async access(customer: string, meters: readonly string[] = [], member?: string): Promise<CustomerAccess> {
    const sorted = [...new Set(meters)].sort();
    const keyOf = (g: string) => `acc:${customer}:${g}:${sorted.join(",")}:${member ?? ""}`;
    const gen = await this.customerGen(customer);
    const answer = await this.cached(Promise.resolve(gen), keyOf, this.accTtl, () =>
      this.client.customers.getCustomerAccess(customer, { meters: sorted.length ? sorted.join(",") : undefined, member }),
    );
    if (gen) await this.remember(customer, gen, keyOf(gen));
    return answer;
  }

  /** Plans with their current prices, active credit packs and running campaigns. */
  catalog(): Promise<Catalog> {
    return this.cached(this.catalogGen(), (g) => `catalog:${g}`, this.catTtl, async () => {
      const [{ plans }, { packs }, { campaigns }] = await Promise.all([
        this.client.plans.listPlans(),
        this.client.creditPacks.listCreditPacks({ active: true }),
        this.client.campaigns.listCampaigns({ status: "running" }),
      ]);
      const published = plans.filter((p) => p.latest_version > 0);
      const versions = await Promise.all(published.map((p) => this.client.plans.getPlanVersion(p.code, String(p.latest_version))));
      return { plans: published.map((plan, i) => ({ plan, version: versions[i]! })), packs, campaigns };
    });
  }

  /**
   * Records one usage event through the API, then counts it into the cached entitlement of that customer and meter (`used += quantity`, and
   * `allowed` recomputed for a hard limit), so the next check needs no request.
   */
  async recordUsage(event: IngestEvent): Promise<EventResult> {
    const [result] = await this.client.events.ingest([event]);
    if (result?.status !== "accepted") return result!;
    const quantity = typeof event.quantity === "bigint" ? event.quantity.toString() : event.quantity;
    const customer = event.customer_external_id;
    const gen = await this.read(this.customerGenKey(customer));
    if (!gen) return result; // no generation: nothing was cached under it
    const one = this.base + `ent:${customer}:${gen}:${event.meter_code}`;
    const raw = await this.read(one);
    if (raw) {
      const next = countUsage(parse(raw) as Status | undefined, quantity);
      await (next ? this.write(one, JSON.stringify(next), this.entTtl) : this.remove(one));
    }
    const all = this.base + `ents:${customer}:${gen}`;
    const rawAll = await this.read(all);
    if (rawAll) {
      const items = parse(rawAll) as Status[] | undefined;
      const i = Array.isArray(items) ? items.findIndex((s) => s?.meter_code === event.meter_code) : -1;
      const next = i >= 0 ? countUsage(items?.[i], quantity) : undefined;
      if (items && i >= 0 && next) {
        items[i] = next;
        await this.write(all, JSON.stringify(items), this.entTtl);
      } else if (i >= 0 || !Array.isArray(items)) {
        await this.remove(all);
      }
    }
    await this.countIntoAccess(customer, gen, event.meter_code, quantity);
    return result;
  }

  // The access answers cached under a generation are listed under one key, so recordUsage can find the ones that hold the meter.
  private async remember(customer: string, gen: string, key: string): Promise<void> {
    const index = this.base + `acc-keys:${customer}:${gen}`;
    const raw = await this.read(index);
    const keys = (raw && (parse(raw) as string[] | undefined)) || [];
    if (!keys.includes(key)) await this.write(index, JSON.stringify([...keys, key].slice(-50)), this.accTtl);
  }

  private async countIntoAccess(customer: string, gen: string, meter: string, quantity: string): Promise<void> {
    const raw = await this.read(this.base + `acc-keys:${customer}:${gen}`);
    const keys = (raw && (parse(raw) as string[] | undefined)) || [];
    for (const k of keys) {
      const key = this.base + k;
      const hit = await this.read(key);
      const a = hit ? (parse(hit) as CustomerAccess | undefined) : undefined;
      if (!a || !Array.isArray(a.meters)) continue;
      const i = a.meters.findIndex((m) => m?.meter_code === meter);
      if (i < 0) continue;
      const next = countUsage(a.meters[i], quantity);
      if (!next) {
        await this.remove(key);
        continue;
      }
      a.meters[i] = next;
      if (!next.allowed && next.mode === "hard" && !next.suspended) {
        if (a.allowed) {
          a.allowed = false;
          a.reason = "limit_reached";
        }
        // The notice the API would now give for this meter, in place of a limit_near one.
        const others = (a.notices ?? []).filter((n) => n.meter_code !== meter);
        a.notices = [...others, { code: "limit_reached", severity: "critical", meter_code: meter, at: null }];
      }
      await this.write(key, JSON.stringify(a), this.accTtl);
    }
  }

  /** Invalidates by the event's type; returns whether it did anything. */
  async handleWebhook(event: CacheWebhookEvent): Promise<boolean> {
    if (CATALOG_EVENTS.has(event.type)) {
      await this.invalidateCatalog();
      return true;
    }
    if (!CUSTOMER_EVENTS.has(event.type) && !event.type.startsWith("subscription.")) return false;
    const data = (event.data ?? {}) as { customer_external_id?: string; subscription?: { customer_external_id?: string } };
    const customer = data.customer_external_id || data.subscription?.customer_external_id;
    if (!customer) return false;
    await this.invalidateCustomer(customer);
    return true;
  }

  /** The customer's next entitlement checks ask the API. */
  async invalidateCustomer(customer: string): Promise<void> {
    await this.write(this.customerGenKey(customer), token(), this.genTtl);
  }

  /** The next catalog read asks the API. */
  async invalidateCatalog(): Promise<void> {
    await this.write(this.base + "gen:catalog", token(), this.genTtl);
  }

  private customerGenKey(customer: string): string {
    return this.base + `gen:c:${customer}`;
  }

  private customerGen(customer: string): Promise<string | null> {
    return this.generation(this.customerGenKey(customer));
  }

  private catalogGen(): Promise<string | null> {
    return this.generation(this.base + "gen:catalog");
  }

  // A missing generation is created. It is not refreshed on read: re-setting the value read could undo an invalidation written in between, and a
  // generation that expires only costs one miss (the new one is random). null: the store failed, so nothing is cached.
  private generation(key: string): Promise<string | null> {
    return this.shared(key, async () => {
      const gen = await this.read(key);
      if (gen === undefined) return null;
      if (gen) return gen;
      const fresh = token();
      return (await this.write(key, fresh, this.genTtl)) ? fresh : null;
    });
  }

  private async cached<T>(genP: Promise<string | null>, keyOf: (gen: string) => string, ttl: number, load: () => Promise<T>): Promise<T> {
    const gen = await genP;
    if (gen === null) return load();
    const key = this.base + keyOf(gen);
    const raw = await this.read(key);
    if (raw) {
      const hit = parse(raw) as T | undefined;
      if (hit !== undefined) return hit;
    }
    return this.shared(key, async () => {
      const value = await load();
      await this.write(key, JSON.stringify(value), ttl);
      return value;
    });
  }

  /** Concurrent calls with the same key share one run. */
  private shared<T>(key: string, run: () => Promise<T>): Promise<T> {
    const running = this.inflight.get(key) as Promise<T> | undefined;
    if (running) return running;
    const p = run().finally(() => this.inflight.delete(key));
    this.inflight.set(key, p);
    return p;
  }

  /** undefined when the store failed. */
  private async read(key: string): Promise<string | null | undefined> {
    try {
      return (await this.store.get(key)) ?? null;
    } catch (err) {
      this.onError(err);
      return undefined;
    }
  }

  private async write(key: string, value: string, ttl: number): Promise<boolean> {
    try {
      await this.store.set(key, value, ttl);
      return true;
    } catch (err) {
      this.onError(err);
      return false;
    }
  }

  private async remove(key: string): Promise<void> {
    try {
      await this.store.delete(key);
    } catch (err) {
      this.onError(err);
    }
  }
}

const token = (): string => globalThis.crypto.randomUUID().replaceAll("-", "").slice(0, 16);

type Status = Partial<EntitlementStatus> & { remaining?: string | null };

function parse(raw: string): unknown {
  try {
    return JSON.parse(raw) as unknown;
  } catch {
    return undefined;
  }
}

/** The status with `quantity` counted in, or undefined when it lacks what is needed (the caller then drops it). */
function countUsage(s: Status | undefined, quantity: string): EntitlementStatus | undefined {
  if (!s || typeof s.used !== "string") return undefined;
  const used = addDecimal(s.used, quantity);
  if (used === undefined) return undefined;
  const next = { ...s, used } as EntitlementStatus & { remaining?: string | null };
  if (s.unlimited || s.limit == null) return next;
  const cmp = compareDecimal(used, s.limit);
  if (cmp === undefined) return undefined;
  next.over_limit = cmp > 0;
  if (typeof s.remaining === "string") {
    const left = addDecimal(s.limit, "-" + used);
    next.remaining = left === undefined || left.startsWith("-") ? "0" : left;
  }
  if (s.mode === "hard") next.allowed = !s.suspended && cmp < 0;
  return next;
}

const DECIMAL = /^([+-]?)(\d+)(?:\.(\d+))?$/;

function scaled(a: string, b: string): [bigint, bigint, number] | undefined {
  const ma = DECIMAL.exec(a.replace(/^--/, ""));
  const mb = DECIMAL.exec(b.replace(/^--/, ""));
  if (!ma || !mb) return undefined;
  const scale = Math.max(ma[3]?.length ?? 0, mb[3]?.length ?? 0);
  const big = (m: RegExpExecArray) => BigInt((m[1] ?? "") + (m[2] ?? "") + (m[3] ?? "").padEnd(scale, "0"));
  return [big(ma), big(mb), scale];
}

function addDecimal(a: string, b: string): string | undefined {
  const s = scaled(a, b);
  if (!s) return undefined;
  const sum = s[0] + s[1];
  const digits = (sum < 0n ? -sum : sum).toString().padStart(s[2] + 1, "0");
  const int = digits.slice(0, digits.length - s[2]);
  const frac = digits.slice(digits.length - s[2]).replace(/0+$/, "");
  return (sum < 0n ? "-" : "") + int + (frac ? "." + frac : "");
}

function compareDecimal(a: string, b: string): number | undefined {
  const s = scaled(a, b);
  return s && (s[0] < s[1] ? -1 : s[0] > s[1] ? 1 : 0);
}
