<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Domain;

use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\BillingEngine;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Gateways\GatewayMode;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Gateways\TokenizedGatewayInterface;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class BillingEngineTest extends TestCase
{
    use EntityFactory;

    private \DateTimeImmutable $now;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $subscriptions;

    /** @var PriceRepository&MockInterface */
    private PriceRepository $prices;

    /** @var TokenizedGatewayInterface&MockInterface */
    private TokenizedGatewayInterface $wompi;

    private BillingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = $this->baseDate();

        /** @var SubscriptionRepository&MockInterface $subscriptions */
        $subscriptions = Mockery::mock(SubscriptionRepository::class);
        $this->subscriptions = $subscriptions;

        /** @var PriceRepository&MockInterface $prices */
        $prices = Mockery::mock(PriceRepository::class);
        $this->prices = $prices;
        $this->prices->shouldReceive('find')->andReturn($this->makePrice())->byDefault();

        /** @var TokenizedGatewayInterface&MockInterface $wompi */
        $wompi = Mockery::mock(TokenizedGatewayInterface::class);
        $wompi->shouldReceive('id')->andReturn('wompi');
        $wompi->shouldReceive('mode')->andReturn(GatewayMode::Tokenized);
        $this->wompi = $wompi;

        $registry = new GatewayRegistry();
        $registry->register($wompi);

        // StateMachine real (final): sus efectos se observan en el repo.
        $this->engine = new BillingEngine(
            $this->subscriptions,
            $this->prices,
            $registry,
            new SubscriptionStateMachine($this->subscriptions, new NullLogger(), new FixedClock($this->now)),
            new FixedClock($this->now),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    private function dueSubscription(?array $meta = null, string $gateway = 'wompi', bool $cancelAtPeriodEnd = false): Subscription
    {
        $base = $this->makeSubscription(
            SubscriptionStatus::Active,
            currentPeriodEnd: $this->now->modify('-1 hour'),
            meta: $meta ?? ['payment_source_id' => '881'],
        );

        // El factory no expone gateway/cancelAtPeriodEnd: se reconstruye.
        return new Subscription(
            id: $base->id,
            uuid: $base->uuid,
            customerId: $base->customerId,
            productId: $base->productId,
            priceId: $base->priceId,
            gateway: $gateway,
            gatewaySubId: null,
            status: $base->status,
            currentPeriodStart: null,
            currentPeriodEnd: $base->currentPeriodEnd,
            cancelAtPeriodEnd: $cancelAtPeriodEnd,
            cancelledAt: null,
            failedPayments: 0,
            meta: $base->meta,
            createdAt: $base->createdAt,
            updatedAt: $base->updatedAt,
        );
    }

    public function testChargesDueSubscriptionAndClaimsAttemptBeforeCallingGateway(): void
    {
        $subscription = $this->dueSubscription();
        $this->subscriptions->shouldReceive('findDueForBilling')->andReturn([$subscription]);

        $claimed = false;

        $this->subscriptions->shouldReceive('updateMeta')
            ->once()
            ->with(5, Mockery::on(static function (array $meta) use (&$claimed): bool {
                $claimed = ($meta['billing']['attempts'] ?? 0) === 1
                    && str_contains((string) ($meta['billing']['last_reference'] ?? ''), 'impay-ren-');

                return $claimed;
            }), $this->now);

        $this->wompi->shouldReceive('chargeStoredSource')
            ->once()
            ->with($subscription, Mockery::any(), '881', Mockery::on(
                static fn (string $reference): bool => str_starts_with($reference, 'impay-ren-')
                    && str_contains($reference, $subscription->uuid)
                    && str_ends_with($reference, '-a1'),
            ))
            ->andReturnUsing(static function () use (&$claimed): string {
                // El intento debe reclamarse ANTES de llamar a la pasarela.
                if (!$claimed) {
                    throw new \RuntimeException('El cobro salió sin reclamar el intento.');
                }

                return 'txn-1';
            });

        $this->assertSame(1, $this->engine->run());
    }

    public function testDuplicateRunDoesNotChargeTwice(): void
    {
        // Segunda corrida del día: el meta ya tiene el intento 1 reclamado
        // hoy → el reintento 2 solo procede a las 24h.
        $subscription = $this->dueSubscription(meta: [
            'payment_source_id' => '881',
            'billing' => [
                'period' => $this->now->modify('-1 hour')->format('Ymd'),
                'attempts' => 1,
                'first_attempt' => $this->now->modify('-2 hours')->format('Y-m-d H:i:s'),
            ],
        ]);

        $this->subscriptions->shouldReceive('findDueForBilling')->andReturn([$subscription]);

        $this->subscriptions->shouldNotReceive('updateMeta');
        $this->wompi->shouldNotReceive('chargeStoredSource');

        $this->assertSame(0, $this->engine->run());
    }

    public function testRetriesAfter24Hours(): void
    {
        $subscription = $this->dueSubscription(meta: [
            'payment_source_id' => '881',
            'billing' => [
                'period' => $this->now->modify('-1 hour')->format('Ymd'),
                'attempts' => 1,
                'first_attempt' => $this->now->modify('-25 hours')->format('Y-m-d H:i:s'),
            ],
        ]);

        $this->subscriptions->shouldReceive('findDueForBilling')->andReturn([$subscription]);
        $this->subscriptions->shouldReceive('updateMeta')->once();

        $this->wompi->shouldReceive('chargeStoredSource')
            ->once()
            ->with(Mockery::any(), Mockery::any(), '881', Mockery::on(
                static fn (string $reference): bool => str_ends_with($reference, '-a2'),
            ))
            ->andReturn('txn-2');

        $this->assertSame(1, $this->engine->run());
    }

    public function testStopsAfterMaxAttempts(): void
    {
        $subscription = $this->dueSubscription(meta: [
            'payment_source_id' => '881',
            'billing' => [
                'period' => $this->now->modify('-1 hour')->format('Ymd'),
                'attempts' => 3,
                'first_attempt' => $this->now->modify('-100 hours')->format('Y-m-d H:i:s'),
            ],
        ]);

        $this->subscriptions->shouldReceive('findDueForBilling')->andReturn([$subscription]);
        $this->wompi->shouldNotReceive('chargeStoredSource');

        $this->assertSame(0, $this->engine->run());
    }

    public function testCancelAtPeriodEndExpiresWithoutCharging(): void
    {
        $subscription = $this->dueSubscription(cancelAtPeriodEnd: true);

        $this->subscriptions->shouldReceive('findDueForBilling')->andReturn([$subscription]);
        $this->wompi->shouldNotReceive('chargeStoredSource');

        // La transición Active→Expired pasa por la state machine real.
        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Expired, $this->now, null);

        $this->assertSame(0, $this->engine->run());
    }

    public function testIgnoresHostedGatewaySubscriptions(): void
    {
        // Una suscripción MP vencida (webhook perdido) jamás se cobra aquí.
        $subscription = $this->dueSubscription(gateway: 'mercadopago');

        $this->subscriptions->shouldReceive('findDueForBilling')->andReturn([$subscription]);
        $this->wompi->shouldNotReceive('chargeStoredSource');

        $this->assertSame(0, $this->engine->run());
    }
}
