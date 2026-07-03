<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Gateways;

use Brain\Monkey\Functions;
use ImaginaPay\Domain\Enums\PriceInterval;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Gateways\GatewayMode;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoClient;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoGateway;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoWebhookHandler;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoWebhookVerifier;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class MercadoPagoGatewayTest extends TestCase
{
    use EntityFactory;

    /** @var MercadoPagoClient&MockInterface */
    private MercadoPagoClient $client;

    /** @var ProductRepository&MockInterface */
    private ProductRepository $products;

    /** @var CustomerRepository&MockInterface */
    private CustomerRepository $customers;

    /** @var PaymentLinkRepository&MockInterface */
    private PaymentLinkRepository $paymentLinks;

    private MercadoPagoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('rest_url')->alias(static fn (string $path): string => 'https://site.test/wp-json/' . $path);
        Functions\when('get_option')->justReturn(7);
        Functions\when('get_permalink')->justReturn('https://site.test/gracias/');
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://site.test' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (string $key, string $value, string $url): string => $url . '?' . $key . '=' . $value,
        );

        /** @var MercadoPagoClient&MockInterface $client */
        $client = Mockery::mock(MercadoPagoClient::class);
        $this->client = $client;

        /** @var MercadoPagoWebhookHandler&MockInterface $handler */
        $handler = Mockery::mock(MercadoPagoWebhookHandler::class);

        /** @var ProductRepository&MockInterface $products */
        $products = Mockery::mock(ProductRepository::class);
        $this->products = $products;

        /** @var CustomerRepository&MockInterface $customers */
        $customers = Mockery::mock(CustomerRepository::class);
        $this->customers = $customers;

        /** @var PaymentLinkRepository&MockInterface $paymentLinks */
        $paymentLinks = Mockery::mock(PaymentLinkRepository::class);
        $this->paymentLinks = $paymentLinks;

        $clock = new FixedClock($this->baseDate());

        $this->gateway = new MercadoPagoGateway(
            $this->client,
            new MercadoPagoWebhookVerifier($clock),
            $handler,
            $this->products,
            $this->customers,
            $this->paymentLinks,
            $clock,
            new NullLogger(),
        );
    }

    public function testIdentityAndMode(): void
    {
        $this->assertSame('mercadopago', $this->gateway->id());
        $this->assertSame(GatewayMode::HostedSubscription, $this->gateway->mode());
    }

    public function testSupportsMatrix(): void
    {
        $this->assertTrue($this->gateway->supports('recurring'));
        $this->assertTrue($this->gateway->supports('pse'));
        $this->assertTrue($this->gateway->supports('pause'));
        $this->assertTrue($this->gateway->supports('currency_COP'));
        $this->assertFalse($this->gateway->supports('currency_USD'));
        $this->assertFalse($this->gateway->supports('trial'));
        $this->assertFalse($this->gateway->supports('nequi_recurring'));
    }

    public function testCreateOneTimeCheckoutBuildsPreferenceAndReturnsInitPoint(): void
    {
        $order = $this->makeOrder();

        $this->products->shouldReceive('find')->with(2)->andReturn($this->makeProduct());
        $this->customers->shouldReceive('find')->with(1)->andReturn($this->makeCustomer());
        $this->client->shouldReceive('isSandbox')->andReturn(false);

        $this->client->shouldReceive('post')
            ->once()
            ->with('/checkout/preferences', Mockery::on(static function (array $payload) use ($order): bool {
                $item = $payload['items'][0];

                return $payload['external_reference'] === $order->externalReference
                    && $payload['statement_descriptor'] === 'IMAGINAWP'
                    && $payload['auto_return'] === 'approved'
                    && $payload['notification_url'] === 'https://site.test/wp-json/impay/v1/webhooks/mercadopago'
                    && str_contains((string) $payload['back_urls']['success'], $order->uuid)
                    && $item['unit_price'] === 49900.0
                    && $item['currency_id'] === 'COP'
                    && $item['quantity'] === 1;
            }), Mockery::type('string'))
            ->andReturn(['id' => 'pref-1', 'init_point' => 'https://mp.test/init']);

        $session = $this->gateway->createOneTimeCheckout($order);

        $this->assertSame('https://mp.test/init', $session->redirectUrl);
        $this->assertSame('pref-1', $session->gatewayRef);
    }

    public function testSandboxPrefersSandboxInitPoint(): void
    {
        $this->products->shouldReceive('find')->andReturn($this->makeProduct());
        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());
        $this->client->shouldReceive('isSandbox')->andReturn(true);

        $this->client->shouldReceive('post')->andReturn([
            'id' => 'pref-1',
            'init_point' => 'https://mp.test/live',
            'sandbox_init_point' => 'https://mp.test/sandbox',
        ]);

        $session = $this->gateway->createOneTimeCheckout($this->makeOrder());

        $this->assertSame('https://mp.test/sandbox', $session->redirectUrl);
    }

    public function testCreateSubscriptionBuildsMonthlyPreapproval(): void
    {
        $subscription = $this->makeSubscription(
            meta: ['initial_order_uuid' => '44444444-4444-4444-8444-444444444444'],
            gatewaySubId: null,
        );

        $this->products->shouldReceive('find')->andReturn($this->makeProduct());
        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());
        $this->client->shouldReceive('isSandbox')->andReturn(false);

        $this->client->shouldReceive('post')
            ->once()
            ->with('/preapproval', Mockery::on(static function (array $payload) use ($subscription): bool {
                $recurring = $payload['auto_recurring'];

                return $payload['external_reference'] === $subscription->uuid
                    && $payload['payer_email'] === 'cliente@example.com'
                    && $payload['reason'] === 'VPS Mensual'
                    && $recurring['frequency'] === 1
                    && $recurring['frequency_type'] === 'months'
                    && $recurring['transaction_amount'] === 49900.0
                    && $recurring['currency_id'] === 'COP'
                    && str_contains((string) $payload['back_url'], '44444444-4444-4444-8444-444444444444');
            }), Mockery::type('string'))
            ->andReturn(['id' => 'preapproval-9', 'init_point' => 'https://mp.test/preapproval']);

        $session = $this->gateway->createSubscription($subscription, $this->makePrice());

        $this->assertSame('https://mp.test/preapproval', $session->redirectUrl);
        $this->assertSame('preapproval-9', $session->gatewayRef);
    }

    public function testAnnualPriceUsesFrequency12Months(): void
    {
        $this->products->shouldReceive('find')->andReturn($this->makeProduct());
        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());
        $this->client->shouldReceive('isSandbox')->andReturn(false);

        $this->client->shouldReceive('post')
            ->once()
            ->with('/preapproval', Mockery::on(static function (array $payload): bool {
                return $payload['auto_recurring']['frequency'] === 12
                    && $payload['auto_recurring']['frequency_type'] === 'months';
            }), Mockery::type('string'))
            ->andReturn(['init_point' => 'https://mp.test/preapproval']);

        $this->gateway->createSubscription(
            $this->makeSubscription(gatewaySubId: null),
            $this->makePrice(PriceInterval::Year),
        );
    }

    public function testCancelPauseResumeUpdatePreapprovalStatus(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active);

        $this->client->shouldReceive('put')->once()->with('/preapproval/preapproval-1', ['status' => 'cancelled'])->andReturn([]);
        $this->client->shouldReceive('put')->once()->with('/preapproval/preapproval-1', ['status' => 'paused'])->andReturn([]);
        $this->client->shouldReceive('put')->once()->with('/preapproval/preapproval-1', ['status' => 'authorized'])->andReturn([]);

        $this->gateway->cancelSubscription($subscription);
        $this->gateway->pauseSubscription($subscription);
        $this->gateway->resumeSubscription($subscription);
    }

    public function testCancelWithoutGatewaySubIdFails(): void
    {
        $this->expectException(GatewayException::class);
        $this->gateway->cancelSubscription($this->makeSubscription(gatewaySubId: null));
    }

    public function testMissingInitPointFails(): void
    {
        $this->products->shouldReceive('find')->andReturn($this->makeProduct());
        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());
        $this->client->shouldReceive('isSandbox')->andReturn(false);
        $this->client->shouldReceive('post')->andReturn(['id' => 'pref-1']); // sin init_point

        $this->expectException(GatewayException::class);
        $this->gateway->createOneTimeCheckout($this->makeOrder());
    }
}
