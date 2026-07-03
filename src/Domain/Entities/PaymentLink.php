<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Entities;

use ImaginaPay\Domain\Enums\PaymentLinkStatus;

final class PaymentLink
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly int $customerId,
        public readonly ?int $subscriptionId,
        public readonly int $priceId,
        public readonly string $gateway,
        public readonly ?string $gatewayRef,
        public readonly string $url,
        public readonly PaymentLinkStatus $status,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?int $paidOrderId,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}
