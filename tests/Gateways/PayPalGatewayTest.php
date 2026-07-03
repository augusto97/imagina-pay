<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Gateways;

use Brain\Monkey\Functions;
use ImaginaPay\Domain\Enums\PriceInterval;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Gateways\GatewayMode;
use ImaginaPay\Gateways\PayPal\PayPalClient;
use ImaginaPay\Gateways\PayPal\PayPalGateway;
use ImaginaPay\Gateways\PayPal\PayPalWebhookHandler;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class PayPalGatewayTest extends TestCase
{
    use EntityFactory;

    /** @var PayPalClient&MockInterface */
    private PayPalClient $client;

    /** @var PriceRepository&MockInterface */
    private PriceRepository $prices;

    /** @var ProductRepository&MockInterface */
    private ProductRepository $products;

    /** @var CustomerRepository&MockInterface */
    private CustomerRepository $customers;

    private PayPalGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->justReturn(7);
        Functions\when('get_permalink')->justReturn('https://site.test/gracias/');
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://site.test' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (string $key, string $value, string $url): string => $url . '?' . $key . '=' . $value,
        );

        /** @var PayPalClient&MockInterface $client */
        $client = Mockery::mock(PayPalClient::class);
        $this->client = $client;

        /** @var PayPalWebhookHandler&MockInterface $handler */
        $handler = Mockery::mock(PayPalWebhookHandler::class);

        /** @var ProductRepository&MockInterface $products */
        $products = Mockery::mock(ProductRepository::class);
        $this->products = $products;

        /** @var CustomerRepository&MockInterface $customers */
        $customers = Mockery::mock(CustomerRepository::class);
        $this->customers = $customers;

        /** @var PriceRepository&MockInterface $prices */
        $prices = Mockery::mock(PriceRepository::class);
        $this->prices = $prices;

        /** @var PaymentLinkRepository&MockInterface $paymentLinks */
        $paymentLinks = Mockery::mock(PaymentLinkRepository::class);

        $this->gateway = new PayPalGateway(
            $this->client,
            $handler,
            $this->products,
            $this->customers,
            $this->prices,
            $paymentLinks,
            new FixedClock($this->baseDate()),
            new NullLogger(),
        );
    }

    public function testIdentityModeAndSupports(): void
    {
        $this->assertSame('paypal', $this->gateway->id());
        $this->assertSame(GatewayMode::HostedSubscription, $this->gateway->mode());
        $this->assertTrue($this->gateway->supports('currency_USD'));
        $this->assertTrue($this->gateway->supports('recurring'));
        $this->assertFalse($this->gateway->supports('currency_COP'));
        $this->assertFalse($this->gateway->supports('pause'));
        $this->assertFalse($this->gateway->supports('pse'));
    }

    public function testCreateOneTimeCheckoutBuildsCaptureOrder(): void
    {
        $order = $this->makeOrder();

        $this->products->shouldReceive('find')->andReturn($this->makeProduct());

        $this->client->shouldReceive('post')
            ->once()
            ->with('/v2/checkout/orders', Mockery::on(static function (array $payload) use ($order): bool {
                $unit = $payload['purchase_units'][0];

                return $payload['intent'] === 'CAPTURE'
                    && $unit['custom_id'] === $order->externalReference
                    && $unit['amount']['value'] === '49900.00'
                    && $unit['amount']['currency_code'] === 'COP'
                    && str_contains((string) $payload['application_context']['return_url'], $order->uuid);
            }), Mockery::type('string'))
            ->andReturn([
                'id' => 'PP-ORDER-1',
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api.paypal.test/self'],
                    ['rel' => 'approve', 'href' => 'https://paypal.test/approve'],
                ],
            ]);

        $session = $this->gateway->createOneTimeCheckout($order);

        $this->assertSame('https://paypal.test/approve', $session->redirectUrl);
        $this->assertSame('PP-ORDER-1', $session->gatewayRef);
    }

    public function testCreateSubscriptionCreatesPlanLazilyAndPersistsRefs(): void
    {
        $subscription = $this->makeSubscription(gatewaySubId: null);
        $price = $this->makePrice(PriceInterval::Month, 'USD', 1299);

        $this->products->shouldReceive('find')->andReturn($this->makeProduct());
        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());

        $this->client->shouldReceive('post')
            ->once()
            ->with('/v1/catalogs/products', Mockery::type('array'), Mockery::type('string'))
            ->andReturn(['id' => 'PROD-1']);

        $this->client->shouldReceive('post')
            ->once()
            ->with('/v1/billing/plans', Mockery::on(static function (array $payload): bool {
                $cycle = $payload['billing_cycles'][0];

                return $payload['product_id'] === 'PROD-1'
                    && $cycle['frequency']['interval_unit'] === 'MONTH'
                    && $cycle['total_cycles'] === 0
                    && $cycle['pricing_scheme']['fixed_price']['value'] === '12.99'
                    && $cycle['pricing_scheme']['fixed_price']['currency_code'] === 'USD';
            }), Mockery::type('string'))
            ->andReturn(['id' => 'P-PLAN-1']);

        $this->prices->shouldReceive('updateGatewayRefs')
            ->once()
            ->with(3, Mockery::on(static fn (array $refs): bool => $refs['paypal_plan_id'] === 'P-PLAN-1'
                && $refs['paypal_product_id'] === 'PROD-1'), Mockery::type(\DateTimeImmutable::class));

        $this->client->shouldReceive('post')
            ->once()
            ->with('/v1/billing/subscriptions', Mockery::on(static function (array $payload) use ($subscription): bool {
                return $payload['plan_id'] === 'P-PLAN-1'
                    && $payload['custom_id'] === $subscription->uuid
                    && $payload['subscriber']['email_address'] === 'cliente@example.com';
            }), Mockery::type('string'))
            ->andReturn([
                'id' => 'I-SUB-1',
                'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/subscribe']],
            ]);

        $session = $this->gateway->createSubscription($subscription, $price);

        $this->assertSame('https://paypal.test/subscribe', $session->redirectUrl);
        $this->assertSame('I-SUB-1', $session->gatewayRef);
    }

    public function testCreateSubscriptionReusesExistingPlan(): void
    {
        $subscription = $this->makeSubscription(gatewaySubId: null);
        $price = new \ImaginaPay\Domain\Entities\Price(
            id: 3,
            uuid: '22222222-2222-4222-8222-222222222222',
            productId: 2,
            currency: 'USD',
            amount: 1299,
            interval: PriceInterval::Month,
            trialDays: 0,
            gatewayRefs: ['paypal_plan_id' => 'P-EXISTENTE', 'paypal_product_id' => 'PROD-1'],
            status: \ImaginaPay\Domain\Enums\PriceStatus::Active,
            createdAt: $this->baseDate(),
            updatedAt: $this->baseDate(),
        );

        $this->products->shouldReceive('find')->andReturn($this->makeProduct());
        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());

        // Sin llamadas a catálogo ni planes: directo a la suscripción.
        $this->client->shouldReceive('post')
            ->once()
            ->with('/v1/billing/subscriptions', Mockery::on(
                static fn (array $payload): bool => $payload['plan_id'] === 'P-EXISTENTE',
            ), Mockery::type('string'))
            ->andReturn(['id' => 'I-SUB-2', 'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/ok']]]);

        $this->prices->shouldNotReceive('updateGatewayRefs');

        $session = $this->gateway->createSubscription($subscription, $price);

        $this->assertSame('I-SUB-2', $session->gatewayRef);
    }

    public function testCancelSubscriptionPostsCancel(): void
    {
        $this->client->shouldReceive('post')
            ->once()
            ->with('/v1/billing/subscriptions/preapproval-1/cancel', Mockery::type('array'))
            ->andReturn([]);

        $this->gateway->cancelSubscription($this->makeSubscription());
    }

    public function testPauseIsNotSupported(): void
    {
        $this->expectException(GatewayException::class);
        $this->gateway->pauseSubscription($this->makeSubscription());
    }

    public function testMissingApproveLinkFails(): void
    {
        $this->products->shouldReceive('find')->andReturn($this->makeProduct());
        $this->client->shouldReceive('post')->andReturn(['id' => 'X', 'links' => []]);

        $this->expectException(GatewayException::class);
        $this->gateway->createOneTimeCheckout($this->makeOrder());
    }
}
