<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\Wompi;

use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\PaymentLink;
use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\PaymentLinkStatus;
use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\PaymentSourceRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\PaymentService;
use ImaginaPay\Domain\Services\RenewalService;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Gateways\CheckoutSession;
use ImaginaPay\Gateways\GatewayMode;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Gateways\PaymentLinkRequest;
use ImaginaPay\Gateways\TokenizedGatewayInterface;
use ImaginaPay\Gateways\WebhookEvent;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;
use ImaginaPay\Support\Uuid;

/**
 * Wompi (Bancolombia) — modo Tokenized (Fase 8 del spec).
 *
 * Pago único y links: Web Checkout (redirect firmado con el secreto de
 * integridad). Suscripciones: el navegador tokeniza tarjeta o Nequi con
 * la llave pública (el PAN nunca toca el servidor), el gateway crea la
 * fuente de pago con acceptance_token y dispara el primer cobro; los
 * siguientes los agenda el BillingEngine. Las transacciones son
 * asíncronas: el estado final llega por el webhook de eventos (checksum
 * SHA-256 verificado).
 */
final class WompiGateway implements TokenizedGatewayInterface
{
    private const WEB_CHECKOUT_URL = 'https://checkout.wompi.co/p/';

    /** Prefijo de referencia de los cobros de renovación del BillingEngine. */
    public const RENEWAL_PREFIX = 'impay-ren-';

    public function __construct(
        private readonly WompiClient $client,
        private readonly WompiWebhookVerifier $verifier,
        private readonly CustomerRepository $customers,
        private readonly OrderRepository $orders,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PaymentSourceRepository $paymentSources,
        private readonly PaymentLinkRepository $paymentLinks,
        private readonly PaymentService $payments,
        private readonly RenewalService $renewals,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function id(): string
    {
        return 'wompi';
    }

    public function mode(): GatewayMode
    {
        return GatewayMode::Tokenized;
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'one_time', 'recurring', 'pse', 'nequi', 'nequi_recurring', 'currency_COP', 'payment_links' => true,
            default => false, // pause/resume (solo MP), trial, currency_USD...
        };
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Configuración pública para el frontend (tokenización en el navegador).
     *
     * @return array{public_key: string, base_url: string}
     */
    public function publicConfig(): array
    {
        return [
            'public_key' => $this->client->publicKey(),
            'base_url' => $this->client->baseUrl(),
        ];
    }

    public function createOneTimeCheckout(Order $order): CheckoutSession
    {
        $customer = $this->customers->find($order->customerId);

        $url = $this->webCheckoutUrl(
            $order->externalReference,
            $order->amount,
            $order->currency,
            $customer?->email,
            redirectOrderUuid: $order->uuid,
        );

        return new CheckoutSession($url);
    }

    /**
     * Alta tokenizada: el token del navegador viene en el meta de la
     * suscripción (pending_token, lo pone CheckoutService). Crea la
     * fuente de pago y dispara el primer cobro (asíncrono).
     */
    public function createSubscription(Subscription $subscription, Price $price): CheckoutSession
    {
        $token = is_string($subscription->meta['pending_token'] ?? null) ? $subscription->meta['pending_token'] : '';
        $type = is_string($subscription->meta['pending_token_type'] ?? null) ? $subscription->meta['pending_token_type'] : '';

        if ($token === '' || !in_array($type, ['CARD', 'NEQUI'], true)) {
            throw new GatewayException('Falta el medio de pago tokenizado para la suscripción con Wompi.');
        }

        $customer = $this->customers->find($subscription->customerId);

        if ($customer === null) {
            throw new GatewayException('La suscripción no tiene cliente asociado.');
        }

        $acceptance = $this->client->acceptanceToken();
        $source = $this->client->createPaymentSource($type, $token, $customer->email, $acceptance);

        $publicData = is_array($source['public_data'] ?? null) ? $source['public_data'] : [];
        $brand = is_string($publicData['brand'] ?? null) ? $publicData['brand'] : null;
        $lastFour = is_string($publicData['last_four'] ?? null) ? $publicData['last_four'] : null;

        if ($lastFour === null && is_string($publicData['phone_number'] ?? null)) {
            $lastFour = substr($publicData['phone_number'], -4);
        }

        $now = $this->clock->now();

        $this->paymentSources->insert([
            'uuid' => Uuid::v4(),
            'customer_id' => $customer->id,
            'gateway' => $this->id(),
            'gateway_source_id' => (string) $source['id'],
            'type' => $type,
            'brand' => $brand,
            'last_four' => $lastFour,
            'status' => strtolower(is_string($source['status'] ?? null) ? $source['status'] : 'available'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        // El token de un solo uso ya se consumió: fuera del meta. Queda la
        // fuente para el BillingEngine y el resumen para portal/admin.
        $meta = $subscription->meta ?? [];
        unset($meta['pending_token'], $meta['pending_token_type']);
        $meta['payment_source_id'] = (string) $source['id'];
        $meta['payment_method'] = ['type' => $type, 'brand' => $brand, 'last_four' => $lastFour];
        $this->subscriptions->updateMeta($subscription->id, $meta, $now);

        // Primer cobro inmediato. Referencia = uuid de la suscripción: el
        // webhook lo resuelve y activa la suscripción + paga el order inicial.
        $this->client->createTransaction($this->transactionPayload(
            $price->amount,
            $price->currency,
            $customer->email,
            $subscription->uuid,
            (string) $source['id'],
            $type,
        ));

        $initialOrderUuid = is_string($meta['initial_order_uuid'] ?? null) ? $meta['initial_order_uuid'] : null;

        // gatewayRef null a propósito: Wompi no tiene objeto de suscripción
        // (el motor es el BillingEngine) y la reconciliación no aplica.
        return new CheckoutSession($this->thanksUrl($initialOrderUuid));
    }

    public function chargeStoredSource(Subscription $subscription, Price $price, string $gatewaySourceId, string $reference): string
    {
        $customer = $this->customers->find($subscription->customerId);

        if ($customer === null) {
            throw new GatewayException('La suscripción no tiene cliente asociado.');
        }

        $type = is_array($subscription->meta['payment_method'] ?? null)
            && is_string($subscription->meta['payment_method']['type'] ?? null)
            ? $subscription->meta['payment_method']['type']
            : 'CARD';

        $transaction = $this->client->createTransaction($this->transactionPayload(
            $price->amount,
            $price->currency,
            $customer->email,
            $reference,
            $gatewaySourceId,
            $type,
            recurrent: true,
        ));

        return (string) $transaction['id'];
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        // Tokenized: el motor de cobro es local; no hay nada que cancelar
        // en Wompi. El BillingEngine deja de cobrar al leer el estado.
        $this->logger->info('wompi', sprintf(
            'Suscripción %s cancelada localmente (Wompi no requiere llamada).',
            $subscription->uuid,
        ));
    }

    public function pauseSubscription(Subscription $subscription): void
    {
        throw new GatewayException('Wompi no soporta pausar suscripciones en esta versión.');
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        throw new GatewayException('Wompi no soporta reanudar suscripciones en esta versión.');
    }

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLink
    {
        $linkUuid = Uuid::v4();

        $url = $this->webCheckoutUrl(
            $linkUuid,
            $request->amount->amount,
            $request->amount->currency,
            $request->customer->email,
            redirectOrderUuid: null,
        );

        $now = $this->clock->now();

        $linkId = $this->paymentLinks->insert([
            'uuid' => $linkUuid,
            'customer_id' => $request->customer->id,
            'subscription_id' => $request->subscriptionId,
            'price_id' => $request->priceId ?? 0,
            'gateway' => $this->id(),
            'gateway_ref' => null,
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

    public function verifyWebhook(\WP_REST_Request $request): WebhookEvent
    {
        $body = $request->get_json_params();

        /** @var array<string, mixed> $body */
        $body = is_array($body) ? $body : [];

        $this->verifier->verify($body, $this->client->eventsSecret());

        $topic = is_string($body['event'] ?? null) ? $body['event'] : '';
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
        $transactionId = is_scalar($transaction['id'] ?? null) ? (string) $transaction['id'] : '';
        $status = is_string($transaction['status'] ?? null) ? $transaction['status'] : '';

        if ($transactionId === '') {
            throw new GatewayException('Evento de Wompi sin transacción.');
        }

        // Un mismo id puede notificarse en varios estados: dedupe por ambos.
        $eventId = hash('sha256', sprintf('wompi|%s|%s', $transactionId, $status));

        // El checksum verificado cubre id/status/amount: el payload firmado
        // es confiable (misma política que PayPal verify-webhook-signature).
        return new WebhookEvent('wompi', $eventId, $topic, ['transaction' => $transaction]);
    }

    public function handleWebhook(WebhookEvent $event): void
    {
        $transaction = is_array($event->payload['transaction'] ?? null) ? $event->payload['transaction'] : [];
        $reference = is_string($transaction['reference'] ?? null) ? $transaction['reference'] : '';

        if ($reference === '') {
            $this->logger->warning('webhooks', 'Evento de Wompi sin referencia: omitido.');

            return;
        }

        $payment = $this->toGatewayPayment($transaction);

        // Renovación del BillingEngine: impay-ren-{uuid}-{periodo}.
        if (str_starts_with($reference, self::RENEWAL_PREFIX)) {
            $subscriptionUuid = substr($reference, strlen(self::RENEWAL_PREFIX), 36);
            $subscription = Uuid::isValid($subscriptionUuid) ? $this->subscriptions->findByUuid($subscriptionUuid) : null;

            if ($subscription === null) {
                $this->logger->warning('webhooks', sprintf(
                    'Renovación Wompi %s sin suscripción local.',
                    $reference,
                ));

                return;
            }

            $this->payments->applySubscriptionPayment($subscription, $payment);

            return;
        }

        // Pago único (referencia = external_reference del order).
        $order = $this->orders->findByExternalReference($reference);

        if ($order !== null) {
            $this->payments->applyOrderPayment($order, $payment);

            return;
        }

        // Primer cargo de suscripción (referencia = uuid de la suscripción).
        $subscription = $this->subscriptions->findByUuid($reference);

        if ($subscription !== null) {
            $this->payments->applySubscriptionPayment($subscription, $payment);

            return;
        }

        // Link de pago (renovación anual / cobro manual).
        $link = $this->paymentLinks->findByUuid($reference);

        if ($link !== null) {
            $this->renewals->applyPaidLink($link, $payment);

            return;
        }

        $this->logger->warning('webhooks', sprintf(
            'Transacción %s de Wompi sin order, suscripción ni link local (referencia: "%s").',
            (string) ($transaction['id'] ?? ''),
            $reference,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchSubscription(string $gatewaySubId): array
    {
        throw new GatewayException('Wompi no tiene objeto de suscripción: el estado vive en el plugin (BillingEngine).');
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchPayment(string $gatewayPaymentId): array
    {
        return $this->client->getTransaction($gatewayPaymentId);
    }

    /**
     * URL de Web Checkout firmada: SHA-256(referencia + monto + moneda + secreto).
     */
    private function webCheckoutUrl(
        string $reference,
        int $amountInCents,
        string $currency,
        ?string $email,
        ?string $redirectOrderUuid = null,
    ): string {
        $integritySecret = $this->client->integritySecret();

        if ($this->client->publicKey() === '' || $integritySecret === '') {
            throw new GatewayException('Wompi no está configurado (llave pública y secreto de integridad).');
        }

        $signature = hash('sha256', $reference . $amountInCents . strtoupper($currency) . $integritySecret);

        $params = [
            'public-key' => $this->client->publicKey(),
            'currency' => strtoupper($currency),
            'amount-in-cents' => (string) $amountInCents,
            'reference' => $reference,
            'signature:integrity' => $signature,
            'redirect-url' => $this->thanksUrl($redirectOrderUuid),
        ];

        if ($email !== null && $email !== '') {
            $params['customer-data:email'] = $email;
        }

        return self::WEB_CHECKOUT_URL . '?' . http_build_query($params);
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(
        int $amountInCents,
        string $currency,
        string $customerEmail,
        string $reference,
        string $paymentSourceId,
        string $type,
        bool $recurrent = false,
    ): array {
        $payload = [
            'amount_in_cents' => $amountInCents,
            'currency' => strtoupper($currency),
            'customer_email' => $customerEmail,
            'reference' => $reference,
            'payment_source_id' => (int) $paymentSourceId,
        ];

        if ($type === 'CARD') {
            $payload['payment_method'] = ['installments' => 1];
        }

        if ($recurrent) {
            $payload['recurrent'] = true;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function toGatewayPayment(array $transaction): GatewayPayment
    {
        $amount = $transaction['amount_in_cents'] ?? 0;
        $amount = is_numeric($amount) ? (int) $amount : 0;

        $paidAt = null;
        $finalized = $transaction['finalized_at'] ?? ($transaction['created_at'] ?? null);

        if (is_string($finalized) && $finalized !== '') {
            try {
                $paidAt = new \DateTimeImmutable($finalized);
            } catch (\Exception) {
                $paidAt = null;
            }
        }

        return new GatewayPayment(
            gateway: 'wompi',
            gatewayPaymentId: is_scalar($transaction['id'] ?? null) ? (string) $transaction['id'] : '',
            status: $this->mapStatus(is_string($transaction['status'] ?? null) ? $transaction['status'] : ''),
            currency: is_string($transaction['currency'] ?? null) ? strtoupper($transaction['currency']) : 'COP',
            amount: $amount,
            method: is_string($transaction['payment_method_type'] ?? null)
                ? strtolower($transaction['payment_method_type'])
                : null,
            paidAt: $paidAt,
            raw: $transaction,
        );
    }

    private function mapStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'APPROVED' => PaymentStatus::Approved,
            'DECLINED', 'ERROR' => PaymentStatus::Rejected,
            'VOIDED' => PaymentStatus::Refunded,
            default => PaymentStatus::Pending, // PENDING y desconocidos
        };
    }

    private function thanksUrl(?string $orderUuid): string
    {
        $pageId = (int) get_option('impay_page_gracias', 0);
        $url = $pageId > 0 ? get_permalink($pageId) : false;
        $url = is_string($url) ? $url : home_url('/gracias-compra/');

        return $orderUuid === null ? $url : add_query_arg('order', $orderUuid, $url);
    }
}
