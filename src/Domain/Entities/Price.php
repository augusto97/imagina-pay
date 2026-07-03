<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Entities;

use ImaginaPay\Domain\Enums\PriceInterval;
use ImaginaPay\Domain\Enums\PriceStatus;
use ImaginaPay\Support\Money;

final class Price
{
    /**
     * @param array<string, mixed>|null $gatewayRefs Referencias de planes en pasarelas
     *                                               (p. ej. {"mercadopago_plan_id": "...", "paypal_plan_id": "P-XXX"}).
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly int $productId,
        public readonly string $currency,
        public readonly int $amount,
        public readonly PriceInterval $interval,
        public readonly int $trialDays,
        public readonly ?array $gatewayRefs,
        public readonly PriceStatus $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }
}
