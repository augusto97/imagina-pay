<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Entities;

use ImaginaPay\Domain\Enums\OrderKind;
use ImaginaPay\Domain\Enums\OrderStatus;
use ImaginaPay\Support\Money;

final class Order
{
    /**
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly int $customerId,
        public readonly int $productId,
        public readonly int $priceId,
        public readonly ?int $subscriptionId,
        public readonly OrderKind $kind,
        public readonly OrderStatus $status,
        public readonly string $currency,
        public readonly int $amount,
        public readonly string $gateway,
        public readonly ?string $gatewayRef,
        public readonly ?string $gatewayPaymentId,
        public readonly string $externalReference,
        public readonly ?\DateTimeImmutable $paidAt,
        public readonly ?array $meta,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }
}
