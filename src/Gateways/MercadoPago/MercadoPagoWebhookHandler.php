<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\MercadoPago;

use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\PaymentService;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Exceptions\InvalidTransitionException;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Gateways\WebhookEvent;
use ImaginaPay\Support\Logger;

/**
 * Traduce eventos de Mercado Pago a eventos de dominio. El webhook trae
 * solo IDs: SIEMPRE se hace fetch a la API antes de procesar; jamás se
 * confía en el payload entrante.
 */
class MercadoPagoWebhookHandler
{
    public function __construct(
        private readonly MercadoPagoClient $client,
        private readonly OrderRepository $orders,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PaymentService $payments,
        private readonly SubscriptionStateMachine $stateMachine,
        private readonly Logger $logger,
    ) {
    }

    public function handle(WebhookEvent $event): void
    {
        $dataId = is_string($event->payload['data_id'] ?? null) ? $event->payload['data_id'] : '';

        if ($dataId === '') {
            $this->logger->warning('webhooks', 'Evento de Mercado Pago sin data.id: omitido.', [
                'topic' => $event->topic,
            ]);

            return;
        }

        match ($event->topic) {
            'payment' => $this->processPayment($dataId),
            'subscription_preapproval' => $this->processPreapproval($dataId),
            'subscription_authorized_payment' => $this->processAuthorizedPayment($dataId),
            default => $this->logger->info('webhooks', sprintf(
                'Topic de Mercado Pago no manejado: "%s".',
                $event->topic,
            )),
        };
    }

    /**
     * Topic payment: pagos únicos y también el pago concreto de cada
     * cargo recurrente. El external_reference decide a qué pertenece.
     */
    private function processPayment(string $paymentId): void
    {
        $data = $this->client->get('/v1/payments/' . $paymentId);
        $externalReference = is_string($data['external_reference'] ?? null) ? $data['external_reference'] : '';
        $payment = $this->toGatewayPayment((string) ($data['id'] ?? $paymentId), $data);

        $order = $externalReference !== '' ? $this->orders->findByExternalReference($externalReference) : null;

        if ($order !== null) {
            $this->payments->applyOrderPayment($order, $payment);

            return;
        }

        // Cargos de preapproval heredan el external_reference de la suscripción.
        $subscription = $externalReference !== '' ? $this->subscriptions->findByUuid($externalReference) : null;

        if ($subscription !== null) {
            $this->payments->applySubscriptionPayment($subscription, $payment);

            return;
        }

        $this->logger->warning('webhooks', sprintf(
            'Pago %s de Mercado Pago sin order ni suscripción local (external_reference: "%s").',
            $paymentId,
            $externalReference,
        ));
    }

    /**
     * Topic subscription_preapproval: cambios de estado de la suscripción.
     */
    private function processPreapproval(string $preapprovalId): void
    {
        $data = $this->client->get('/preapproval/' . $preapprovalId);
        $subscription = $this->subscriptions->findByGatewaySubId('mercadopago', $preapprovalId);

        if ($subscription === null) {
            $externalReference = is_string($data['external_reference'] ?? null) ? $data['external_reference'] : '';
            $subscription = $externalReference !== '' ? $this->subscriptions->findByUuid($externalReference) : null;

            if ($subscription !== null && $subscription->gatewaySubId === null) {
                $this->subscriptions->setGatewaySubId($subscription->id, $preapprovalId, $subscription->updatedAt);
            }
        }

        if ($subscription === null) {
            $this->logger->warning('webhooks', sprintf(
                'Preapproval %s de Mercado Pago sin suscripción local.',
                $preapprovalId,
            ));

            return;
        }

        $mpStatus = is_string($data['status'] ?? null) ? $data['status'] : '';

        $target = match ($mpStatus) {
            'authorized' => SubscriptionStatus::Active,
            'paused' => SubscriptionStatus::Paused,
            'cancelled' => SubscriptionStatus::Cancelled,
            default => null, // pending u otros: sin efecto.
        };

        if ($target === null) {
            return;
        }

        try {
            // La extensión de periodo NO ocurre aquí: la hace el webhook del
            // pago aprobado (evita doble extensión activación + primer cargo).
            $this->stateMachine->transition($subscription, $target, [
                'source' => 'webhook',
                'gateway' => 'mercadopago',
                'preapproval_id' => $preapprovalId,
            ]);
        } catch (InvalidTransitionException $exception) {
            $this->logger->warning('webhooks', $exception->getMessage(), [
                'preapproval_id' => $preapprovalId,
            ]);
        }
    }

    /**
     * Topic subscription_authorized_payment: cada cargo recurrente.
     * Se deduplica con el topic payment vía el id del pago subyacente.
     */
    private function processAuthorizedPayment(string $authorizedPaymentId): void
    {
        $data = $this->client->get('/authorized_payments/' . $authorizedPaymentId);
        $preapprovalId = is_string($data['preapproval_id'] ?? null) ? $data['preapproval_id'] : '';

        $subscription = $preapprovalId !== ''
            ? $this->subscriptions->findByGatewaySubId('mercadopago', $preapprovalId)
            : null;

        if ($subscription === null) {
            $this->logger->warning('webhooks', sprintf(
                'Cargo autorizado %s de Mercado Pago sin suscripción local (preapproval: "%s").',
                $authorizedPaymentId,
                $preapprovalId,
            ));

            return;
        }

        $paymentData = is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $paymentId = isset($paymentData['id']) ? (string) $paymentData['id'] : 'ap-' . $authorizedPaymentId;

        $merged = array_merge($data, ['status' => $paymentData['status'] ?? ($data['status'] ?? '')]);
        $this->payments->applySubscriptionPayment($subscription, $this->toGatewayPayment($paymentId, $merged));
    }

    /**
     * @param array<string, mixed> $data Respuesta de la API de MP.
     */
    private function toGatewayPayment(string $paymentId, array $data): GatewayPayment
    {
        $amountMajor = $data['transaction_amount'] ?? 0;
        $amountMajor = is_numeric($amountMajor) ? (float) $amountMajor : 0.0;

        $approvedAt = null;

        if (is_string($data['date_approved'] ?? null) && $data['date_approved'] !== '') {
            try {
                $approvedAt = new \DateTimeImmutable($data['date_approved']);
            } catch (\Exception) {
                $approvedAt = null;
            }
        }

        return new GatewayPayment(
            gateway: 'mercadopago',
            gatewayPaymentId: $paymentId,
            status: $this->mapPaymentStatus(is_string($data['status'] ?? null) ? $data['status'] : ''),
            currency: is_string($data['currency_id'] ?? null) ? $data['currency_id'] : 'COP',
            amount: (int) round($amountMajor * 100),
            method: is_string($data['payment_method_id'] ?? null) ? $data['payment_method_id'] : null,
            paidAt: $approvedAt,
            raw: $data,
        );
    }

    private function mapPaymentStatus(string $mpStatus): PaymentStatus
    {
        return match ($mpStatus) {
            'approved' => PaymentStatus::Approved,
            'refunded' => PaymentStatus::Refunded,
            'charged_back' => PaymentStatus::ChargedBack,
            'rejected', 'cancelled' => PaymentStatus::Rejected,
            default => PaymentStatus::Pending, // pending, in_process, authorized...
        };
    }
}
