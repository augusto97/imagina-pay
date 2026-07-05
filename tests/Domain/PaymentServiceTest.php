<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Domain;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use ImaginaPay\Domain\Entities\Payment;
use ImaginaPay\Domain\Enums\OrderStatus;
use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentRepository;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\PaymentService;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class PaymentServiceTest extends TestCase
{
    use EntityFactory;

    private \DateTimeImmutable $now;

    /** @var PaymentRepository&MockInterface */
    private PaymentRepository $payments;

    /** @var OrderRepository&MockInterface */
    private OrderRepository $orders;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $subscriptions;

    /** @var PriceRepository&MockInterface */
    private PriceRepository $prices;

    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_json_encode')->alias('json_encode');

        $this->now = $this->baseDate();

        /** @var PaymentRepository&MockInterface $payments */
        $payments = Mockery::mock(PaymentRepository::class);
        $this->payments = $payments;

        /** @var OrderRepository&MockInterface $orders */
        $orders = Mockery::mock(OrderRepository::class);
        $this->orders = $orders;

        /** @var SubscriptionRepository&MockInterface $subscriptions */
        $subscriptions = Mockery::mock(SubscriptionRepository::class);
        $this->subscriptions = $subscriptions;

        /** @var PriceRepository&MockInterface $prices */
        $prices = Mockery::mock(PriceRepository::class);
        $this->prices = $prices;

        $clock = new FixedClock($this->now);
        $logger = new NullLogger();

        $this->service = new PaymentService(
            $this->payments,
            $this->orders,
            $this->subscriptions,
            $this->prices,
            new SubscriptionStateMachine($this->subscriptions, $logger, $clock),
            $clock,
            $logger,
        );
    }

    private function gatewayPayment(
        PaymentStatus $status = PaymentStatus::Approved,
        string $id = 'pay-100',
        int $amount = 4990000,
        string $currency = 'COP',
    ): GatewayPayment {
        return new GatewayPayment(
            gateway: 'mercadopago',
            gatewayPaymentId: $id,
            status: $status,
            currency: $currency,
            amount: $amount,
            method: 'visa',
            paidAt: $this->now,
            raw: ['id' => $id],
        );
    }

    private function existingPayment(PaymentStatus $status, int $id = 77): Payment
    {
        return new Payment(
            id: $id,
            uuid: '66666666-6666-4666-8666-666666666666',
            orderId: 9,
            subscriptionId: null,
            customerId: 1,
            gateway: 'mercadopago',
            gatewayPaymentId: 'pay-100',
            status: $status,
            currency: 'COP',
            amount: 4990000,
            method: 'visa',
            paidAt: null,
            createdAt: $this->baseDate(),
            raw: null,
        );
    }

    public function testApprovedPaymentMarksOrderPaidAndFiresHook(): void
    {
        $order = $this->makeOrder(OrderStatus::Pending);

        $this->payments->shouldReceive('findByGatewayPaymentId')->with('mercadopago', 'pay-100')->andReturnNull();
        $this->payments->shouldReceive('insert')->once()->with(Mockery::on(
            fn (array $data): bool => $data['gateway_payment_id'] === 'pay-100'
                && $data['status'] === 'approved'
                && $data['order_id'] === 9
                && $data['amount'] === 4990000,
        ))->andReturn(1);

        $this->orders->shouldReceive('setGatewayPaymentId')->once()->with(9, 'pay-100', $this->now);
        $this->orders->shouldReceive('updateStatus')->once()->with(9, OrderStatus::Paid, $this->now, $this->now);

        Actions\expectDone('impay_order_paid')->once();

        $this->service->applyOrderPayment($order, $this->gatewayPayment());
    }

    public function testApprovedPaymentWithLowerAmountDoesNotMarkOrderPaid(): void
    {
        $order = $this->makeOrder(OrderStatus::Pending); // 4990000 COP

        $this->payments->shouldReceive('findByGatewayPaymentId')->andReturnNull();
        $this->payments->shouldReceive('insert')->once()->andReturn(1); // queda para auditoría
        $this->orders->shouldReceive('setGatewayPaymentId')->once();

        $this->orders->shouldNotReceive('updateStatus');

        $this->service->applyOrderPayment($order, $this->gatewayPayment(amount: 100000));

        $this->assertSame(0, did_action('impay_order_paid'));
        $this->assertSame(0, did_action('impay_payment_approved'));
    }

    public function testApprovedPaymentWithWrongCurrencyDoesNotMarkOrderPaid(): void
    {
        $order = $this->makeOrder(OrderStatus::Pending); // COP

        $this->payments->shouldReceive('findByGatewayPaymentId')->andReturnNull();
        $this->payments->shouldReceive('insert')->once()->andReturn(1);
        $this->orders->shouldReceive('setGatewayPaymentId')->once();

        $this->orders->shouldNotReceive('updateStatus');

        $this->service->applyOrderPayment($order, $this->gatewayPayment(currency: 'USD'));

        $this->assertSame(0, did_action('impay_order_paid'));
    }

    public function testDuplicatePaymentWithSameStatusIsIgnored(): void
    {
        $order = $this->makeOrder(OrderStatus::Paid);

        $this->payments->shouldReceive('findByGatewayPaymentId')
            ->andReturn($this->existingPayment(PaymentStatus::Approved));

        $this->payments->shouldNotReceive('insert');
        $this->orders->shouldNotReceive('updateStatus');

        $this->service->applyOrderPayment($order, $this->gatewayPayment());

        $this->assertSame(0, did_action('impay_order_paid'));
    }

    public function testPendingToApprovedUpdatesExistingPaymentAndPaysOrder(): void
    {
        $order = $this->makeOrder(OrderStatus::Pending);

        $this->payments->shouldReceive('findByGatewayPaymentId')
            ->andReturn($this->existingPayment(PaymentStatus::Pending));
        $this->payments->shouldReceive('updateStatus')->once()->with(77, PaymentStatus::Approved, $this->now);

        $this->orders->shouldReceive('setGatewayPaymentId')->once();
        $this->orders->shouldReceive('updateStatus')->once()->with(9, OrderStatus::Paid, $this->now, $this->now);

        Actions\expectDone('impay_order_paid')->once();

        $this->service->applyOrderPayment($order, $this->gatewayPayment());
    }

    public function testPaidOrderIsNeverDowngradedByLateWebhooks(): void
    {
        $order = $this->makeOrder(OrderStatus::Paid);

        $this->payments->shouldReceive('findByGatewayPaymentId')->andReturnNull();
        $this->payments->shouldReceive('insert')->once()->andReturn(1);
        $this->orders->shouldReceive('setGatewayPaymentId')->once();

        $this->orders->shouldNotReceive('updateStatus');

        $this->service->applyOrderPayment($order, $this->gatewayPayment(PaymentStatus::Rejected, 'pay-101'));
    }

    public function testFirstSubscriptionChargeSetsPeriodActivatesAndPaysInitialOrder(): void
    {
        $subscription = $this->makeSubscription(
            SubscriptionStatus::Pending,
            meta: ['initial_order_uuid' => '44444444-4444-4444-8444-444444444444'],
        );
        $initialOrder = $this->makeOrder(OrderStatus::Pending, subscriptionId: 5);

        $this->payments->shouldReceive('findByGatewayPaymentId')->andReturnNull();
        $this->payments->shouldReceive('insert')->once()->andReturn(1);
        $this->prices->shouldReceive('find')->with(3)->andReturn($this->makePrice());

        $expectedEnd = $this->now->add(new \DateInterval('P1M'));
        $this->subscriptions->shouldReceive('extendPeriod')
            ->once()
            ->with(5, $this->now, Mockery::on(
                fn (\DateTimeImmutable $end): bool => $end->format('Y-m-d') === $expectedEnd->format('Y-m-d'),
            ), $this->now);

        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Active, $this->now, null);

        Actions\expectDone('impay_subscription_active')->once();

        $this->orders->shouldReceive('findByUuid')->with('44444444-4444-4444-8444-444444444444')->andReturn($initialOrder);
        $this->orders->shouldReceive('setGatewayPaymentId')->once()->with(9, 'pay-100', $this->now);
        $this->orders->shouldReceive('updateStatus')->once()->with(9, OrderStatus::Paid, $this->now, $this->now);

        Actions\expectDone('impay_order_paid')->once();

        $this->service->applySubscriptionPayment($subscription, $this->gatewayPayment());
    }

    public function testRenewalExtendsFromCurrentPeriodEndWithoutRefiringHooks(): void
    {
        $periodEnd = $this->now->modify('+10 days');
        $subscription = $this->makeSubscription(SubscriptionStatus::Active, currentPeriodEnd: $periodEnd);

        $this->payments->shouldReceive('findByGatewayPaymentId')->andReturnNull();
        $this->payments->shouldReceive('insert')->once()->andReturn(1);
        $this->prices->shouldReceive('find')->andReturn($this->makePrice());

        $this->subscriptions->shouldReceive('extendPeriod')
            ->once()
            ->with(5, $periodEnd, Mockery::on(
                fn (\DateTimeImmutable $end): bool => $end == $periodEnd->add(new \DateInterval('P1M')),
            ), $this->now);

        // active → active es no-op: no persiste ni dispara hooks.
        $this->subscriptions->shouldNotReceive('updateStatus');

        $this->service->applySubscriptionPayment($subscription, $this->gatewayPayment());

        $this->assertSame(0, did_action('impay_subscription_active'));
    }

    public function testRejectedPaymentMovesActiveSubscriptionToPastDue(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active, currentPeriodEnd: $this->now->modify('+1 day'));

        $this->payments->shouldReceive('findByGatewayPaymentId')->andReturnNull();
        $this->payments->shouldReceive('insert')->once()->andReturn(1);

        $this->subscriptions->shouldReceive('incrementFailedPayments')->once()->with(5, $this->now)->andReturn(1);
        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::PastDue, $this->now, null);

        Actions\expectDone('impay_subscription_past_due')->once();

        $this->service->applySubscriptionPayment($subscription, $this->gatewayPayment(PaymentStatus::Rejected));
    }

    public function testThirdFailedPaymentCancelsPastDueSubscription(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::PastDue);

        $this->payments->shouldReceive('findByGatewayPaymentId')->andReturnNull();
        $this->payments->shouldReceive('insert')->once()->andReturn(1);

        $this->subscriptions->shouldReceive('incrementFailedPayments')->once()->andReturn(3);
        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Cancelled, $this->now, $this->now);

        Actions\expectDone('impay_subscription_cancelled')->once();

        $this->service->applySubscriptionPayment($subscription, $this->gatewayPayment(PaymentStatus::Rejected));
    }
}
