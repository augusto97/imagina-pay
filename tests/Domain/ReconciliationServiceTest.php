<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Domain;

use Brain\Monkey\Actions;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\ReconciliationService;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Gateways\GatewayInterface;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class ReconciliationServiceTest extends TestCase
{
    use EntityFactory;

    private \DateTimeImmutable $now;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $subscriptions;

    /** @var OrderRepository&MockInterface */
    private OrderRepository $orders;

    /** @var PaymentLinkRepository&MockInterface */
    private PaymentLinkRepository $paymentLinks;

    private GatewayRegistry $gateways;

    private ReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = $this->baseDate();

        /** @var SubscriptionRepository&MockInterface $subscriptions */
        $subscriptions = Mockery::mock(SubscriptionRepository::class);
        $this->subscriptions = $subscriptions;

        /** @var OrderRepository&MockInterface $orders */
        $orders = Mockery::mock(OrderRepository::class);
        $this->orders = $orders;

        /** @var PaymentLinkRepository&MockInterface $paymentLinks */
        $paymentLinks = Mockery::mock(PaymentLinkRepository::class);
        $this->paymentLinks = $paymentLinks;

        $this->gateways = new GatewayRegistry();

        $this->service = new ReconciliationService(
            $this->subscriptions,
            $this->orders,
            $this->paymentLinks,
            $this->gateways,
            new SubscriptionStateMachine($this->subscriptions, new NullLogger(), new FixedClock($this->now)),
            new FixedClock($this->now),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $remote
     * @return GatewayInterface&MockInterface
     */
    private function registerGateway(array $remote): GatewayInterface
    {
        /** @var GatewayInterface&MockInterface $gateway */
        $gateway = Mockery::mock(GatewayInterface::class);
        $gateway->shouldReceive('id')->andReturn('mercadopago');
        $gateway->shouldReceive('fetchSubscription')->andReturn($remote);
        $this->gateways->register($gateway);

        return $gateway;
    }

    public function testDivergenceIsCorrected(): void
    {
        // Local activa, la pasarela dice cancelada (webhook perdido).
        $subscription = $this->makeSubscription(SubscriptionStatus::Active);

        $this->subscriptions->shouldReceive('findForReconciliation')->andReturn([$subscription]);
        $this->registerGateway(['status' => 'cancelled']);

        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Cancelled, $this->now, $this->now);

        Actions\expectDone('impay_subscription_cancelled')->once();

        $this->service->reconcile();
    }

    public function testMatchingStatusIsLeftAlone(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active);

        $this->subscriptions->shouldReceive('findForReconciliation')->andReturn([$subscription]);
        $this->registerGateway(['status' => 'authorized']); // authorized ≡ active

        $this->subscriptions->shouldNotReceive('updateStatus');

        $this->service->reconcile();
    }

    public function testPendingLocalWithAuthorizedRemoteActivates(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Pending);

        $this->subscriptions->shouldReceive('findForReconciliation')->andReturn([$subscription]);
        $this->registerGateway(['status' => 'authorized']);

        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Active, $this->now, null);

        Actions\expectDone('impay_subscription_active')->once();

        $this->service->reconcile();
    }

    public function testGatewayFailureDoesNotAbortTheRun(): void
    {
        $failing = $this->makeSubscription(SubscriptionStatus::Active, gatewaySubId: 'preapproval-1');
        $ok = $this->makeSubscription(SubscriptionStatus::Active, gatewaySubId: 'preapproval-2', id: 6);

        $this->subscriptions->shouldReceive('findForReconciliation')->andReturn([$failing, $ok]);

        /** @var GatewayInterface&MockInterface $gateway */
        $gateway = Mockery::mock(GatewayInterface::class);
        $gateway->shouldReceive('id')->andReturn('mercadopago');
        $gateway->shouldReceive('fetchSubscription')
            ->twice()
            ->andReturnUsing(static function (string $id): array {
                if ($id === 'preapproval-1') {
                    throw new \RuntimeException('timeout');
                }

                return ['status' => 'paused'];
            });
        $this->gateways->register($gateway);

        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(6, SubscriptionStatus::Paused, $this->now, null);

        $this->service->reconcile();
    }

    public function testExpireStaleExpiresOrdersLinksAndOverdueAnnuals(): void
    {
        $this->orders->shouldReceive('expireStale')
            ->once()
            ->with(Mockery::on(fn (\DateTimeImmutable $t): bool => $t == $this->now->modify('-48 hours')), $this->now)
            ->andReturn(2);

        $this->paymentLinks->shouldReceive('expireStale')->once()->with($this->now)->andReturn(1);

        $overdue = $this->makeSubscription(
            SubscriptionStatus::Active,
            currentPeriodEnd: $this->now->modify('-10 days'),
            gatewaySubId: null,
        );

        $this->subscriptions->shouldReceive('findLogicalExpiring')
            ->once()
            ->with(Mockery::on(fn (\DateTimeImmutable $t): bool => $t == $this->now->modify('-7 days')))
            ->andReturn([$overdue]);

        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Expired, $this->now, null);

        Actions\expectDone('impay_subscription_expired')->once();
        Actions\expectDone('impay_service_suspend')->once();

        $this->service->expireStale();
    }
}
