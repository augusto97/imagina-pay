<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Entities;

use ImaginaPay\Domain\Enums\SubscriptionStatus;

/**
 * Suscripción local. Para productos annual_hybrid el registro existe
 * con gateway_sub_id = NULL: la "suscripción" es lógica (controla el
 * vencimiento y las renovaciones por link).
 */
final class Subscription
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
        public readonly string $gateway,
        public readonly ?string $gatewaySubId,
        public readonly SubscriptionStatus $status,
        public readonly ?\DateTimeImmutable $currentPeriodStart,
        public readonly ?\DateTimeImmutable $currentPeriodEnd,
        public readonly bool $cancelAtPeriodEnd,
        public readonly ?\DateTimeImmutable $cancelledAt,
        public readonly int $failedPayments,
        public readonly ?array $meta,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Copia inmutable con nuevo estado (las props readonly no permiten clone-with en PHP 8.1).
     */
    public function withStatus(
        SubscriptionStatus $status,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $cancelledAt = null,
    ): self {
        return new self(
            $this->id,
            $this->uuid,
            $this->customerId,
            $this->productId,
            $this->priceId,
            $this->gateway,
            $this->gatewaySubId,
            $status,
            $this->currentPeriodStart,
            $this->currentPeriodEnd,
            $this->cancelAtPeriodEnd,
            $cancelledAt ?? $this->cancelledAt,
            $this->failedPayments,
            $this->meta,
            $this->createdAt,
            $updatedAt,
        );
    }

    public function withCancelAtPeriodEnd(bool $cancelAtPeriodEnd, \DateTimeImmutable $updatedAt): self
    {
        return new self(
            $this->id,
            $this->uuid,
            $this->customerId,
            $this->productId,
            $this->priceId,
            $this->gateway,
            $this->gatewaySubId,
            $this->status,
            $this->currentPeriodStart,
            $this->currentPeriodEnd,
            $cancelAtPeriodEnd,
            $this->cancelledAt,
            $this->failedPayments,
            $this->meta,
            $this->createdAt,
            $updatedAt,
        );
    }
}
