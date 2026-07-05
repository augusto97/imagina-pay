<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Domain;

use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\PriceInterval;
use ImaginaPay\Domain\Enums\PriceStatus;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\SubscriptionService;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Gateways\CheckoutSession;
use ImaginaPay\Gateways\GatewayInterface;
use ImaginaPay\Gateways\GatewayMode;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class SubscriptionServiceTest extends TestCase
{
    private \DateTimeImmutable $now;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $repository;

    private GatewayRegistry $gateways;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new \DateTimeImmutable('2026-07-03 12:00:00', new \DateTimeZone('UTC'));

        /** @var SubscriptionRepository&MockInterface $repository */
        $repository = Mockery::mock(SubscriptionRepository::class);
        $this->repository = $repository;

        $this->gateways = new GatewayRegistry();
        $clock = new FixedClock($this->now);
        $logger = new NullLogger();

        $this->service = new SubscriptionService(
            $this->repository,
            new SubscriptionStateMachine($this->repository, $logger, $clock),
            $this->gateways,
            $clock,
            $logger,
        );
    }

    /**
     * @return GatewayInterface&MockInterface
     */
    private function gateway(string $id, GatewayMode $mode): GatewayInterface
    {
        /** @var GatewayInterface&MockInterface $gateway */
        $gateway = Mockery::mock(GatewayInterface::class);
        $gateway->shouldReceive('id')->andReturn($id);
        $gateway->shouldReceive('mode')->andReturn($mode);

        return $gateway;
    }

    private function subscription(string $gateway, SubscriptionStatus $status, ?string $gatewaySubId = 'sub-1'): Subscription
    {
        return new Subscription(
            id: 5,
            uuid: '3b241101-e2bb-4255-8caf-4136c566a962',
            customerId: 1,
            productId: 2,
            priceId: 3,
            gateway: $gateway,
            gatewaySubId: $gatewaySubId,
            status: $status,
            currentPeriodStart: null,
            currentPeriodEnd: $this->now->modify('+20 days'),
            cancelAtPeriodEnd: false,
            cancelledAt: null,
            failedPayments: 0,
            meta: null,
            createdAt: $this->now->modify('-1 month'),
            updatedAt: $this->now->modify('-1 day'),
        );
    }

    private function price(): Price
    {
        return new Price(
            id: 3,
            uuid: '4c341101-e2bb-4255-8caf-4136c566a963',
            productId: 2,
            currency: 'COP',
            amount: 4990000,
            interval: PriceInterval::Month,
            trialDays: 0,
            gatewayRefs: null,
            status: PriceStatus::Active,
            createdAt: $this->now,
            updatedAt: $this->now,
        );
    }

    public function testHostedModeDelegatesSubscriptionCreationToGateway(): void
    {
        $gateway = $this->gateway('mercadopago', GatewayMode::HostedSubscription);
        $session = new CheckoutSession('https://mp.example/init_point', 'preapproval-9');
        $subscription = $this->subscription('mercadopago', SubscriptionStatus::Pending);
        $price = $this->price();

        $gateway->shouldReceive('createSubscription')
            ->once()
            ->with($subscription, $price)
            ->andReturn($session);

        $this->gateways->register($gateway);

        $this->assertSame($session, $this->service->startGatewaySubscription($subscription, $price));
    }

    public function testTokenizedModeDelegatesToGatewayCreateSubscription(): void
    {
        $gateway = $this->gateway('wompi', GatewayMode::Tokenized);
        $this->gateways->register($gateway);

        $subscription = $this->subscription('wompi', SubscriptionStatus::Pending);
        $price = $this->price();
        $session = new CheckoutSession('https://sitio.test/gracias-compra/');

        $gateway->shouldReceive('createSubscription')->once()->with($subscription, $price)->andReturn($session);

        $this->assertSame($session, $this->service->startGatewaySubscription($subscription, $price));
    }

    public function testCancelAtPeriodEndKeepsActiveAndStopsGatewayBilling(): void
    {
        $gateway = $this->gateway('mercadopago', GatewayMode::HostedSubscription);
        $gateway->shouldReceive('cancelSubscription')->once();
        $this->gateways->register($gateway);

        $subscription = $this->subscription('mercadopago', SubscriptionStatus::Active);

        $this->repository
            ->shouldReceive('markCancelAtPeriodEnd')
            ->once()
            ->with(5, true, $this->now);

        $result = $this->service->cancel($subscription, true);

        $this->assertSame(SubscriptionStatus::Active, $result->status);
        $this->assertTrue($result->cancelAtPeriodEnd);
    }

    public function testImmediateCancelTransitionsToCancelled(): void
    {
        $gateway = $this->gateway('mercadopago', GatewayMode::HostedSubscription);
        $gateway->shouldReceive('cancelSubscription')->once();
        $this->gateways->register($gateway);

        $this->repository
            ->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Cancelled, $this->now, $this->now);

        $result = $this->service->cancel($this->subscription('mercadopago', SubscriptionStatus::Active), false);

        $this->assertSame(SubscriptionStatus::Cancelled, $result->status);
    }

    public function testLogicalSubscriptionWithoutGatewaySubIdSkipsGatewayCall(): void
    {
        // annual_hybrid: gateway_sub_id NULL, no hay nada que cancelar en la pasarela.
        $this->repository
            ->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Cancelled, $this->now, $this->now);

        $result = $this->service->cancel(
            $this->subscription('mercadopago', SubscriptionStatus::Active, null),
            false,
        );

        $this->assertSame(SubscriptionStatus::Cancelled, $result->status);
    }
}
