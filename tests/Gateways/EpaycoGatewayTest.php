<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Gateways;

use Brain\Monkey\Functions;
use ImaginaPay\Core\Settings;
use ImaginaPay\Support\Crypto;
use ImaginaPay\Domain\Enums\OrderStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Services\PaymentService;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Gateways\Epayco\EpaycoGateway;
use ImaginaPay\Gateways\Epayco\EpaycoWebhookVerifier;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Gateways\WebhookEvent;
use ImaginaPay\Http\HttpClient;
use ImaginaPay\Http\HttpResponse;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class EpaycoGatewayTest extends TestCase
{
    use EntityFactory;

    private const CUST_ID = '901234';
    private const P_KEY = 'p-key-secreta';

    /** @var HttpClient&MockInterface */
    private HttpClient $http;

    private Crypto $crypto;

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var OrderRepository&MockInterface */
    private OrderRepository $orders;

    /** @var PaymentService&MockInterface */
    private PaymentService $payments;

    private EpaycoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://sitio.test/wp-json/' . $path);
        Functions\when('get_option')->alias(fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default);
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://sitio.test' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (string $key, string $value, string $url): string => $url . '?' . $key . '=' . $value,
        );

        /** @var HttpClient&MockInterface $http */
        $http = Mockery::mock(HttpClient::class);
        $this->http = $http;

        $this->crypto = Crypto::fromAuthKey('clave-de-tests-para-epayco');

        /** @var ProductRepository&MockInterface $products */
        $products = Mockery::mock(ProductRepository::class);
        $products->shouldReceive('find')->andReturn($this->makeProduct())->byDefault();

        /** @var CustomerRepository&MockInterface $customers */
        $customers = Mockery::mock(CustomerRepository::class);
        $customers->shouldReceive('find')->andReturn($this->makeCustomer())->byDefault();

        /** @var OrderRepository&MockInterface $orders */
        $orders = Mockery::mock(OrderRepository::class);
        $this->orders = $orders;

        /** @var PaymentService&MockInterface $payments */
        $payments = Mockery::mock(PaymentService::class);
        $this->payments = $payments;

        $this->gateway = new EpaycoGateway(
            $this->http,
            new Settings($this->crypto),
            new EpaycoWebhookVerifier(),
            $products,
            $customers,
            $this->orders,
            $this->payments,
            new NullLogger(),
        );
    }

    private function configure(string $custId = self::CUST_ID, string $pKey = self::P_KEY): void
    {
        $this->options['impay_settings'] = [
            'epayco_cust_id' => $custId,
            'epayco_public_key' => 'pub-key',
            'epayco_p_key' => $pKey !== '' ? $this->crypto->encrypt($pKey) : '',
            'epayco_test' => true,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function confirmation(string $ref = 'ref-123', string $cod = '1', ?string $signature = null): array
    {
        $params = [
            'x_ref_payco' => $ref,
            'x_transaction_id' => 'txn-9',
            'x_amount' => '49900.00',
            'x_currency_code' => 'COP',
            'x_cod_response' => $cod,
        ];

        $params['x_signature'] = $signature ?? hash('sha256', sprintf(
            '%s^%s^%s^%s^%s^%s',
            self::CUST_ID,
            self::P_KEY,
            $params['x_ref_payco'],
            $params['x_transaction_id'],
            $params['x_amount'],
            $params['x_currency_code'],
        ));

        return $params;
    }

    private function request(array $params): \WP_REST_Request
    {
        /** @var \WP_REST_Request&MockInterface $request */
        $request = Mockery::mock(\WP_REST_Request::class);
        $request->shouldReceive('get_body_params')->andReturn($params);
        $request->shouldReceive('get_json_params')->andReturn(null);

        return $request;
    }

    public function testVerifyWebhookAcceptsValidSignature(): void
    {
        $this->configure();

        $event = $this->gateway->verifyWebhook($this->request($this->confirmation()));

        $this->assertSame('epayco', $event->gateway);
        $this->assertSame('payment', $event->topic);
        $this->assertSame('ref-123', $event->payload['ref_payco']);
    }

    public function testVerifyWebhookRejectsInvalidSignature(): void
    {
        $this->configure();

        $this->expectException(GatewayException::class);
        $this->gateway->verifyWebhook($this->request($this->confirmation(signature: str_repeat('0', 64))));
    }

    public function testVerifyWebhookRejectsWhenNotConfigured(): void
    {
        $this->configure(custId: '', pKey: '');

        $this->expectException(GatewayException::class);
        $this->gateway->verifyWebhook($this->request($this->confirmation()));
    }

    public function testEventIdChangesWithTransactionState(): void
    {
        $this->configure();

        $pending = $this->gateway->verifyWebhook($this->request($this->confirmation(cod: '3')));
        $approved = $this->gateway->verifyWebhook($this->request($this->confirmation(cod: '1')));

        $this->assertNotSame($pending->eventId, $approved->eventId);
    }

    public function testHandleWebhookFetchesRealStateAndAppliesApprovedPayment(): void
    {
        $this->configure();

        // fetch-before-trust: el estado sale de la API de validación.
        $this->http->shouldReceive('get')
            ->once()
            ->with('https://secure.epayco.co/validation/v1/reference/ref-123')
            ->andReturn(new HttpResponse(200, (string) json_encode(['success' => true, 'data' => [
                'x_ref_payco' => 'ref-123',
                'x_cod_response' => 1,
                'x_amount' => '49900.00',
                'x_currency_code' => 'COP',
                'x_extra1' => '44444444-4444-4444-8444-444444444444',
                'x_franchise' => 'PSE',
                'x_transaction_date' => '2026-07-05 10:00:00',
            ]])));

        $order = $this->makeOrder(OrderStatus::Pending);
        $this->orders->shouldReceive('findByExternalReference')
            ->with('44444444-4444-4444-8444-444444444444')
            ->andReturn($order);

        $this->payments->shouldReceive('applyOrderPayment')->once()->with($order, Mockery::on(
            static fn (GatewayPayment $payment): bool => $payment->gateway === 'epayco'
                && $payment->gatewayPaymentId === 'ref-123'
                && $payment->status->value === 'approved'
                && $payment->amount === 4990000
                && $payment->currency === 'COP'
                && $payment->method === 'PSE',
        ));

        $this->gateway->handleWebhook(new WebhookEvent('epayco', 'evt-1', 'payment', ['ref_payco' => 'ref-123']));
    }

    public function testHandleWebhookMapsRejectedAndRefundedStates(): void
    {
        $this->configure();

        foreach (['2' => 'rejected', '6' => 'refunded', '3' => 'pending'] as $cod => $expected) {
            $this->http->shouldReceive('get')->once()->andReturn(new HttpResponse(200, (string) json_encode([
                'data' => [
                    'x_cod_response' => $cod,
                    'x_amount' => '10.00',
                    'x_currency_code' => 'COP',
                    'x_extra1' => '44444444-4444-4444-8444-444444444444',
                ],
            ])));

            $this->orders->shouldReceive('findByExternalReference')->andReturn($this->makeOrder());
            $this->payments->shouldReceive('applyOrderPayment')->once()->with(Mockery::any(), Mockery::on(
                static fn (GatewayPayment $payment): bool => $payment->status->value === $expected,
            ));

            $this->gateway->handleWebhook(new WebhookEvent('epayco', 'evt-' . $cod, 'payment', ['ref_payco' => 'r']));
        }
    }

    public function testCreateOneTimeCheckoutReturnsWidgetData(): void
    {
        $this->configure();

        $session = $this->gateway->createOneTimeCheckout($this->makeOrder());

        $this->assertNotNull($session->widget);
        $this->assertSame('epayco', $session->widget['provider']);
        $this->assertSame('pub-key', $session->widget['key']);
        $this->assertTrue($session->widget['test']);
        $this->assertSame('49900.00', $session->widget['data']['amount']);
        $this->assertSame('44444444-4444-4444-8444-444444444444', $session->widget['data']['extra1']);
        $this->assertSame('https://sitio.test/wp-json/impay/v1/webhooks/epayco', $session->widget['data']['confirmation']);
    }

    public function testSubscriptionsAreNotSupported(): void
    {
        $this->assertFalse($this->gateway->supports('recurring'));
        $this->assertFalse($this->gateway->supports('payment_links'));
        $this->assertTrue($this->gateway->supports('one_time'));
        $this->assertTrue($this->gateway->supports('currency_COP'));

        $this->expectException(GatewayException::class);
        $this->gateway->createSubscription($this->makeSubscription(), $this->makePrice());
    }
}
