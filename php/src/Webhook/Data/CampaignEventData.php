<?php

declare(strict_types=1);

namespace Payrudder\Webhook\Data;

use Payrudder\Internal\Cast;
use Payrudder\Model\Coupon;
use Payrudder\Model\PromotionCode;

/**
 * The `data` of `campaign.started` and `campaign.ended`: the campaign's promotion code and its coupon, as the API returns them.
 * Read it with `WebhookEvent::dataAs(CampaignEventData::class)`.
 */
final class CampaignEventData implements EventData
{
    public function __construct(
        public readonly PromotionCode $promotionCode,
        public readonly Coupon $coupon,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            promotionCode: PromotionCode::fromArray(Cast::map($data['promotion_code'] ?? null) ?? []),
            coupon: Coupon::fromArray(Cast::map($data['coupon'] ?? null) ?? []),
        );
    }
}
