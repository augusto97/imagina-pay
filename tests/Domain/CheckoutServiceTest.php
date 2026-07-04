<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Domain;

use Brain\Monkey\Functions;
use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Product;
use ImaginaPay\Domain\Enums\PriceInterval;
use ImaginaPay\Domain\Enums\ProductStatus;
use ImaginaPay\Domain\Enums\ProductType;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\CheckoutService;
use ImaginaPay\Exceptions\NotFoundException;
use ImaginaPay\Exceptions\ValidationException;
use ImaginaPay\Gateways\CheckoutSession;
use ImaginaPay\Gateways\GatewayInterface;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Support\Uuid;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class CheckoutServiceTest extends TestCase
{
    use EntityFactory;

    private \DateTimeImmutable $now;

    /** @var ProductRepository&MockInterface */
    private ProductRepository $products;

    /** @var PriceRepository&MockInterface */
    private PriceRepository $prices;

    /** @var CustomerRepository&MockInterface */
    private CustomerRepository $customers;

    /** @var OrderRepository&MockInterface */
    private OrderRepository $orders;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $subscriptions;

    private GatewayRegistry $gateways;

    private CheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_json_encode')->alias('json_encode');

        $this->now = $this->baseDate();

        /** @var ProductRepository&MockInterface $products */
        $products = Mockery::mock(ProductRepository::class);
        $this->products = $products;

        /** @var PriceRepository&MockInterface $prices */
        $prices = Mockery::mock(PriceRepository::class);
        $this->prices = $prices;

        /** @var CustomerRepository&MockInterface $customers */
        $customers = Mockery::mock(CustomerRepository::class);
        $this->customers = $customers;

        /** @var OrderRepository&MockInterface $orders */
        $orders = Mockery::mock(OrderRepository::class);
        $this->orders = $orders;

        /** @var SubscriptionRepository&MockInterface $subscriptions */
        $subscriptions = Mockery::mock(SubscriptionRepository::class);
        $this->subscriptions = $subscriptions;

        $this->gateways = new GatewayRegistry();

        $this->service = new CheckoutService(
            $this->products,
            $this->prices,
            $this->customers,
            $this->orders,
            $this->subscriptions,
            $this->gateways,
            new FixedClock($this->now),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, bool> $features
     * @return GatewayInterface&MockInterface
     */
    private function registerGateway(array $features = []): GatewayInterface
    {
        $defaults = ['currency_COP' => true, 'one_time' => true, 'recurring' => true];
        $map = array_merge($defaults, $features);

        /** @var GatewayInterface&MockInterface $gateway */
        $gateway = Mockery::mock(GatewayInterface::class);
        $gateway->shouldReceive('id')->andReturn('mercadopago');
        $gateway->shouldReceive('supports')->andReturnUsing(
            static fn (string $feature): bool => $map[$feature] ?? false,
        );

        $this->gateways->register($gateway);

        return $gateway;
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return [
            'product' => '11111111-1111-4111-8111-111111111111',
            'price' => '22222222-2222-4222-8222-222222222222',
            'gateway' => 'mercadopago',
            'full_name' => 'Cliente de Prueba',
            'email' => 'cliente@example.com',
        ];
    }

    private function expectProductAndPrice(Product $product, Price $price): void
    {
        $this->products->shouldReceive('findByUuid')->andReturn($product);
        $this->prices->shouldReceive('findByUuid')->andReturn($price);
    }

    private function expectExistingCustomer(): void
    {
        $customer = $this->makeCustomer();
        $this->customers->shouldReceive('findByEmail')->with('cliente@example.com')->andReturn($customer);
        $this->customers->shouldReceive('update')->once();
        $this->customers->shouldReceive('find')->with(1)->andReturn($customer);
    }

    public function testOneTimeCheckoutCreatesOrderAndRedirects(): void
    {
        $this->expectProductAndPrice(
            $this->makeProduct(ProductType::OneTime),
            $this->makePrice(PriceInterval::OneTime),
        );
        $this->expectExistingCustomer();

        $gateway = $this->registerGateway();

        $this->orders->shouldReceive('insert')->once()->with(Mockery::on(
            static fn (array $data): bool => $data['kind'] === 'purchase'
                && $data['status'] === 'pending'
                && $data['amount'] === 4990000
                && $data['currency'] === 'COP'
                && Uuid::isValid((string) $data['uuid'])
                && $data['external_reference'] === $data['uuid'],
        ))->andReturn(9);

        $order = $this->makeOrder();
        $this->orders->shouldReceive('find')->with(9)->andReturn($order);

        $gateway->shouldReceive('createOneTimeCheckout')
            ->once()
            ->with($order)
            ->andReturn(new CheckoutSession('https://mp.test/init', 'pref-1'));

        $this->orders->shouldReceive('setGatewayRef')->once()->with(9, 'pref-1', $this->now);

        $result = $this->service->start($this->input());

        $this->assertSame('https://mp.test/init', $result['redirect_url']);
        $this->assertTrue(Uuid::isValid($result['order']));
    }

    public function testRecurringCheckoutCreatesSubscriptionLinkedToOrder(): void
    {
        $this->expectProductAndPrice(
            $this->makeProduct(ProductType::Subscription),
            $this->makePrice(PriceInterval::Month),
        );
        $this->expectExistingCustomer();

        $gateway = $this->registerGateway();

        $this->orders->shouldReceive('insert')->once()->with(Mockery::on(
            static fn (array $data): bool => $data['kind'] === 'subscription_initial',
        ))->andReturn(9);

        $this->subscriptions->shouldReceive('insert')->once()->with(Mockery::on(
            static function (array $data): bool {
                $meta = json_decode((string) $data['meta'], true);

                return $data['status'] === SubscriptionStatus::Pending->value
                    && is_array($meta)
                    && Uuid::isValid((string) ($meta['initial_order_uuid'] ?? ''));
            },
        ))->andReturn(5);

        $subscription = $this->makeSubscription(gatewaySubId: null);
        $this->subscriptions->shouldReceive('find')->with(5)->andReturn($subscription);

        $this->orders->shouldReceive('linkSubscription')->once()->with(9, 5, $this->now);

        $gateway->shouldReceive('createSubscription')
            ->once()
            ->with($subscription, Mockery::type(Price::class))
            ->andReturn(new CheckoutSession('https://mp.test/preapproval', 'preapproval-9'));

        $this->subscriptions->shouldReceive('setGatewaySubId')->once()->with(5, 'preapproval-9', $this->now);
        $this->orders->shouldReceive('setGatewayRef')->once()->with(9, 'preapproval-9', $this->now);

        $result = $this->service->start($this->input());

        $this->assertSame('https://mp.test/preapproval', $result['redirect_url']);
    }

    public function testAnnualHybridProductIsChargedAsOneTimePurchase(): void
    {
        $this->expectProductAndPrice(
            $this->makeProduct(ProductType::AnnualHybrid),
            $this->makePrice(PriceInterval::Year),
        );
        $this->expectExistingCustomer();

        $gateway = $this->registerGateway();

        // Nada de subscriptions->insert: la suscripción lógica se crea al pagar (Fase 3).
        $this->subscriptions->shouldNotReceive('insert');

        $this->orders->shouldReceive('insert')->once()->with(Mockery::on(
            static fn (array $data): bool => $data['kind'] === 'purchase',
        ))->andReturn(9);
        $this->orders->shouldReceive('find')->andReturn($this->makeOrder());

        $gateway->shouldReceive('createOneTimeCheckout')->once()->andReturn(new CheckoutSession('https://mp.test/init'));

        $result = $this->service->start($this->input());

        $this->assertSame('https://mp.test/init', $result['redirect_url']);
    }

    public function testRejectsGatewayThatDoesNotSupportTheCurrency(): void
    {
        $this->expectProductAndPrice(
            $this->makeProduct(),
            $this->makePrice(currency: 'USD'),
        );
        $this->registerGateway(); // solo currency_COP

        $this->expectException(ValidationException::class);
        $this->service->start($this->input());
    }

    public function testRejectsRecurringPriceOnGatewayWithoutRecurringSupport(): void
    {
        $this->expectProductAndPrice(
            $this->makeProduct(ProductType::Subscription),
            $this->makePrice(PriceInterval::Month),
        );
        $this->registerGateway(['recurring' => false]);

        $this->expectException(ValidationException::class);
        $this->service->start($this->input());
    }

    public function testRejectsInactiveProduct(): void
    {
        $this->products->shouldReceive('findByUuid')
            ->andReturn($this->makeProduct(status: ProductStatus::Draft));

        $this->expectException(NotFoundException::class);
        $this->service->start($this->input());
    }

    public function testRejectsPriceFromAnotherProduct(): void
    {
        $this->expectProductAndPrice(
            $this->makeProduct(),
            $this->makePrice(productId: 99),
        );

        $this->expectException(NotFoundException::class);
        $this->service->start($this->input());
    }

    public function testCustomFieldAnswersAreStoredInOrderMeta(): void
    {
        $this->expectProductAndPrice(
            $this->makeProduct(ProductType::OneTime, customFields: [
                ['key' => 'dominio', 'label' => 'Dominio de tu sitio', 'type' => 'text', 'required' => true],
                ['key' => 'notas', 'label' => 'Notas', 'type' => 'textarea', 'required' => false],
            ]),
            $this->makePrice(PriceInterval::OneTime),
        );
        $this->expectExistingCustomer();

        $gateway = $this->registerGateway();

        $this->orders->shouldReceive('insert')->once()->with(Mockery::on(
            static function (array $data): bool {
                $meta = json_decode((string) $data['meta'], true);

                return is_array($meta)
                    && ($meta['custom_fields'][0]['key'] ?? '') === 'dominio'
                    && ($meta['custom_fields'][0]['value'] ?? '') === 'misitio.com'
                    && count($meta['custom_fields']) === 1; // el campo opcional vacío no se guarda
            },
        ))->andReturn(9);
        $this->orders->shouldReceive('find')->andReturn($this->makeOrder());
        $this->orders->shouldReceive('setGatewayRef');
        $gateway->shouldReceive('createOneTimeCheckout')->andReturn(new CheckoutSession('https://mp.test/init'));

        $input = $this->input();
        $input['custom_fields'] = ['dominio' => ' misitio.com ', 'notas' => ''];

        $result = $this->service->start($input);

        $this->assertArrayHasKey('redirect_url', $result);
    }

    public function testMissingRequiredCustomFieldIsRejected(): void
    {
        $this->expectProductAndPrice(
            $this->makeProduct(ProductType::OneTime, customFields: [
                ['key' => 'dominio', 'label' => 'Dominio', 'type' => 'text', 'required' => true],
            ]),
            $this->makePrice(PriceInterval::OneTime),
        );
        $this->registerGateway();

        $this->orders->shouldNotReceive('insert');

        $this->expectException(ValidationException::class);
        $this->service->start($this->input());
    }

    public function testSelectCustomFieldRejectsUnknownOption(): void
    {
        $this->expectProductAndPrice(
            $this->makeProduct(ProductType::OneTime, customFields: [
                ['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => true, 'options' => ['Básico', 'Premium']],
            ]),
            $this->makePrice(PriceInterval::OneTime),
        );
        $this->registerGateway();

        $input = $this->input();
        $input['custom_fields'] = ['plan' => 'Inexistente'];

        $this->expectException(ValidationException::class);
        $this->service->start($input);
    }

    public function testCreatesCustomerWhenEmailIsNew(): void
    {
        $this->expectProductAndPrice(
            $this->makeProduct(ProductType::OneTime),
            $this->makePrice(PriceInterval::OneTime),
        );
        $gateway = $this->registerGateway();

        $this->customers->shouldReceive('findByEmail')->andReturnNull();
        $this->customers->shouldReceive('insert')->once()->with(Mockery::on(
            static fn (array $data): bool => $data['email'] === 'cliente@example.com'
                && $data['full_name'] === 'Cliente de Prueba'
                && $data['country'] === 'CO'
                && Uuid::isValid((string) $data['uuid']),
        ))->andReturn(1);
        $this->customers->shouldReceive('find')->with(1)->andReturn($this->makeCustomer());

        $this->orders->shouldReceive('insert')->andReturn(9);
        $this->orders->shouldReceive('find')->andReturn($this->makeOrder());
        $gateway->shouldReceive('createOneTimeCheckout')->andReturn(new CheckoutSession('https://mp.test/init'));

        $result = $this->service->start($this->input());

        $this->assertArrayHasKey('redirect_url', $result);
    }
}
