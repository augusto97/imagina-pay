<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\PayPal;

use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\PaymentService;
use ImaginaPay\Domain\Services\RenewalService;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Exceptions\InvalidTransitionException;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Gateways\WebhookEvent;
use ImaginaPay\Support\Logger;

/**
 * Traduce eventos de PayPal a eventos de dominio. A diferencia de MP,
 * el payload completo viene firmado y verificado (verify-webhook-signature),
 * por lo que el resource del evento es confiable.
 */
class PayPalWebhookHandler
{
    public function __construct(
        private readonly PayPalClient $client,
        private readonly OrderRepository $orders,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PaymentLinkRepository $paymentLinks,
        private readonly PaymentService $payments,
        private readonly RenewalService $renewals,
        private readonly SubscriptionStateMachine $stateMachine,
        private readonly Logger $logger,
    ) {
    }

    public function handle(WebhookEvent $event): void
    {
        $body = is_array($event->payload['body'] ?? null) ? $event->payload['body'] : [];

        /** @var array<string, mixed> $resource */
        $resource = is_array($body['resource'] ?? null) ? $body['resource'] : [];

        match ($event->topic) {
            'CHECKOUT.ORDER.APPROVED' => $this->captureApprovedOrder($resource),
            'PAYMENT.CAPTURE.COMPLETED' => $this->applyCapture($resource, PaymentStatus::Approved),
            'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED' => $this->applyCapture($resource, PaymentStatus::Refunded),
            'PAYMENT.SALE.COMPLETED' => $this->applySale($resource),
            'BILLING.SUBSCRIPTION.ACTIVATED' => $this->transitionSubscription($resource, SubscriptionStatus::Active),
            'BILLING.SUBSCRIPTION.CANCELLED' => $this->transitionSubscription($resource, SubscriptionStatus::Cancelled),
            'BILLING.SUBSCRIPTION.SUSPENDED' => $this->transitionSubscription($resource, SubscriptionStatus::Paused),
            'BILLING.SUBSCRIPTION.EXPIRED' => $this->transitionSubscription($resource, SubscriptionStatus::Expired),
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->applyPaymentFailure($resource),
            default => $this->logger->info('webhooks', sprintf(
                'Evento de PayPal no manejado: "%s".',
                $event->topic,
            )),
        };
    }

    /**
     * El cliente aprobó el pago: capturar de inmediato (intent CAPTURE).
     * El PAYMENT.CAPTURE.COMPLETED posterior aplica el pago; capturar aquí
     * también procesa el resultado por si ese evento se pierde.
     *
     * @param array<string, mixed> $resource
     */
    private function captureApprovedOrder(array $resource): void
    {
        $orderId = is_string($resource['id'] ?? null) ? $resource['id'] : '';

        if ($orderId === '') {
            return;
        }

        try {
            $capture = $this->client->post(
                '/v2/checkout/orders/' . $orderId . '/capture',
                [],
                'impay-capture-' . $orderId,
            );
        } catch (GatewayException $exception) {
            // ORDER_ALREADY_CAPTURED u otro estado no capturable: informativo.
            $this->logger->info('webhooks', sprintf(
                'Captura de order %s de PayPal no realizada: %s',
                $orderId,
                $exception->getMessage(),
            ));

            return;
        }

        foreach ($this->extractCaptures($capture) as $captureResource) {
            $status = ($captureResource['status'] ?? '') === 'COMPLETED'
                ? PaymentStatus::Approved
                : PaymentStatus::Pending;

            $this->applyCapture($captureResource, $status);
        }
    }

    /**
     * Un capture (pago único o link de pago). custom_id transporta el
     * external_reference: uuid del order o uuid del payment link.
     *
     * @param array<string, mixed> $resource
     */
    private function applyCapture(array $resource, PaymentStatus $status): void
    {
        $captureId = is_string($resource['id'] ?? null) ? $resource['id'] : '';
        $customId = is_string($resource['custom_id'] ?? null) ? $resource['custom_id'] : '';

        if ($captureId === '') {
            return;
        }

        $payment = $this->toGatewayPayment($captureId, $resource, $status);
        $order = $customId !== '' ? $this->orders->findByExternalReference($customId) : null;

        if ($order !== null) {
            $this->payments->applyOrderPayment($order, $payment);

            return;
        }

        $link = $customId !== '' ? $this->paymentLinks->findByUuid($customId) : null;

        if ($link !== null) {
            $this->renewals->applyPaidLink($link, $payment);

            return;
        }

        $this->logger->warning('webhooks', sprintf(
            'Capture %s de PayPal sin order ni link local (custom_id: "%s").',
            $captureId,
            $customId,
        ));
    }

    /**
     * PAYMENT.SALE.COMPLETED: cobro recurrente de una Billing Subscription.
     *
     * @param array<string, mixed> $resource
     */
    private function applySale(array $resource): void
    {
        $saleId = is_string($resource['id'] ?? null) ? $resource['id'] : '';
        $billingAgreementId = is_string($resource['billing_agreement_id'] ?? null)
            ? $resource['billing_agreement_id']
            : '';

        if ($saleId === '' || $billingAgreementId === '') {
            return;
        }

        $subscription = $this->subscriptions->findByGatewaySubId('paypal', $billingAgreementId);

        if ($subscription === null) {
            $this->logger->warning('webhooks', sprintf(
                'Venta %s de PayPal sin suscripción local (billing agreement: "%s").',
                $saleId,
                $billingAgreementId,
            ));

            return;
        }

        /** @var array<string, mixed> $amount */
        $amount = is_array($resource['amount'] ?? null) ? $resource['amount'] : [];
        $value = is_string($amount['total'] ?? null) ? $amount['total'] : '0';
        $currency = is_string($amount['currency'] ?? null) ? $amount['currency'] : 'USD';

        $payment = new GatewayPayment(
            gateway: 'paypal',
            gatewayPaymentId: $saleId,
            status: PaymentStatus::Approved,
            currency: $currency,
            amount: (int) round(((float) $value) * 100),
            method: 'paypal',
            paidAt: $this->parseDate($resource['create_time'] ?? null),
            raw: $resource,
        );

        $this->payments->applySubscriptionPayment($subscription, $payment);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function applyPaymentFailure(array $resource): void
    {
        $subscription = $this->findSubscription($resource);

        if ($subscription === null) {
            return;
        }

        $this->payments->applyChargeFailure($subscription, [
            'gateway' => 'paypal',
            'event' => 'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
        ]);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function transitionSubscription(array $resource, SubscriptionStatus $target): void
    {
        $subscription = $this->findSubscription($resource);

        if ($subscription === null) {
            return;
        }

        try {
            // Igual que en MP: el periodo lo extiende el webhook del pago
            // (PAYMENT.SALE.COMPLETED), no el cambio de estado.
            $this->stateMachine->transition($subscription, $target, [
                'source' => 'webhook',
                'gateway' => 'paypal',
            ]);
        } catch (InvalidTransitionException $exception) {
            $this->logger->warning('webhooks', $exception->getMessage(), [
                'subscription' => $subscription->uuid,
            ]);
        }
    }

    /**
     * Busca la suscripción por el id de PayPal (I-XXX) con fallback por
     * custom_id (uuid local), vinculando el gateway_sub_id si faltaba.
     *
     * @param array<string, mixed> $resource
     */
    private function findSubscription(array $resource): ?\ImaginaPay\Domain\Entities\Subscription
    {
        $paypalSubId = is_string($resource['id'] ?? null) ? $resource['id'] : '';
        $subscription = $paypalSubId !== ''
            ? $this->subscriptions->findByGatewaySubId('paypal', $paypalSubId)
            : null;

        if ($subscription === null) {
            $customId = is_string($resource['custom_id'] ?? null) ? $resource['custom_id'] : '';
            $subscription = $customId !== '' ? $this->subscriptions->findByUuid($customId) : null;

            if ($subscription !== null && $subscription->gatewaySubId === null && $paypalSubId !== '') {
                $this->subscriptions->setGatewaySubId($subscription->id, $paypalSubId, $subscription->updatedAt);
            }
        }

        if ($subscription === null) {
            $this->logger->warning('webhooks', 'Evento de suscripción de PayPal sin suscripción local.', [
                'paypal_sub_id' => $paypalSubId,
            ]);
        }

        return $subscription;
    }

    /**
     * @param array<string, mixed> $capture Respuesta de captura de Orders v2.
     * @return list<array<string, mixed>>
     */
    private function extractCaptures(array $capture): array
    {
        $captures = [];
        $units = is_array($capture['purchase_units'] ?? null) ? $capture['purchase_units'] : [];

        foreach ($units as $unit) {
            if (!is_array($unit)) {
                continue;
            }

            $payments = is_array($unit['payments'] ?? null) ? $unit['payments'] : [];
            $unitCaptures = is_array($payments['captures'] ?? null) ? $payments['captures'] : [];

            foreach ($unitCaptures as $unitCapture) {
                if (is_array($unitCapture)) {
                    /** @var array<string, mixed> $unitCapture */
                    $captures[] = $unitCapture;
                }
            }
        }

        return $captures;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function toGatewayPayment(string $captureId, array $resource, PaymentStatus $status): GatewayPayment
    {
        /** @var array<string, mixed> $amount */
        $amount = is_array($resource['amount'] ?? null) ? $resource['amount'] : [];
        $value = is_string($amount['value'] ?? null) ? $amount['value'] : '0';
        $currency = is_string($amount['currency_code'] ?? null) ? $amount['currency_code'] : 'USD';

        return new GatewayPayment(
            gateway: 'paypal',
            gatewayPaymentId: $captureId,
            status: $status,
            currency: $currency,
            amount: (int) round(((float) $value) * 100),
            method: 'paypal',
            paidAt: $this->parseDate($resource['create_time'] ?? null),
            raw: $resource,
        );
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
