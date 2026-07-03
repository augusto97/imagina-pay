<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Support;

use ImaginaPay\Domain\Entities\Customer;
use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Product;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\OrderKind;
use ImaginaPay\Domain\Enums\OrderStatus;
use ImaginaPay\Domain\Enums\PriceInterval;
use ImaginaPay\Domain\Enums\PriceStatus;
use ImaginaPay\Domain\Enums\ProductStatus;
use ImaginaPay\Domain\Enums\ProductType;
use ImaginaPay\Domain\Enums\SubscriptionStatus;

/**
 * Constructores de entidades con valores por defecto sensatos para tests.
 */
trait EntityFactory
{
    private function baseDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-03 12:00:00', new \DateTimeZone('UTC'));
    }

    private function makeProduct(
        ProductType $type = ProductType::Subscription,
        ProductStatus $status = ProductStatus::Active,
        int $id = 2,
    ): Product {
        return new Product(
            id: $id,
            uuid: '11111111-1111-4111-8111-111111111111',
            name: 'VPS Mensual',
            slug: 'vps-mensual',
            type: $type,
            description: null,
            features: null,
            imageUrl: null,
            status: $status,
            provisioning: null,
            createdAt: $this->baseDate(),
            updatedAt: $this->baseDate(),
        );
    }

    private function makePrice(
        PriceInterval $interval = PriceInterval::Month,
        string $currency = 'COP',
        int $amount = 4990000,
        int $id = 3,
        int $productId = 2,
        PriceStatus $status = PriceStatus::Active,
    ): Price {
        return new Price(
            id: $id,
            uuid: '22222222-2222-4222-8222-222222222222',
            productId: $productId,
            currency: $currency,
            amount: $amount,
            interval: $interval,
            trialDays: 0,
            gatewayRefs: null,
            status: $status,
            createdAt: $this->baseDate(),
            updatedAt: $this->baseDate(),
        );
    }

    private function makeCustomer(int $id = 1): Customer
    {
        return new Customer(
            id: $id,
            uuid: '33333333-3333-4333-8333-333333333333',
            wpUserId: null,
            email: 'cliente@example.com',
            fullName: 'Cliente de Prueba',
            company: null,
            taxIdType: null,
            taxId: null,
            country: 'CO',
            phone: null,
            gatewayRefs: null,
            createdAt: $this->baseDate(),
            updatedAt: $this->baseDate(),
        );
    }

    private function makeOrder(
        OrderStatus $status = OrderStatus::Pending,
        OrderKind $kind = OrderKind::Purchase,
        int $id = 9,
        ?int $subscriptionId = null,
    ): Order {
        return new Order(
            id: $id,
            uuid: '44444444-4444-4444-8444-444444444444',
            customerId: 1,
            productId: 2,
            priceId: 3,
            subscriptionId: $subscriptionId,
            kind: $kind,
            status: $status,
            currency: 'COP',
            amount: 4990000,
            gateway: 'mercadopago',
            gatewayRef: null,
            gatewayPaymentId: null,
            externalReference: '44444444-4444-4444-8444-444444444444',
            paidAt: null,
            meta: null,
            createdAt: $this->baseDate(),
            updatedAt: $this->baseDate(),
        );
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    private function makeSubscription(
        SubscriptionStatus $status = SubscriptionStatus::Pending,
        ?\DateTimeImmutable $currentPeriodEnd = null,
        ?array $meta = null,
        ?string $gatewaySubId = 'preapproval-1',
        int $id = 5,
    ): Subscription {
        return new Subscription(
            id: $id,
            uuid: '55555555-5555-4555-8555-555555555555',
            customerId: 1,
            productId: 2,
            priceId: 3,
            gateway: 'mercadopago',
            gatewaySubId: $gatewaySubId,
            status: $status,
            currentPeriodStart: null,
            currentPeriodEnd: $currentPeriodEnd,
            cancelAtPeriodEnd: false,
            cancelledAt: null,
            failedPayments: 0,
            meta: $meta,
            createdAt: $this->baseDate()->modify('-1 month'),
            updatedAt: $this->baseDate()->modify('-1 day'),
        );
    }
}
