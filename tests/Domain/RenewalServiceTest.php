<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Domain;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use ImaginaPay\Core\Container;
use ImaginaPay\Domain\Entities\PaymentLink;
use ImaginaPay\Domain\Enums\OrderKind;
use ImaginaPay\Domain\Enums\OrderStatus;
use ImaginaPay\Domain\Enums\PaymentLinkStatus;
use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Domain\Enums\PriceInterval;
use ImaginaPay\Domain\Enums\ProductType;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\PaymentService;
use ImaginaPay\Domain\Services\RenewalService;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Gateways\GatewayInterface;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Gateways\PaymentLinkRequest;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class RenewalServiceTest extends TestCase
{
    use EntityFactory;

    private \DateTimeImmutable $now;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $subscriptions;

    /** @var OrderRepository&MockInterface */
    private OrderRepository $orders;

    /** @var PaymentLinkRepository&MockInterface */
    private PaymentLinkRepository $paymentLinks;

    /** @var PriceRepository&MockInterface */
    private PriceRepository $prices;

    /** @var ProductRepository&MockInterface */
    private ProductRepository $products;

    /** @var CustomerRepository&MockInterface */
    private CustomerRepository $customers;

    /** @var PaymentService&MockInterface */
    private PaymentService $payments;

    private GatewayRegistry $gateways;

    private RenewalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_json_encode')->alias('json_encode');

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

        /** @var PriceRepository&MockInterface $prices */
        $prices = Mockery::mock(PriceRepository::class);
        $this->prices = $prices;

        /** @var ProductRepository&MockInterface $products */
        $products = Mockery::mock(ProductRepository::class);
        $this->products = $products;

        /** @var CustomerRepository&MockInterface $customers */
        $customers = Mockery::mock(CustomerRepository::class);
        $this->customers = $customers;

        /** @var PaymentService&MockInterface $payments */
        $payments = Mockery::mock(PaymentService::class);
        $this->payments = $payments;

        $this->gateways = new GatewayRegistry();

        $container = new Container();
        $container->instance(GatewayRegistry::class, $this->gateways);

        $this->service = new RenewalService(
            $container,
            $this->subscriptions,
            $this->orders,
            $this->paymentLinks,
            $this->prices,
            $this->products,
            $this->customers,
            $this->payments,
            new SubscriptionStateMachine($this->subscriptions, new NullLogger(), new FixedClock($this->now)),
            new FixedClock($this->now),
            new NullLogger(),
        );
    }

    private function makeLink(PaymentLinkStatus $status = PaymentLinkStatus::Open, ?int $subscriptionId = 5): PaymentLink
    {
        return new PaymentLink(
            id: 4,
            uuid: '77777777-7777-4777-8777-777777777777',
            customerId: 1,
            subscriptionId: $subscriptionId,
            priceId: 3,
            gateway: 'mercadopago',
            gatewayRef: 'pref-9',
            url: 'https://mp.test/link',
            status: $status,
            expiresAt: null,
            paidOrderId: null,
            createdAt: $this->baseDate(),
        );
    }

    private function approvedPayment(): GatewayPayment
    {
        return new GatewayPayment(
            gateway: 'mercadopago',
            gatewayPaymentId: 'pay-500',
            status: PaymentStatus::Approved,
            currency: 'COP',
            amount: 4990000,
            method: 'pse',
            paidAt: $this->now,
            raw: [],
        );
    }

    public function testPaidAnnualHybridOrderCreatesActiveLogicalSubscription(): void
    {
        $order = $this->makeOrder(OrderStatus::Paid);

        $this->orders->shouldReceive('find')->with(9)->andReturn($order);
        $this->products->shouldReceive('find')->with(2)->andReturn($this->makeProduct(ProductType::AnnualHybrid));
        $this->prices->shouldReceive('find')->with(3)->andReturn($this->makePrice(PriceInterval::Year));

        $this->subscriptions->shouldReceive('insert')->once()->with(Mockery::on(
            static function (array $data): bool {
                $meta = json_decode((string) $data['meta'], true);

                return $data['gateway_sub_id'] === null
                    && $data['status'] === 'pending'
                    && is_array($meta)
                    && $meta['initial_order_uuid'] === '44444444-4444-4444-8444-444444444444';
            },
        ))->andReturn(5);

        $expectedEnd = $this->now->add(new \DateInterval('P1Y'));
        $this->subscriptions->shouldReceive('extendPeriod')
            ->once()
            ->with(5, $this->now, Mockery::on(
                fn (\DateTimeImmutable $end): bool => $end->format('Y-m-d') === $expectedEnd->format('Y-m-d'),
            ), $this->now);

        $this->orders->shouldReceive('linkSubscription')->once()->with(9, 5, $this->now);

        $this->subscriptions->shouldReceive('find')->with(5)
            ->andReturn($this->makeSubscription(SubscriptionStatus::Pending, gatewaySubId: null));
        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Active, $this->now, null);

        Actions\expectDone('impay_subscription_active')->once();

        $this->service->handleOrderPaid($order);
    }

    public function testNonAnnualProductsAreIgnored(): void
    {
        $order = $this->makeOrder(OrderStatus::Paid);

        $this->orders->shouldReceive('find')->andReturn($order);
        $this->products->shouldReceive('find')->andReturn($this->makeProduct(ProductType::OneTime));

        $this->subscriptions->shouldNotReceive('insert');

        $this->service->handleOrderPaid($order);
    }

    public function testOrderAlreadyLinkedIsIgnored(): void
    {
        $order = $this->makeOrder(OrderStatus::Paid);
        $linked = $this->makeOrder(OrderStatus::Paid, subscriptionId: 5);

        $this->orders->shouldReceive('find')->andReturn($linked);
        $this->subscriptions->shouldNotReceive('insert');

        $this->service->handleOrderPaid($order);
    }

    public function testRenewalOrdersDoNotCreateSubscriptions(): void
    {
        $order = $this->makeOrder(OrderStatus::Paid, OrderKind::Renewal);

        $this->orders->shouldNotReceive('find');
        $this->subscriptions->shouldNotReceive('insert');

        $this->service->handleOrderPaid($order);
    }

    public function testApplyPaidLinkExtendsSubscriptionAndMarksLinkPaid(): void
    {
        $periodEnd = $this->now->modify('+10 days');
        $subscription = $this->makeSubscription(SubscriptionStatus::Active, currentPeriodEnd: $periodEnd, gatewaySubId: null);
        $link = $this->makeLink();

        $this->subscriptions->shouldReceive('find')->with(5)->andReturn($subscription);
        $this->prices->shouldReceive('find')->with(3)->andReturn($this->makePrice(PriceInterval::Year));

        $this->orders->shouldReceive('insert')->once()->with(Mockery::on(
            static fn (array $data): bool => $data['kind'] === 'renewal'
                && $data['subscription_id'] === 5
                && $data['amount'] === 4990000,
        ))->andReturn(30);

        $renewalOrder = $this->makeOrder(OrderStatus::Pending, OrderKind::Renewal, id: 30, subscriptionId: 5);
        $this->orders->shouldReceive('find')->with(30)->andReturn($renewalOrder);

        $this->payments->shouldReceive('applyOrderPayment')->once()->with($renewalOrder, Mockery::type(GatewayPayment::class));

        $this->paymentLinks->shouldReceive('updateStatus')->once()->with(4, PaymentLinkStatus::Paid, 30);

        $expectedEnd = $periodEnd->add(new \DateInterval('P1Y'));
        $this->subscriptions->shouldReceive('extendPeriod')
            ->once()
            ->with(5, $periodEnd, Mockery::on(
                fn (\DateTimeImmutable $end): bool => $end == $expectedEnd,
            ), $this->now);

        Actions\expectDone('impay_renewal_paid')->once();

        $this->service->applyPaidLink($link, $this->approvedPayment());
    }

    public function testExpiredSubscriptionReactivatesFromToday(): void
    {
        // Venció hace 3 días: el nuevo periodo corre desde hoy.
        $subscription = $this->makeSubscription(
            SubscriptionStatus::Expired,
            currentPeriodEnd: $this->now->modify('-3 days'),
            gatewaySubId: null,
        );
        $link = $this->makeLink();

        $this->subscriptions->shouldReceive('find')->andReturn($subscription);
        $this->prices->shouldReceive('find')->andReturn($this->makePrice(PriceInterval::Year));
        $this->orders->shouldReceive('insert')->andReturn(30);
        $this->orders->shouldReceive('find')->andReturn($this->makeOrder(OrderStatus::Pending, OrderKind::Renewal, id: 30));
        $this->payments->shouldReceive('applyOrderPayment')->once();
        $this->paymentLinks->shouldReceive('updateStatus')->once();

        $this->subscriptions->shouldReceive('extendPeriod')
            ->once()
            ->with(5, $this->now, Mockery::on(
                fn (\DateTimeImmutable $end): bool => $end == $this->now->add(new \DateInterval('P1Y')),
            ), $this->now);

        // expired → active vuelve a disparar la provisión.
        $this->subscriptions->shouldReceive('updateStatus')
            ->once()
            ->with(5, SubscriptionStatus::Active, $this->now, null);

        Actions\expectDone('impay_subscription_active')->once();
        Actions\expectDone('impay_renewal_paid')->once();

        $this->service->applyPaidLink($link, $this->approvedPayment());
    }

    public function testAlreadyPaidLinkIsIdempotentNoOp(): void
    {
        $this->orders->shouldNotReceive('insert');
        $this->paymentLinks->shouldNotReceive('updateStatus');

        $this->service->applyPaidLink($this->makeLink(PaymentLinkStatus::Paid), $this->approvedPayment());
    }

    public function testSendRemindersCreatesLinkAndFiresHookAtMark(): void
    {
        // Vence en 15 días exactos.
        $subscription = $this->makeSubscription(
            SubscriptionStatus::Active,
            currentPeriodEnd: $this->now->modify('+15 days'),
            gatewaySubId: null,
        );

        $this->subscriptions->shouldReceive('findLogicalExpiring')->andReturn([$subscription]);
        $this->paymentLinks->shouldReceive('findOpenBySubscription')->with(5)->andReturnNull();

        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());
        $this->prices->shouldReceive('find')->andReturn($this->makePrice(PriceInterval::Year));
        $this->products->shouldReceive('find')->andReturn($this->makeProduct(ProductType::AnnualHybrid));

        $link = $this->makeLink();

        /** @var GatewayInterface&MockInterface $gateway */
        $gateway = Mockery::mock(GatewayInterface::class);
        $gateway->shouldReceive('id')->andReturn('mercadopago');
        $gateway->shouldReceive('createPaymentLink')
            ->once()
            ->with(Mockery::on(static fn (PaymentLinkRequest $request): bool => $request->subscriptionId === 5
                && $request->amount->amount === 4990000
                && str_contains($request->description, 'Renovación')))
            ->andReturn($link);
        $this->gateways->register($gateway);

        $this->subscriptions->shouldReceive('updateMeta')
            ->once()
            ->with(5, Mockery::on(static function (array $meta): bool {
                $key = array_key_first($meta['renewal_notices']);

                return $meta['renewal_notices'][$key] === [15];
            }), $this->now);

        Actions\expectDone('impay_renewal_reminder')
            ->once()
            ->whenHappen(function ($sub, $sentLink, int $mark) use ($link): void {
                $this->assertSame($link, $sentLink);
                $this->assertSame(15, $mark);
            });

        $this->service->sendReminders();
    }

    public function testReminderIsNotRepeatedForTheSameMark(): void
    {
        $periodEnd = $this->now->modify('+15 days');
        $subscription = $this->makeSubscription(
            SubscriptionStatus::Active,
            currentPeriodEnd: $periodEnd,
            meta: ['renewal_notices' => [$periodEnd->format('Y-m-d') => [30, 15]]],
            gatewaySubId: null,
        );

        $this->subscriptions->shouldReceive('findLogicalExpiring')->andReturn([$subscription]);
        $this->paymentLinks->shouldNotReceive('findOpenBySubscription');

        $this->service->sendReminders();

        $this->assertSame(0, did_action('impay_renewal_reminder'));
    }

    public function testNoReminderOutsideMarks(): void
    {
        // 25 días: entre 30 y 15, con la marca 30 ya notificada.
        $periodEnd = $this->now->modify('+25 days');
        $subscription = $this->makeSubscription(
            SubscriptionStatus::Active,
            currentPeriodEnd: $periodEnd,
            meta: ['renewal_notices' => [$periodEnd->format('Y-m-d') => [30]]],
            gatewaySubId: null,
        );

        $this->subscriptions->shouldReceive('findLogicalExpiring')->andReturn([$subscription]);

        $this->service->sendReminders();

        $this->assertSame(0, did_action('impay_renewal_reminder'));
    }
}
