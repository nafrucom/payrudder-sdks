import { PayrudderCache, type CacheWebhookEvent, type PayrudderCacheOptions } from "./cache";
import type { IngestEvent } from "./events";
import { PayrudderResources } from "./generated/client-base";
import type { ConnectCustomerResponse, CustomerAccess, EventResult } from "./generated/types";
import { HttpTransport, resolveConfig, type PayrudderOptions } from "./transport";

/** Who `connect` is about: your own user (or organisation) id, and for an organisation the signed-in person as `member`. */
export interface ConnectInput {
  id: string;
  name: string;
  /** Required for a person; a new organisation may leave it out and takes its first member's. */
  email?: string;
  locale?: "en" | "tr" | "de" | "fr" | "es" | "ar";
  /** The plan the customer page opens with, when the customer has no running subscription. */
  plan?: string;
  member?: { id: string; name?: string; email?: string };
  /** Default 1. */
  expiresInDays?: number;
}

/**
 * Client of the Payrudder tenant API (`/v1/*`).
 *
 * ```ts
 * const payrudder = new Payrudder({ apiKey: "prk_test_..." });
 * const { url } = await payrudder.connect({ id: user.id, name: user.name, email: user.email });
 * if (!(await payrudder.access(user.id, "api_calls")).allowed) return new Response("Payment required", { status: 402 });
 * ```
 */
export class Payrudder extends PayrudderResources {
  /** The API key's environment: `prk_test_` keys are "test", every other key "live". */
  readonly environment: "test" | "live";
  /** The cache `options.cache` asked for; undefined without it. */
  readonly cache?: PayrudderCache;

  constructor(options: PayrudderOptions & { cache?: PayrudderCacheOptions } = {}) {
    const config = resolveConfig(options);
    super(new HttpTransport(config));
    this.environment = config.apiKey.startsWith("prk_test_") ? "test" : "live";
    if (options.cache) this.cache = new PayrudderCache(this, options.cache);
  }

  /** Creates or updates the customer and returns the link to their customer page (`url` is null for a member who does not manage payment). */
  connect(input: ConnectInput): Promise<ConnectCustomerResponse> {
    const { id, plan, expiresInDays, ...rest } = input;
    const body = { ...rest, ...(plan !== undefined ? { plan_code: plan } : {}), ...(expiresInDays !== undefined ? { expires_in_days: expiresInDays } : {}) };
    return this.customers.connectCustomer(id, body);
  }

  /** Whether the customer may use the service now, by subscription, payment and the meters given. Cached when the client has a cache. */
  access(customer: string, meters: string | readonly string[] = [], options: { member?: string } = {}): Promise<CustomerAccess> {
    const list = typeof meters === "string" ? [meters] : meters;
    if (this.cache) return this.cache.access(customer, list, options.member);
    return this.customers.getCustomerAccess(customer, { meters: list.length ? list.join(",") : undefined, member: options.member });
  }

  /** Records one usage event; with a cache, counts it into the cached answers too. */
  async recordUsage(event: IngestEvent): Promise<EventResult> {
    if (this.cache) return this.cache.recordUsage(event);
    const [result] = await this.events.ingest([event]);
    return result!;
  }

  /** Invalidates cached answers for a verified webhook event; false without a cache or for an event that changes nothing cached. */
  async handleWebhook(event: CacheWebhookEvent): Promise<boolean> {
    return this.cache ? this.cache.handleWebhook(event) : false;
  }
}
