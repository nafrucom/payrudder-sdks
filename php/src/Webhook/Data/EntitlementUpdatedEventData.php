<?php

declare(strict_types=1);

namespace Payrudder\Webhook\Data;

use Payrudder\Internal\Cast;

/**
 * The `data` of `entitlement.updated`: what the customer may use of a meter changed, so a cached entitlement is stale.
 * Read it with `WebhookEvent::dataAs(EntitlementUpdatedEventData::class)`.
 */
final class EntitlementUpdatedEventData implements EventData
{
    /**
     * @param string $meterCode the meter whose entitlement changed; empty: every meter of the customer
     */
    public function __construct(
        public readonly string $customerExternalId,
        public readonly string $meterCode = '',
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            customerExternalId: Cast::string($data['customer_external_id'] ?? null) ?? '',
            meterCode: Cast::string($data['meter_code'] ?? null) ?? '',
        );
    }
}
