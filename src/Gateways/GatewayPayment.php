<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways;

use ImaginaPay\Domain\Enums\PaymentStatus;

/**
 * Cobro individual reportado por una pasarela (webhook o fetch),
 * normalizado al dominio: montos en unidad mínima, estado propio.
 */
final class GatewayPayment
{
    /**
     * @param array<string, mixed> $raw Payload original de la pasarela (auditoría).
     */
    public function __construct(
        public readonly string $gateway,
        public readonly string $gatewayPaymentId,
        public readonly PaymentStatus $status,
        public readonly string $currency,
        public readonly int $amount,
        public readonly ?string $method,
        public readonly ?\DateTimeImmutable $paidAt,
        public readonly array $raw,
    ) {
    }
}
