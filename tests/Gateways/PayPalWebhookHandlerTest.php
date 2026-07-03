<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Gateways;

use Brain\Monkey\Actions;
use ImaginaPay\Domain\Enums\OrderStatus;
use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\PaymentService;
use ImaginaPay\Domain\Services\RenewalService;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Gateways\PayPal\PayPalClient;
use ImaginaPay\Gateways\PayPal\PayPalWebhookHandler;
use ImaginaPay\Gateways\WebhookEvent;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class PayPalWebhookHandlerTest extends TestCase
{
    use EntityFactory;

    /** @var PayPalClient&MockInterface */
    private PayPalClient $client;

    /** @var OrderRepository&MockInterface */
    private OrderRepository $orders;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $subscriptions;

    /** @var PaymentLinkRepository&MockInterface */
    private PaymentLinkRepository $paymentLinks;

    /** @var PaymentService&MockInterface */
    private PaymentService $payments;

    /** @var RenewalService&MockInterface */
    private RenewalService $renewals;

    private PayPalWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var PayPalClient&MockInterface $client */
        $client = Mockery::mock(PayPalClient::class);
        $this->client = $client;

        /** @var OrderRepository&MockInterface $orders */
        $orders = Mockery::mock(OrderRepository::class);
        $this->orders = $orders;

        /** @var SubscriptionRepository&MockInterface $subscriptions */
        $subscriptions = Mockery::mock(SubscriptionRepository::class);
        $this->subscriptions = $subscriptions;

        /** @var PaymentLinkRepository&MockInterface $paymentLinks */
        $paymentLinks = Mockery::mock(PaymentLinkRepository::class);
        $this->paymentLinks = $paymentLinks;

        /** @var PaymentService&MockInterface $payments */
        $payments = Mockery::mock(PaymentService::class);
        $this->payments = $payments;

        /** @var RenewalService&MockInterface $renewals */
        $renewals = Mockery::mock(RenewalService::class);
        $this->renewals = $renewals;

        $this->handler = new PayPalWebhookHandler(
            $this->client,
            $this->orders,
            $this->subscriptions,
            $this->paymentLinks,
            $this->payments,
            $this->renewals,
            new SubscriptionStateMachine($this->subscriptions, new NullLogger(), new FixedClock($this->baseDate())),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function event(string $type, array $resource): WebhookEvent
    {
        return new WebhookEvent('paypal', 'WH-1', $type, [
            'body' => ['id' => 'WH-1', 'event_type' => $type, 'resource' => $resource],
        ]);
    }

    public function testCaptureCompletedAppliesOrderPayment(): void
    {
        $order = $this->makeOrder(OrderStatus::Pending);

        $this->orders->shouldReceive('findByExternalReference')
            ->with($order->externalReference)
            ->andReturn($order);

        $this->payments->shouldReceive('applyOrderPayment')
            ->once()
            ->with($order, Mockery::on(static fn (GatewayPayment $p): bool => $p->gateway === 'paypal'
                && $p->gatewayPaymentId === 'CAP-1'
                && $p->status === PaymentStatus::Approved
                && $p->amount === 1299
                && $p->currency === 'USD'));

        $this->handler->handle($this->event('PAYMENT.CAPTURE.COMPLETED', [
            'id' => 'CAP-1',
            'status' => 'COMPLETED',
            'custom_id' => $order->externalReference,
            'amount' => ['currency_code' => 'USD', 'value' => '12.99'],
        ]));
    }

    public function testCaptureForPaymentLinkGoesToRenewalService(): void
    {
        $link = new \ImaginaPay\Domain\Entities\PaymentLink(
            id: 4,
            uuid: '77777777-7777-4777-8777-777777777777',
            customerId: 1,
            subscriptionId: 5,
            priceId: 3,
            gateway: 'paypal',
            gatewayRef: 'PP-ORDER-9',
            url: 'https://paypal.test/approve',
            status: \ImaginaPay\Domain\Enums\PaymentLinkStatus::Open,
            expiresAt: null,
            paidOrderId: null,
            createdAt: $this->baseDate(),
        );

        $this->orders->shouldReceive('findByExternalReference')->andReturnNull();
        $this->paymentLinks->shouldReceive('findByUuid')->with($link->uuid)->andReturn($link);

        $this->renewals->shouldReceive('applyPaidLink')
            ->once()
            ->with($link, Mockery::type(GatewayPayment::class));

        $this->handler->handle($this->event('PAYMENT.CAPTURE.COMPLETED', [
            'id' => 'CAP-2',
            'custom_id' => $link->uuid,
            'amount' => ['currency_code' => 'USD', 'value' => '99.00'],
        ]));
    }

    public function testSaleCompletedAppliesSubscriptionRenewal(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active, gatewaySubId: 'I-SUB-1');
        $subscription = new \ImaginaPay\Domain\Entities\Subscription(
            $subscription->id,
            $subscription->uuid,
            $subscription->customerId,
            $subscription->productId,
            $subscription->priceId,
            'paypal',
            'I-SUB-1',
            $subscription->status,
            null,
            null,
            false,
            null,
            0,
            null,
            $subscription->createdAt,
            $subscription->updatedAt,
        );

        $this->subscriptions->shouldReceive('findByGatewaySubId')
            ->with('paypal', 'I-SUB-1')
            ->andReturn($subscription);

        $this->payments->shouldReceive('applySubscriptionPayment')
            ->once()
            ->with($subscription, Mockery::on(static fn (GatewayPayment $p): bool => $p->gatewayPaymentId === 'SALE-1'
                && $p->status === PaymentStatus::Approved
                && $p->amount === 1299));

        $this->handler->handle($this->event('PAYMENT.SALE.COMPLETED', [
            'id' => 'SALE-1',
            'billing_agreement_id' => 'I-SUB-1',
            'amount' => ['currency' => 'USD', 'total' => '12.99'],
        ]));
    }

    public function testSubscriptionActivatedTransitionsToActive(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Pending, gatewaySubId: 'I-SUB-1');

        $this->subscriptions->shouldReceive('findByGatewaySubId')->andReturnNull();
        $this->subscriptions->shouldReceive('findByUuid')->with($subscription->uuid)->andReturn($subscription);

        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Active, Mockery::type(\DateTimeImmutable::class), null);

        Actions\expectDone('impay_subscription_active')->once();

        $this->handler->handle($this->event('BILLING.SUBSCRIPTION.ACTIVATED', [
            'id' => 'I-NUEVO',
            'custom_id' => $subscription->uuid,
        ]));
    }

    public function testActivatedLinksGatewaySubIdWhenMissing(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Pending, gatewaySubId: null);

        $this->subscriptions->shouldReceive('findByGatewaySubId')->andReturnNull();
        $this->subscriptions->shouldReceive('findByUuid')->andReturn($subscription);
        $this->subscriptions->shouldReceive('setGatewaySubId')
            ->once()
            ->with(5, 'I-NUEVO', Mockery::type(\DateTimeImmutable::class));
        $this->subscriptions->shouldReceive('updateStatus')->once();

        $this->handler->handle($this->event('BILLING.SUBSCRIPTION.ACTIVATED', [
            'id' => 'I-NUEVO',
            'custom_id' => $subscription->uuid,
        ]));
    }

    public function testSuspendedTransitionsToPaused(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active, gatewaySubId: 'I-SUB-1');

        $this->subscriptions->shouldReceive('findByGatewaySubId')->andReturn($subscription);
        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Paused, Mockery::type(\DateTimeImmutable::class), null);

        Actions\expectDone('impay_subscription_paused')->once();

        $this->handler->handle($this->event('BILLING.SUBSCRIPTION.SUSPENDED', ['id' => 'I-SUB-1']));
    }

    public function testPaymentFailedDelegatesToChargeFailure(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active, gatewaySubId: 'I-SUB-1');

        $this->subscriptions->shouldReceive('findByGatewaySubId')->andReturn($subscription);
        $this->payments->shouldReceive('applyChargeFailure')->once()->with($subscription, Mockery::type('array'));

        $this->handler->handle($this->event('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-SUB-1']));
    }

    public function testApprovedOrderIsCapturedAndProcessed(): void
    {
        $order = $this->makeOrder(OrderStatus::Pending);

        $this->client->shouldReceive('post')
            ->once()
            ->with('/v2/checkout/orders/PP-ORDER-1/capture', [], 'impay-capture-PP-ORDER-1')
            ->andReturn([
                'purchase_units' => [[
                    'payments' => [
                        'captures' => [[
                            'id' => 'CAP-9',
                            'status' => 'COMPLETED',
                            'custom_id' => $order->externalReference,
                            'amount' => ['currency_code' => 'USD', 'value' => '12.99'],
                        ]],
                    ],
                ]],
            ]);

        $this->orders->shouldReceive('findByExternalReference')->andReturn($order);
        $this->payments->shouldReceive('applyOrderPayment')
            ->once()
            ->with($order, Mockery::on(static fn (GatewayPayment $p): bool => $p->gatewayPaymentId === 'CAP-9'
                && $p->status === PaymentStatus::Approved));

        $this->handler->handle($this->event('CHECKOUT.ORDER.APPROVED', ['id' => 'PP-ORDER-1']));
    }

    public function testUnknownEventIsIgnored(): void
    {
        $this->handler->handle($this->event('CUSTOMER.DISPUTE.CREATED', ['id' => 'X']));

        $this->addToAssertionCount(1); // Sin llamadas a mocks: no explota.
    }
}
