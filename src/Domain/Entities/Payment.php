<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Entities;

use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Support\Money;

final class Payment
{
    /**
     * @param array<string, mixed>|null $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly ?int $orderId,
        public readonly ?int $subscriptionId,
        public readonly int $customerId,
        public readonly string $gateway,
        public readonly string $gatewayPaymentId,
        public readonly PaymentStatus $status,
        public readonly string $currency,
        public readonly int $amount,
        public readonly ?string $method,
        public readonly ?\DateTimeImmutable $paidAt,
        public readonly ?array $raw,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }
}
