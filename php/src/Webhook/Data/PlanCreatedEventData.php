<?php

declare(strict_types=1);

namespace Payrudder\Webhook\Data;

use Payrudder\Internal\Cast;
use Payrudder\Model\Plan;

/**
 * The `data` of `plan.created`: the plan as the API returns it.
 * Read it with `WebhookEvent::dataAs(PlanCreatedEventData::class)`.
 */
final class PlanCreatedEventData implements EventData
{
    public function __construct(
        public readonly Plan $plan,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            plan: Plan::fromArray(Cast::map($data['plan'] ?? null) ?? []),
        );
    }
}
