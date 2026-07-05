<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\PayPal;

use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\PaymentLink;
use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\PaymentLinkStatus;
use ImaginaPay\Domain\Enums\PriceInterval;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\PriceRepository;
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
 * PayPal (clientes internacionales, USD). Pago único: Orders v2 con
 * intent CAPTURE. Suscripción: Catalog Products + Billing Plans creados
 * perezosamente al primer uso, luego Billing Subscriptions.
 */
final class PayPalGateway implements GatewayInterface
{
    private const BRAND_NAME = 'Imagina WP';

    public function __construct(
        private readonly PayPalClient $client,
        private readonly PayPalWebhookHandler $webhookHandler,
        private readonly ProductRepository $products,
        private readonly CustomerRepository $customers,
        private readonly PriceRepository $prices,
        private readonly PaymentLinkRepository $paymentLinks,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function id(): string
    {
        return 'paypal';
    }

    public function mode(): GatewayMode
    {
        return GatewayMode::HostedSubscription;
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'one_time', 'recurring', 'currency_USD', 'payment_links' => true,
            // pause/resume solo se exponen para Mercado Pago (spec sección 7).
            default => false,
        };
    }

    public function createOneTimeCheckout(Order $order): CheckoutSession
    {
        $product = $this->products->find($order->productId);

        if ($product === null) {
            throw new GatewayException('El pedido no tiene producto válido.');
        }

        $thanksUrl = $this->thanksUrl($order->uuid);

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $order->uuid,
                'custom_id' => $order->externalReference,
                'invoice_id' => 'impay-' . $order->uuid,
                'description' => $product->name,
                'amount' => [
                    'currency_code' => $order->currency,
                    'value' => self::toApiAmount($order->amount),
                ],
            ]],
            'application_context' => [
                'brand_name' => self::BRAND_NAME,
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $thanksUrl,
                'cancel_url' => $thanksUrl,
            ],
        ];

        $response = $this->client->post(
            '/v2/checkout/orders',
            $payload,
            IdempotencyKey::derive('pp-order', $order->uuid),
        );

        return new CheckoutSession(
            $this->approveLink($response),
            is_string($response['id'] ?? null) ? $response['id'] : null,
        );
    }

    public function createSubscription(Subscription $subscription, Price $price): CheckoutSession
    {
        $product = $this->products->find($subscription->productId);
        $customer = $this->customers->find($subscription->customerId);

        if ($product === null || $customer === null) {
            throw new GatewayException('La suscripción no tiene producto o cliente válido.');
        }

        $planId = $this->ensureBillingPlan($price, $product->name);

        $initialOrderUuid = $subscription->meta['initial_order_uuid'] ?? null;
        $thanksUrl = $this->thanksUrl(is_string($initialOrderUuid) ? $initialOrderUuid : null);

        $payload = [
            'plan_id' => $planId,
            'custom_id' => $subscription->uuid,
            'subscriber' => [
                'email_address' => $customer->email,
            ],
            'application_context' => [
                'brand_name' => self::BRAND_NAME,
                'user_action' => 'SUBSCRIBE_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $thanksUrl,
                'cancel_url' => $thanksUrl,
            ],
        ];

        $response = $this->client->post(
            '/v1/billing/subscriptions',
            $payload,
            IdempotencyKey::derive('pp-subscription', $subscription->uuid),
        );

        return new CheckoutSession(
            $this->approveLink($response),
            is_string($response['id'] ?? null) ? $response['id'] : null,
        );
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        if ($subscription->gatewaySubId === null) {
            throw new GatewayException('La suscripción no tiene subscription id asociado en PayPal.');
        }

        $this->client->post(
            '/v1/billing/subscriptions/' . $subscription->gatewaySubId . '/cancel',
            ['reason' => 'Cancelada por el cliente o el administrador.'],
        );

        $this->logger->info('paypal', sprintf('Suscripción %s cancelada en PayPal.', $subscription->gatewaySubId));
    }

    public function pauseSubscription(Subscription $subscription): void
    {
        throw new GatewayException('Pausar suscripciones solo está disponible con Mercado Pago.');
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        throw new GatewayException('Reanudar suscripciones solo está disponible con Mercado Pago.');
    }

    public function verifyWebhook(\WP_REST_Request $request): WebhookEvent
    {
        $body = $request->get_json_params();

        /** @var array<string, mixed> $body */
        $body = is_array($body) ? $body : [];

        $headers = [];

        foreach (['paypal-transmission-id', 'paypal-transmission-time', 'paypal-transmission-sig', 'paypal-cert-url', 'paypal-auth-algo'] as $name) {
            $value = $request->get_header($name);
            $headers[$name] = is_string($value) ? $value : '';
        }

        if (!$this->client->verifyWebhookSignature($headers, $body)) {
            throw new GatewayException('Firma de webhook de PayPal inválida.');
        }

        $eventId = is_string($body['id'] ?? null) ? $body['id'] : '';
        $topic = is_string($body['event_type'] ?? null) ? $body['event_type'] : '';

        if ($eventId === '') {
            throw new GatewayException('Evento de PayPal sin id.');
        }

        return new WebhookEvent('paypal', $eventId, $topic, ['body' => $body]);
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
        return $this->client->get('/v1/billing/subscriptions/' . $gatewaySubId);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchPayment(string $gatewayPaymentId): array
    {
        return $this->client->get('/v2/payments/captures/' . $gatewayPaymentId);
    }

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLink
    {
        $linkUuid = Uuid::v4();
        $thanksUrl = $this->thanksUrl(null);

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $linkUuid,
                'custom_id' => $linkUuid,
                'invoice_id' => 'impay-link-' . $linkUuid,
                'description' => $request->description,
                'amount' => [
                    'currency_code' => $request->amount->currency,
                    'value' => self::toApiAmount($request->amount->amount),
                ],
            ]],
            'application_context' => [
                'brand_name' => self::BRAND_NAME,
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $thanksUrl,
                'cancel_url' => $thanksUrl,
            ],
        ];

        $response = $this->client->post(
            '/v2/checkout/orders',
            $payload,
            IdempotencyKey::derive('pp-link', $linkUuid),
        );

        $now = $this->clock->now();

        $linkId = $this->paymentLinks->insert([
            'uuid' => $linkUuid,
            'customer_id' => $request->customer->id,
            'subscription_id' => $request->subscriptionId,
            'price_id' => $request->priceId ?? 0,
            'gateway' => $this->id(),
            'gateway_ref' => is_string($response['id'] ?? null) ? $response['id'] : null,
            'url' => $this->approveLink($response),
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

    /**
     * Crea (una sola vez) el Catalog Product y el Billing Plan del precio,
     * y guarda las referencias en gateway_refs.
     */
    private function ensureBillingPlan(Price $price, string $productName): string
    {
        $refs = $price->gatewayRefs ?? [];
        $existing = $refs['paypal_plan_id'] ?? null;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        if ($price->interval === PriceInterval::OneTime) {
            throw new GatewayException('Un precio de pago único no puede tener plan de suscripción.');
        }

        $catalogProductId = $refs['paypal_product_id'] ?? null;

        if (!is_string($catalogProductId) || $catalogProductId === '') {
            $catalogProduct = $this->client->post(
                '/v1/catalogs/products',
                ['name' => $productName, 'type' => 'SERVICE'],
                IdempotencyKey::derive('pp-product', $price->uuid),
            );

            $catalogProductId = is_string($catalogProduct['id'] ?? null) ? $catalogProduct['id'] : '';

            if ($catalogProductId === '') {
                throw new GatewayException('PayPal no devolvió el id del producto de catálogo.');
            }
        }

        $plan = $this->client->post(
            '/v1/billing/plans',
            [
                'product_id' => $catalogProductId,
                'name' => sprintf('%s (%s)', $productName, $price->interval->value),
                'billing_cycles' => [[
                    'frequency' => [
                        'interval_unit' => $price->interval === PriceInterval::Year ? 'YEAR' : 'MONTH',
                        'interval_count' => 1,
                    ],
                    'tenure_type' => 'REGULAR',
                    'sequence' => 1,
                    'total_cycles' => 0,
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'currency_code' => $price->currency,
                            'value' => self::toApiAmount($price->amount),
                        ],
                    ],
                ]],
                'payment_preferences' => [
                    'auto_bill_outstanding' => true,
                    'payment_failure_threshold' => 3,
                ],
            ],
            IdempotencyKey::derive('pp-plan', $price->uuid),
        );

        $planId = is_string($plan['id'] ?? null) ? $plan['id'] : '';

        if ($planId === '') {
            throw new GatewayException('PayPal no devolvió el id del plan de facturación.');
        }

        $this->prices->updateGatewayRefs($price->id, array_merge($refs, [
            'paypal_product_id' => $catalogProductId,
            'paypal_plan_id' => $planId,
        ]), $this->clock->now());

        $this->logger->info('paypal', sprintf('Plan %s creado para el precio %s.', $planId, $price->uuid));

        return $planId;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function approveLink(array $response): string
    {
        $links = is_array($response['links'] ?? null) ? $response['links'] : [];

        foreach ($links as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === 'approve' && is_string($link['href'] ?? null)) {
                return $link['href'];
            }
        }

        throw new GatewayException('PayPal no devolvió una URL de aprobación.');
    }

    /**
     * Monto en unidad mínima → formato decimal de la API ("12.99").
     * Conversión solo en el borde: el dominio nunca opera con floats.
     */
    private static function toApiAmount(int $minorUnits): string
    {
        return sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }

    private function thanksUrl(?string $orderUuid): string
    {
        $pageId = (int) get_option('impay_page_gracias', 0);
        $url = $pageId > 0 ? get_permalink($pageId) : false;
        $url = is_string($url) ? $url : home_url('/gracias-compra/');

        return $orderUuid === null ? $url : add_query_arg('order', $orderUuid, $url);
    }
}
