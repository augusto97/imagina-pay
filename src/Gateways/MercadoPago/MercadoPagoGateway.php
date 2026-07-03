<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\MercadoPago;

use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\PaymentLink;
use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\PaymentLinkStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Gateways\CheckoutSession;
use ImaginaPay\Gateways\GatewayInterface;
use ImaginaPay\Gateways\GatewayMode;
use ImaginaPay\Gateways\PaymentLinkRequest;
use ImaginaPay\Gateways\WebhookEvent;
use ImaginaPay\Http\IdempotencyKey;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;
use ImaginaPay\Support\Uuid;

/**
 * Mercado Pago (cuenta Colombia, cobros en COP).
 * Pago único: Checkout Pro (preferences). Suscripción: preapproval sin
 * plan (solo tarjeta). El motor de cobro recurrente vive en MP.
 */
final class MercadoPagoGateway implements GatewayInterface
{
    private const STATEMENT_DESCRIPTOR = 'IMAGINAWP';

    public function __construct(
        private readonly MercadoPagoClient $client,
        private readonly MercadoPagoWebhookVerifier $verifier,
        private readonly MercadoPagoWebhookHandler $webhookHandler,
        private readonly ProductRepository $products,
        private readonly CustomerRepository $customers,
        private readonly PaymentLinkRepository $paymentLinks,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function id(): string
    {
        return 'mercadopago';
    }

    public function mode(): GatewayMode
    {
        return GatewayMode::HostedSubscription;
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'one_time', 'recurring', 'pause', 'resume', 'pse', 'nequi', 'currency_COP' => true,
            default => false, // trial, nequi_recurring, currency_USD...
        };
    }

    public function createOneTimeCheckout(Order $order): CheckoutSession
    {
        $product = $this->products->find($order->productId);
        $customer = $this->customers->find($order->customerId);

        if ($product === null || $customer === null) {
            throw new GatewayException('El pedido no tiene producto o cliente válido.');
        }

        $thanksUrl = $this->thanksUrl($order->uuid);

        $payload = [
            'items' => [[
                'id' => $product->slug,
                'title' => $product->name,
                'quantity' => 1,
                'currency_id' => $order->currency,
                // Conversión a unidades mayores solo en el borde con la API.
                'unit_price' => round($order->amount / 100, 2),
            ]],
            'payer' => [
                'name' => $customer->fullName,
                'email' => $customer->email,
            ],
            'external_reference' => $order->externalReference,
            'back_urls' => [
                'success' => $thanksUrl,
                'pending' => $thanksUrl,
                'failure' => $thanksUrl,
            ],
            'auto_return' => 'approved',
            'notification_url' => rest_url('impay/v1/webhooks/mercadopago'),
            'statement_descriptor' => self::STATEMENT_DESCRIPTOR,
        ];

        $response = $this->client->post(
            '/checkout/preferences',
            $payload,
            IdempotencyKey::derive('mp-preference', $order->uuid),
        );

        return new CheckoutSession($this->initPoint($response), $this->stringOrNull($response['id'] ?? null));
    }

    public function createSubscription(Subscription $subscription, Price $price): CheckoutSession
    {
        $product = $this->products->find($subscription->productId);
        $customer = $this->customers->find($subscription->customerId);

        if ($product === null || $customer === null) {
            throw new GatewayException('La suscripción no tiene producto o cliente válido.');
        }

        $initialOrderUuid = $subscription->meta['initial_order_uuid'] ?? null;
        $backUrl = $this->thanksUrl(is_string($initialOrderUuid) ? $initialOrderUuid : null);

        $payload = [
            'reason' => $product->name,
            'external_reference' => $subscription->uuid,
            'payer_email' => $customer->email,
            'auto_recurring' => [
                'frequency' => $price->interval->value === 'year' ? 12 : 1,
                'frequency_type' => 'months',
                'transaction_amount' => round($price->amount / 100, 2),
                'currency_id' => $price->currency,
            ],
            'back_url' => $backUrl,
            'status' => 'pending',
        ];

        $response = $this->client->post(
            '/preapproval',
            $payload,
            IdempotencyKey::derive('mp-preapproval', $subscription->uuid),
        );

        return new CheckoutSession($this->initPoint($response), $this->stringOrNull($response['id'] ?? null));
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        $this->updatePreapprovalStatus($subscription, 'cancelled');
    }

    public function pauseSubscription(Subscription $subscription): void
    {
        $this->updatePreapprovalStatus($subscription, 'paused');
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        $this->updatePreapprovalStatus($subscription, 'authorized');
    }

    public function verifyWebhook(\WP_REST_Request $request): WebhookEvent
    {
        $signature = $request->get_header('x-signature');
        $requestId = $request->get_header('x-request-id');
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];

        $dataId = $request->get_param('data.id');

        if (!is_string($dataId) || $dataId === '') {
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            $dataId = isset($data['id']) ? (string) $data['id'] : '';
        }

        $topic = $request->get_param('type') ?? $request->get_param('topic') ?? ($body['type'] ?? '');
        $topic = is_string($topic) ? $topic : '';

        $this->verifier->verify(
            is_string($signature) ? $signature : '',
            is_string($requestId) ? $requestId : '',
            $dataId,
            $this->client->webhookSecret(),
        );

        $eventId = is_string($requestId) && $requestId !== ''
            ? $requestId
            : hash('sha256', $topic . '|' . $dataId . '|' . (is_string($signature) ? $signature : ''));

        /** @var array<string, mixed> $body */
        return new WebhookEvent('mercadopago', $eventId, $topic, [
            'data_id' => $dataId,
            'type' => $topic,
            'body' => $body,
        ]);
    }

    public function handleWebhook(WebhookEvent $event): void
    {
        $this->webhookHandler->handle($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchSubscription(string $gatewaySubId): array
    {
        return $this->client->get('/preapproval/' . $gatewaySubId);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchPayment(string $gatewayPaymentId): array
    {
        return $this->client->get('/v1/payments/' . $gatewayPaymentId);
    }

    /**
     * Link de pago vía Checkout Pro (renovaciones anuales y cobros manuales).
     */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLink
    {
        $linkUuid = Uuid::v4();
        $thanksUrl = $this->thanksUrl(null);

        $payload = [
            'items' => [[
                'id' => 'impay-link',
                'title' => $request->description,
                'quantity' => 1,
                'currency_id' => $request->amount->currency,
                'unit_price' => round($request->amount->amount / 100, 2),
            ]],
            'payer' => [
                'name' => $request->customer->fullName,
                'email' => $request->customer->email,
            ],
            'external_reference' => $linkUuid,
            'back_urls' => [
                'success' => $thanksUrl,
                'pending' => $thanksUrl,
                'failure' => $thanksUrl,
            ],
            'notification_url' => rest_url('impay/v1/webhooks/mercadopago'),
            'statement_descriptor' => self::STATEMENT_DESCRIPTOR,
        ];

        if ($request->expiresAt !== null) {
            $payload['expires'] = true;
            $payload['expiration_date_to'] = $request->expiresAt->format(\DateTimeInterface::ATOM);
        }

        $response = $this->client->post(
            '/checkout/preferences',
            $payload,
            IdempotencyKey::derive('mp-link', $linkUuid),
        );

        $url = $this->initPoint($response);
        $now = $this->clock->now();

        $linkId = $this->paymentLinks->insert([
            'uuid' => $linkUuid,
            'customer_id' => $request->customer->id,
            'subscription_id' => $request->subscriptionId,
            'price_id' => $request->priceId ?? 0,
            'gateway' => $this->id(),
            'gateway_ref' => $this->stringOrNull($response['id'] ?? null),
            'url' => $url,
            'status' => PaymentLinkStatus::Open->value,
            'expires_at' => $request->expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $link = $this->paymentLinks->find($linkId);

        if ($link === null) {
            throw new GatewayException('No fue posible registrar el link de pago.');
        }

        return $link;
    }

    private function updatePreapprovalStatus(Subscription $subscription, string $status): void
    {
        if ($subscription->gatewaySubId === null) {
            throw new GatewayException('La suscripción no tiene preapproval asociado en Mercado Pago.');
        }

        $this->client->put('/preapproval/' . $subscription->gatewaySubId, ['status' => $status]);

        $this->logger->info('mercadopago', sprintf(
            'Preapproval %s actualizado a "%s".',
            $subscription->gatewaySubId,
            $status,
        ));
    }

    /**
     * URL de /gracias con el uuid del order para el polling de estado.
     */
    private function thanksUrl(?string $orderUuid): string
    {
        $pageId = (int) get_option('impay_page_gracias', 0);
        $url = $pageId > 0 ? get_permalink($pageId) : false;
        $url = is_string($url) ? $url : home_url('/gracias-compra/');

        return $orderUuid === null ? $url : add_query_arg('order', $orderUuid, $url);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function initPoint(array $response): string
    {
        $initPoint = $this->client->isSandbox() && is_string($response['sandbox_init_point'] ?? null)
            ? $response['sandbox_init_point']
            : ($response['init_point'] ?? null);

        if (!is_string($initPoint) || $initPoint === '') {
            throw new GatewayException('Mercado Pago no devolvió una URL de checkout.');
        }

        return $initPoint;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }
}
