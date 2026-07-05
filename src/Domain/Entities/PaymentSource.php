<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Entities;

/**
 * Medio de pago tokenizado (tarjeta o Nequi) de un gateway modo
 * Tokenized. El token real vive en la pasarela; aquí solo la referencia
 * (gateway_source_id) y datos de presentación (brand, last_four).
 */
final class PaymentSource
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly int $customerId,
        public readonly string $gateway,
        public readonly string $gatewaySourceId,
        public readonly string $type,
        public readonly ?string $brand,
        public readonly ?string $lastFour,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }
}
