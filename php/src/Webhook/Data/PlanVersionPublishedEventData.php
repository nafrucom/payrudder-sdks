<?php

declare(strict_types=1);

namespace Payrudder\Webhook\Data;

use Payrudder\Internal\Cast;
use Payrudder\Model\PlanVersion;

/**
 * The `data` of `plan.version_published`: the version as the API returns it.
 * Read it with `WebhookEvent::dataAs(PlanVersionPublishedEventData::class)`.
 */
final class PlanVersionPublishedEventData implements EventData
{
    public function __construct(
        public readonly PlanVersion $planVersion,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            planVersion: PlanVersion::fromArray(Cast::map($data['plan_version'] ?? null) ?? []),
        );
    }
}
