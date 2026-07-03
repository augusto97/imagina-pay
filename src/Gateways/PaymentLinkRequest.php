<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways;

use ImaginaPay\Domain\Entities\Customer;
use ImaginaPay\Support\Money;

/**
 * Solicitud de link de pago (renovaciones annual_hybrid y cobros manuales).
 */
final class PaymentLinkRequest
{
    public function __construct(
        public readonly Customer $customer,
        public readonly Money $amount,
        public readonly string $description,
        public readonly ?int $subscriptionId = null,
        public readonly ?int $priceId = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}
