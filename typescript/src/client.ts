import { PayrudderResources } from "./generated/client-base";
import { HttpTransport, resolveConfig, type PayrudderOptions } from "./transport";

/**
 * Client of the Payrudder tenant API (`/v1/*`).
 *
 * ```ts
 * const payrudder = new Payrudder({ apiKey: "prk_test_..." });
 * const customer = await payrudder.customers.getCustomer("cus_42");
 * ```
 */
export class Payrudder extends PayrudderResources {
  /** The API key's environment: `prk_test_` keys are "test", every other key "live". */
  readonly environment: "test" | "live";

  constructor(options: PayrudderOptions = {}) {
    const config = resolveConfig(options);
    super(new HttpTransport(config));
    this.environment = config.apiKey.startsWith("prk_test_") ? "test" : "live";
  }
}
