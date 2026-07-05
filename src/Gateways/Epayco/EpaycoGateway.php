<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\Epayco;

use ImaginaPay\Core\Settings;
use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\PaymentLink;
use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Services\PaymentService;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Gateways\CheckoutSession;
use ImaginaPay\Gateways\GatewayInterface;
use ImaginaPay\Gateways\GatewayMode;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Gateways\PaymentLinkRequest;
use ImaginaPay\Gateways\WebhookEvent;
use ImaginaPay\Http\HttpClient;
use ImaginaPay\Support\Logger;

/**
 * ePayco — SOLO pago único (decisión de negocio: su producto de
 * suscripciones tiene costo adicional no rentable; ver CLAUDE.md §7).
 *
 * Flujo: el checkout devuelve los datos del widget Onpage (checkout.js
 * con la llave pública) en lugar de una URL de redirección. ePayco
 * notifica a la URL de confirmación (form-encoded, firmada con SHA-256);
 * antes de procesar SIEMPRE se re-consulta el estado real en su API de
 * validación (fetch-before-trust, misma política que Mercado Pago).
 */
final class EpaycoGateway implements GatewayInterface
{
    private const VALIDATION_URL = 'https://secure.epayco.co/validation/v1/reference/';
    private const ONLY_ONE_TIME = 'ePayco está habilitado solo para pagos únicos (las suscripciones no son rentables con esta pasarela).';

    public function __construct(
        private readonly HttpClient $http,
        private readonly Settings $settings,
        private readonly EpaycoWebhookVerifier $verifier,
        private readonly ProductRepository $products,
        private readonly CustomerRepository $customers,
        private readonly OrderRepository $orders,
        private readonly PaymentService $payments,
        private readonly Logger $logger,
    ) {
    }

    public function id(): string
    {
        return 'epayco';
    }

    public function mode(): GatewayMode
    {
        return GatewayMode::HostedSubscription;
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'one_time', 'pse', 'nequi', 'currency_COP' => true,
            default => false, // recurring, payment_links, pause, currency_USD...
        };
    }

    public function isConfigured(): bool
    {
        return $this->custId() !== '' && $this->pKey() !== '' && $this->publicKey() !== '';
    }

    public function createOneTimeCheckout(Order $order): CheckoutSession
    {
        if (!$this->isConfigured()) {
            throw new GatewayException('ePayco no está configurado (P_CUST_ID, PUBLIC_KEY y P_KEY en Ajustes).');
        }

        $product = $this->products->find($order->productId);
        $customer = $this->customers->find($order->customerId);
        $name = $product?->name ?? 'Compra';

        $data = [
            'name' => $name,
            'description' => $name,
            'invoice' => $order->uuid,
            'currency' => strtolower($order->currency),
            'amount' => number_format($order->amount / 100, 2, '.', ''),
            'tax_base' => '0',
            'tax' => '0',
            'country' => 'co',
            'lang' => 'es',
            'external' => 'false',
            'extra1' => $order->externalReference,
            'confirmation' => rest_url('impay/v1/webhooks/epayco'),
            'response' => $this->thanksUrl($order->uuid),
            'email_billing' => $customer?->email ?? '',
            'name_billing' => $customer?->fullName ?? '',
            'mobilephone_billing' => $customer?->phone ?? '',
        ];

        // El widget corre en el navegador: no hay gateway_ref hasta que
        // ePayco confirme (llega como x_ref_payco en el webhook).
        return new CheckoutSession('', null, [
            'provider' => 'epayco',
            'key' => $this->publicKey(),
            'test' => $this->isTest(),
            'data' => $data,
        ]);
    }

    public function createSubscription(Subscription $subscription, Price $price): CheckoutSession
    {
        throw new GatewayException(self::ONLY_ONE_TIME);
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        throw new GatewayException(self::ONLY_ONE_TIME);
    }

    public function pauseSubscription(Subscription $subscription): void
    {
        throw new GatewayException(self::ONLY_ONE_TIME);
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        throw new GatewayException(self::ONLY_ONE_TIME);
    }

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLink
    {
        throw new GatewayException('ePayco no soporta links de pago en esta versión.');
    }

    public function verifyWebhook(\WP_REST_Request $request): WebhookEvent
    {
        // La confirmación de ePayco llega form-encoded (no JSON).
        $params = $request->get_body_params();

        if ($params === []) {
            $json = $request->get_json_params();
            $params = is_array($json) ? $json : [];
        }

        /** @var array<string, mixed> $params */
        $this->verifier->verify($params, $this->custId(), $this->pKey());

        $refPayco = is_scalar($params['x_ref_payco'] ?? null) ? (string) $params['x_ref_payco'] : '';
        $codResponse = is_scalar($params['x_cod_response'] ?? null) ? (string) $params['x_cod_response'] : '';
        $transactionId = is_scalar($params['x_transaction_id'] ?? null) ? (string) $params['x_transaction_id'] : '';

        // Un mismo ref_payco puede notificarse varias veces al cambiar de
        // estado (pendiente → aprobada): el event_id incluye el estado.
        $eventId = hash('sha256', sprintf('epayco|%s|%s|%s', $refPayco, $codResponse, $transactionId));

        return new WebhookEvent('epayco', $eventId, 'payment', ['ref_payco' => $refPayco]);
    }

    public function handleWebhook(WebhookEvent $event): void
    {
        $refPayco = is_string($event->payload['ref_payco'] ?? null) ? $event->payload['ref_payco'] : '';

        if ($refPayco === '') {
            $this->logger->warning('webhooks', 'Evento de ePayco sin ref_payco: omitido.');

            return;
        }

        // Nunca confiar en el payload entrante: consultar el estado real.
        $data = $this->fetchPayment($refPayco);

        $externalReference = is_string($data['x_extra1'] ?? null) && $data['x_extra1'] !== ''
            ? $data['x_extra1']
            : (is_string($data['x_id_invoice'] ?? null) ? $data['x_id_invoice'] : '');

        $order = $externalReference !== '' ? $this->orders->findByExternalReference($externalReference) : null;

        if ($order === null) {
            $this->logger->warning('webhooks', sprintf(
                'Transacción %s de ePayco sin order local (extra1/invoice: "%s").',
                $refPayco,
                $externalReference,
            ));

            return;
        }

        $this->payments->applyOrderPayment($order, $this->toGatewayPayment($refPayco, $data));
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchSubscription(string $gatewaySubId): array
    {
        throw new GatewayException(self::ONLY_ONE_TIME);
    }

    /**
     * Estado real de la transacción en la API de validación de ePayco.
     *
     * @return array<string, mixed>
     */
    public function fetchPayment(string $gatewayPaymentId): array
    {
        $response = $this->http->get(self::VALIDATION_URL . rawurlencode($gatewayPaymentId));

        if (!$response->ok()) {
            throw new GatewayException(sprintf(
                'ePayco respondió %d al consultar la transacción %s.',
                $response->status,
                $gatewayPaymentId,
            ));
        }

        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        if ($data === []) {
            throw new GatewayException(sprintf('ePayco no devolvió datos para la transacción %s.', $gatewayPaymentId));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data Respuesta de la API de validación.
     */
    private function toGatewayPayment(string $refPayco, array $data): GatewayPayment
    {
        $amountMajor = $data['x_amount'] ?? 0;
        $amountMajor = is_numeric($amountMajor) ? (float) $amountMajor : 0.0;

        $paidAt = null;

        if (is_string($data['x_transaction_date'] ?? null) && $data['x_transaction_date'] !== '') {
            try {
                // ePayco reporta en hora de Colombia.
                $paidAt = new \DateTimeImmutable($data['x_transaction_date'], new \DateTimeZone('America/Bogota'));
            } catch (\Exception) {
                $paidAt = null;
            }
        }

        $currency = is_string($data['x_currency_code'] ?? null) && $data['x_currency_code'] !== ''
            ? strtoupper($data['x_currency_code'])
            : 'COP';

        $method = is_string($data['x_franchise'] ?? null) ? $data['x_franchise'] : null;

        return new GatewayPayment(
            gateway: 'epayco',
            gatewayPaymentId: $refPayco,
            status: $this->mapStatus((string) ($data['x_cod_response'] ?? '')),
            currency: $currency,
            amount: (int) round($amountMajor * 100),
            method: $method,
            paidAt: $paidAt,
            raw: $data,
        );
    }

    /**
     * x_cod_response: 1 Aceptada, 2 Rechazada, 3 Pendiente, 4 Fallida,
     * 6 Reversada, 9 Expirada, 10/11 Abandonada/Cancelada.
     */
    private function mapStatus(string $codResponse): PaymentStatus
    {
        return match ($codResponse) {
            '1' => PaymentStatus::Approved,
            '2', '4', '9', '10', '11' => PaymentStatus::Rejected,
            '6' => PaymentStatus::Refunded,
            default => PaymentStatus::Pending, // 3 y desconocidos
        };
    }

    private function custId(): string
    {
        $value = $this->settings->get('epayco_cust_id', '');

        return is_string($value) ? trim($value) : '';
    }

    private function publicKey(): string
    {
        $value = $this->settings->get('epayco_public_key', '');

        return is_string($value) ? trim($value) : '';
    }

    private function pKey(): string
    {
        $value = $this->settings->get('epayco_p_key', '');

        return is_string($value) ? trim($value) : '';
    }

    private function isTest(): bool
    {
        return (bool) $this->settings->get('epayco_test', true);
    }

    private function thanksUrl(string $orderUuid): string
    {
        $pageId = (int) get_option('impay_page_gracias', 0);
        $url = $pageId > 0 ? get_permalink($pageId) : false;
        $url = is_string($url) ? $url : home_url('/gracias-compra/');

        return add_query_arg('order', $orderUuid, $url);
    }
}
