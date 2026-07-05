<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Gateways;

use Brain\Monkey\Functions;
use ImaginaPay\Domain\Enums\OrderStatus;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\PaymentSourceRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\PaymentService;
use ImaginaPay\Domain\Services\RenewalService;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Gateways\WebhookEvent;
use ImaginaPay\Gateways\Wompi\WompiClient;
use ImaginaPay\Gateways\Wompi\WompiGateway;
use ImaginaPay\Gateways\Wompi\WompiWebhookVerifier;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class WompiGatewayTest extends TestCase
{
    use EntityFactory;

    /** @var WompiClient&MockInterface */
    private WompiClient $client;

    /** @var OrderRepository&MockInterface */
    private OrderRepository $orders;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $subscriptions;

    /** @var PaymentSourceRepository&MockInterface */
    private PaymentSourceRepository $paymentSources;

    /** @var PaymentLinkRepository&MockInterface */
    private PaymentLinkRepository $paymentLinks;

    /** @var PaymentService&MockInterface */
    private PaymentService $payments;

    private WompiGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->justReturn(0);
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://sitio.test' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (string $key, string $value, string $url): string => $url . '?' . $key . '=' . $value,
        );

        /** @var WompiClient&MockInterface $client */
        $client = Mockery::mock(WompiClient::class);
        $client->shouldReceive('publicKey')->andReturn('pub_test_abc')->byDefault();
        $client->shouldReceive('integritySecret')->andReturn('int-secret')->byDefault();
        $client->shouldReceive('eventsSecret')->andReturn('ev-secret')->byDefault();
        $this->client = $client;

        /** @var CustomerRepository&MockInterface $customers */
        $customers = Mockery::mock(CustomerRepository::class);
        $customers->shouldReceive('find')->andReturn($this->makeCustomer())->byDefault();

        /** @var OrderRepository&MockInterface $orders */
        $orders = Mockery::mock(OrderRepository::class);
        $this->orders = $orders;

        /** @var SubscriptionRepository&MockInterface $subscriptions */
        $subscriptions = Mockery::mock(SubscriptionRepository::class);
        $this->subscriptions = $subscriptions;

        /** @var PaymentSourceRepository&MockInterface $paymentSources */
        $paymentSources = Mockery::mock(PaymentSourceRepository::class);
        $this->paymentSources = $paymentSources;

        /** @var PaymentLinkRepository&MockInterface $paymentLinks */
        $paymentLinks = Mockery::mock(PaymentLinkRepository::class);
        $this->paymentLinks = $paymentLinks;

        /** @var PaymentService&MockInterface $payments */
        $payments = Mockery::mock(PaymentService::class);
        $this->payments = $payments;

        /** @var RenewalService&MockInterface $renewals */
        $renewals = Mockery::mock(RenewalService::class);

        $this->gateway = new WompiGateway(
            $this->client,
            new WompiWebhookVerifier(),
            $customers,
            $this->orders,
            $this->subscriptions,
            $this->paymentSources,
            $this->paymentLinks,
            $this->payments,
            $renewals,
            new FixedClock($this->baseDate()),
            new NullLogger(),
        );
    }

    public function testOneTimeCheckoutBuildsSignedWebCheckoutUrl(): void
    {
        $order = $this->makeOrder(); // 4990000 COP, external_reference 4444...

        $session = $this->gateway->createOneTimeCheckout($order);

        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $session->redirectUrl);

        parse_str((string) parse_url($session->redirectUrl, PHP_URL_QUERY), $params);

        $this->assertSame('pub_test_abc', $params['public-key']);
        $this->assertSame('COP', $params['currency']);
        $this->assertSame('4990000', $params['amount-in-cents']);
        $this->assertSame($order->externalReference, $params['reference']);
        $this->assertSame(
            hash('sha256', $order->externalReference . '4990000' . 'COP' . 'int-secret'),
            $params['signature:integrity'],
        );
    }

    public function testCreateSubscriptionRequiresPendingToken(): void
    {
        $subscription = $this->makeSubscription(meta: ['initial_order_uuid' => 'x']);

        $this->expectException(GatewayException::class);
        $this->gateway->createSubscription($subscription, $this->makePrice());
    }

    public function testCreateSubscriptionTokenizesAndFiresFirstCharge(): void
    {
        $subscription = $this->makeSubscription(meta: [
            'initial_order_uuid' => '44444444-4444-4444-8444-444444444444',
            'pending_token' => 'tok_test_9',
            'pending_token_type' => 'CARD',
        ]);

        $this->client->shouldReceive('acceptanceToken')->once()->andReturn('acc-token');
        $this->client->shouldReceive('createPaymentSource')
            ->once()
            ->with('CARD', 'tok_test_9', 'cliente@example.com', 'acc-token')
            ->andReturn(['id' => 881, 'status' => 'AVAILABLE', 'public_data' => ['brand' => 'VISA', 'last_four' => '4242']]);

        $this->paymentSources->shouldReceive('insert')->once()->with(Mockery::on(
            static fn (array $data): bool => $data['gateway'] === 'wompi'
                && $data['gateway_source_id'] === '881'
                && $data['type'] === 'CARD'
                && $data['last_four'] === '4242',
        ))->andReturn(1);

        // El token de un solo uso desaparece del meta; queda la fuente.
        $this->subscriptions->shouldReceive('updateMeta')->once()->with(5, Mockery::on(
            static fn (array $meta): bool => !isset($meta['pending_token'])
                && $meta['payment_source_id'] === '881'
                && $meta['payment_method']['last_four'] === '4242',
        ), Mockery::type(\DateTimeImmutable::class));

        $this->client->shouldReceive('createTransaction')->once()->with(Mockery::on(
            static fn (array $payload): bool => $payload['amount_in_cents'] === 4990000
                && $payload['currency'] === 'COP'
                && $payload['reference'] === '55555555-5555-4555-8555-555555555555'
                && $payload['payment_source_id'] === 881,
        ))->andReturn(['id' => 'txn-1', 'status' => 'PENDING']);

        $session = $this->gateway->createSubscription($subscription, $this->makePrice());

        // Redirige a /gracias con el order inicial para el polling.
        $this->assertStringContainsString('44444444-4444-4444-8444-444444444444', $session->redirectUrl);
        $this->assertNull($session->gatewayRef);
    }

    public function testHandleWebhookRoutesRenewalReferenceToSubscription(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active);

        $this->subscriptions->shouldReceive('findByUuid')
            ->with('55555555-5555-4555-8555-555555555555')
            ->andReturn($subscription);

        $this->payments->shouldReceive('applySubscriptionPayment')->once()->with($subscription, Mockery::on(
            static fn (GatewayPayment $payment): bool => $payment->gateway === 'wompi'
                && $payment->status->value === 'approved'
                && $payment->amount === 4990000,
        ));

        $this->gateway->handleWebhook(new WebhookEvent('wompi', 'evt-1', 'transaction.updated', ['transaction' => [
            'id' => 'txn-9',
            'status' => 'APPROVED',
            'reference' => 'impay-ren-55555555-5555-4555-8555-555555555555-20260801-a1',
            'amount_in_cents' => 4990000,
            'currency' => 'COP',
            'payment_method_type' => 'NEQUI',
        ]]));
    }

    public function testHandleWebhookRoutesOrderReference(): void
    {
        $order = $this->makeOrder(OrderStatus::Pending);
        $this->orders->shouldReceive('findByExternalReference')
            ->with('44444444-4444-4444-8444-444444444444')
            ->andReturn($order);

        $this->payments->shouldReceive('applyOrderPayment')->once()->with($order, Mockery::on(
            static fn (GatewayPayment $payment): bool => $payment->status->value === 'rejected',
        ));

        $this->gateway->handleWebhook(new WebhookEvent('wompi', 'evt-2', 'transaction.updated', ['transaction' => [
            'id' => 'txn-10',
            'status' => 'DECLINED',
            'reference' => '44444444-4444-4444-8444-444444444444',
            'amount_in_cents' => 4990000,
            'currency' => 'COP',
        ]]));
    }

    public function testSupportsRecurringWithNequiAndPaymentLinks(): void
    {
        $this->assertTrue($this->gateway->supports('recurring'));
        $this->assertTrue($this->gateway->supports('nequi_recurring'));
        $this->assertTrue($this->gateway->supports('payment_links'));
        $this->assertTrue($this->gateway->supports('currency_COP'));
        $this->assertFalse($this->gateway->supports('currency_USD'));
        $this->assertFalse($this->gateway->supports('pause'));
    }
}
