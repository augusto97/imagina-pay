<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Services;

use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\Payment;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\OrderStatus;
use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentRepository;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Exceptions\InvalidTransitionException;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;
use ImaginaPay\Support\Uuid;

/**
 * Aplica cobros reportados por las pasarelas al estado local. Idempotente:
 * un mismo gateway_payment_id nunca se registra dos veces; si vuelve con
 * otro estado (pending → approved) se actualiza.
 */
class PaymentService
{
    private const MAX_FAILED_PAYMENTS = 3;

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly OrderRepository $orders,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PriceRepository $prices,
        private readonly SubscriptionStateMachine $stateMachine,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Cobro asociado a un order (pago único o primer cargo).
     */
    public function applyOrderPayment(Order $order, GatewayPayment $payment): void
    {
        if (!$this->upsertPayment($payment, $order->customerId, $order->id, $order->subscriptionId)) {
            return; // Duplicado exacto: nada que hacer.
        }

        $now = $this->clock->now();
        $this->orders->setGatewayPaymentId($order->id, $payment->gatewayPaymentId, $now);

        // Defensa en profundidad: un pago aprobado solo marca el order como
        // pagado si monto y moneda coinciden con lo que se cobró. El pago
        // queda registrado (auditoría) pero el order sigue pending y la
        // discrepancia queda en logs para revisión manual.
        if (
            $payment->status === PaymentStatus::Approved
            && (strtoupper($payment->currency) !== strtoupper($order->currency) || $payment->amount < $order->amount)
        ) {
            $this->logger->error('payments', sprintf(
                'Pago %s aprobado NO aplicado al order %s: monto/moneda no coinciden (pago: %d %s, order: %d %s). Requiere revisión manual.',
                $payment->gatewayPaymentId,
                $order->uuid,
                $payment->amount,
                $payment->currency,
                $order->amount,
                $order->currency,
            ));

            return;
        }

        if ($payment->status === PaymentStatus::Approved) {
            // Un recibo por pago aprobado (el upsert deduplica reintentos).
            do_action('impay_payment_approved', $payment, $order->customerId);
        }

        // Un order pagado nunca se degrada por webhooks tardíos (salvo reembolso).
        if ($order->status === OrderStatus::Paid && $payment->status !== PaymentStatus::Refunded) {
            return;
        }

        $newStatus = match ($payment->status) {
            PaymentStatus::Approved => OrderStatus::Paid,
            PaymentStatus::Rejected => OrderStatus::Failed,
            PaymentStatus::Refunded => OrderStatus::Refunded,
            PaymentStatus::ChargedBack => OrderStatus::Refunded,
            PaymentStatus::Pending => null,
        };

        if ($newStatus === null || $newStatus === $order->status) {
            return;
        }

        $paidAt = $newStatus === OrderStatus::Paid ? ($payment->paidAt ?? $now) : null;
        $this->orders->updateStatus($order->id, $newStatus, $now, $paidAt);

        $this->logger->info('payments', sprintf(
            'Order %s: %s → %s (pago %s).',
            $order->uuid,
            $order->status->value,
            $newStatus->value,
            $payment->gatewayPaymentId,
        ));

        do_action('impay_order_' . $newStatus->value, $order, $payment);
    }

    /**
     * Cobro recurrente de una suscripción (o su primer cargo).
     */
    public function applySubscriptionPayment(Subscription $subscription, GatewayPayment $payment): void
    {
        if (!$this->upsertPayment($payment, $subscription->customerId, null, $subscription->id)) {
            return;
        }

        match ($payment->status) {
            PaymentStatus::Approved => $this->onSubscriptionPaymentApproved($subscription, $payment),
            PaymentStatus::Rejected => $this->onSubscriptionPaymentRejected($subscription, $payment),
            default => $this->logger->info('payments', sprintf(
                'Pago %s de la suscripción %s en estado %s: sin efecto.',
                $payment->gatewayPaymentId,
                $subscription->uuid,
                $payment->status->value,
            )),
        };
    }

    private function onSubscriptionPaymentApproved(Subscription $subscription, GatewayPayment $payment): void
    {
        $now = $this->clock->now();

        do_action('impay_payment_approved', $payment, $subscription->customerId);

        $price = $this->prices->find($subscription->priceId);

        if ($price === null) {
            $this->logger->error('payments', sprintf(
                'Suscripción %s sin precio asociado: no se puede extender el periodo.',
                $subscription->uuid,
            ));

            return;
        }

        // El nuevo periodo arranca donde termina el vigente (o ahora si venció / es el primero).
        $base = $subscription->currentPeriodEnd !== null && $subscription->currentPeriodEnd > $now
            ? $subscription->currentPeriodEnd
            : $now;
        $newEnd = $base->add($price->interval->period());

        $this->subscriptions->extendPeriod($subscription->id, $base, $newEnd, $now);

        $this->logger->info('payments', sprintf(
            'Suscripción %s: periodo extendido hasta %s (pago %s).',
            $subscription->uuid,
            $newEnd->format('Y-m-d'),
            $payment->gatewayPaymentId,
        ), ['amount' => $payment->amount, 'currency' => $payment->currency]);

        // pending → active (primer cargo), past_due → active (recuperación),
        // active → active (no-op idempotente).
        try {
            $this->stateMachine->transition($subscription, SubscriptionStatus::Active, [
                'source' => 'payment',
                'gateway_payment_id' => $payment->gatewayPaymentId,
            ]);
        } catch (InvalidTransitionException) {
            $this->logger->warning('payments', sprintf(
                'Pago aprobado sobre suscripción %s en estado %s: no se pudo activar.',
                $subscription->uuid,
                $subscription->status->value,
            ));
        }

        // Un cobro exitoso cierra cualquier episodio de dunning abierto.
        if (isset($subscription->meta['dunning'])) {
            $meta = $subscription->meta;
            unset($meta['dunning']);
            $this->subscriptions->updateMeta($subscription->id, $meta, $now);
        }

        $this->markInitialOrderPaid($subscription, $payment, $now);
    }

    private function onSubscriptionPaymentRejected(Subscription $subscription, GatewayPayment $payment): void
    {
        $this->applyChargeFailure($subscription, ['gateway_payment_id' => $payment->gatewayPaymentId]);
    }

    /**
     * Fallo de cobro sin pago concreto asociado (p. ej. el evento
     * BILLING.SUBSCRIPTION.PAYMENT.FAILED de PayPal): incrementa el
     * contador, degrada a past_due y cancela al tercer fallo.
     *
     * @param array<string, mixed> $context
     */
    public function applyChargeFailure(Subscription $subscription, array $context = []): void
    {
        $now = $this->clock->now();
        $failed = $this->subscriptions->incrementFailedPayments($subscription->id, $now);

        $this->logger->warning('payments', sprintf(
            'Pago rechazado en suscripción %s (fallo #%d).',
            $subscription->uuid,
            $failed,
        ), $context);

        try {
            if ($failed >= self::MAX_FAILED_PAYMENTS && $subscription->status === SubscriptionStatus::PastDue) {
                $this->stateMachine->transition($subscription, SubscriptionStatus::Cancelled, array_merge($context, [
                    'source' => 'dunning',
                    'failed_payments' => $failed,
                ]));

                return;
            }

            if ($subscription->status === SubscriptionStatus::Active) {
                $this->stateMachine->transition($subscription, SubscriptionStatus::PastDue, array_merge($context, [
                    'source' => 'payment',
                ]));
            }
        } catch (InvalidTransitionException $exception) {
            // Estado ya terminal o incompatible: la state machine ya lo registró.
            $this->logger->debug('payments', $exception->getMessage(), ['subscription' => $subscription->uuid]);
        }
    }

    /**
     * El order subscription_initial del checkout queda paid al aprobarse
     * el primer cargo de la suscripción.
     */
    private function markInitialOrderPaid(Subscription $subscription, GatewayPayment $payment, \DateTimeImmutable $now): void
    {
        $initialOrderUuid = $subscription->meta['initial_order_uuid'] ?? null;

        if (!is_string($initialOrderUuid)) {
            return;
        }

        $order = $this->orders->findByUuid($initialOrderUuid);

        if ($order === null || $order->status !== OrderStatus::Pending) {
            return;
        }

        $this->orders->setGatewayPaymentId($order->id, $payment->gatewayPaymentId, $now);
        $this->orders->updateStatus($order->id, OrderStatus::Paid, $now, $payment->paidAt ?? $now);

        do_action('impay_order_paid', $order, $payment);
    }

    /**
     * Registra o actualiza el pago. Devuelve false si ya existía con el
     * mismo estado (evento duplicado, no hay nada que aplicar).
     */
    private function upsertPayment(GatewayPayment $payment, int $customerId, ?int $orderId, ?int $subscriptionId): bool
    {
        $existing = $this->payments->findByGatewayPaymentId($payment->gateway, $payment->gatewayPaymentId);

        if ($existing instanceof Payment) {
            if ($existing->status === $payment->status) {
                return false;
            }

            $this->payments->updateStatus($existing->id, $payment->status, $payment->paidAt);

            return true;
        }

        $this->payments->insert([
            'uuid' => Uuid::v4(),
            'order_id' => $orderId,
            'subscription_id' => $subscriptionId,
            'customer_id' => $customerId,
            'gateway' => $payment->gateway,
            'gateway_payment_id' => $payment->gatewayPaymentId,
            'status' => $payment->status->value,
            'currency' => $payment->currency,
            'amount' => $payment->amount,
            'method' => $payment->method,
            'paid_at' => $payment->paidAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'raw' => (string) wp_json_encode($payment->raw),
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        return true;
    }
}
