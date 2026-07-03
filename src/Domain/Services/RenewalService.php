<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Services;

use ImaginaPay\Core\Container;
use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\PaymentLink;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\OrderKind;
use ImaginaPay\Domain\Enums\OrderStatus;
use ImaginaPay\Domain\Enums\PaymentLinkStatus;
use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Domain\Enums\ProductType;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Exceptions\InvalidTransitionException;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Gateways\PaymentLinkRequest;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;
use ImaginaPay\Support\Money;
use ImaginaPay\Support\Uuid;

/**
 * Ciclo de vida annual_hybrid (sección 8.4): suscripción lógica al pagar,
 * recordatorios con link de pago a 30/15/5/0 días del vencimiento y
 * aplicación del link pagado (+1 periodo).
 */
class RenewalService
{
    private const REMINDER_MARKS = [30, 15, 5, 0];

    public function __construct(
        // El registry se resuelve perezosamente: los webhook handlers de las
        // pasarelas dependen de este servicio y el registry depende de ellas.
        private readonly Container $container,
        private readonly SubscriptionRepository $subscriptions,
        private readonly OrderRepository $orders,
        private readonly PaymentLinkRepository $paymentLinks,
        private readonly PriceRepository $prices,
        private readonly ProductRepository $products,
        private readonly CustomerRepository $customers,
        private readonly PaymentService $payments,
        private readonly SubscriptionStateMachine $stateMachine,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Hook impay_order_paid: si el order pagado es de un producto
     * annual_hybrid, crea su suscripción lógica activa (+1 periodo).
     */
    public function handleOrderPaid(Order $order): void
    {
        if ($order->kind !== OrderKind::Purchase) {
            return;
        }

        // Releer: el order puede haber sido vinculado por otro evento (idempotencia).
        $fresh = $this->orders->find($order->id);

        if ($fresh === null || $fresh->subscriptionId !== null || $fresh->status !== OrderStatus::Paid) {
            return;
        }

        $product = $this->products->find($order->productId);

        if ($product === null || $product->type !== ProductType::AnnualHybrid) {
            return;
        }

        $price = $this->prices->find($order->priceId);
        $now = $this->clock->now();
        $periodEnd = $now->add($price !== null && $price->interval->isRecurring()
            ? $price->interval->period()
            : new \DateInterval('P1Y'));

        $subscriptionId = $this->subscriptions->insert([
            'uuid' => Uuid::v4(),
            'customer_id' => $order->customerId,
            'product_id' => $order->productId,
            'price_id' => $order->priceId,
            'gateway' => $order->gateway,
            'gateway_sub_id' => null, // Suscripción lógica: el cobro no es automático.
            'status' => SubscriptionStatus::Pending->value,
            'cancel_at_period_end' => 0,
            'failed_payments' => 0,
            'meta' => (string) wp_json_encode(['initial_order_uuid' => $order->uuid]),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $this->subscriptions->extendPeriod($subscriptionId, $now, $periodEnd, $now);
        $this->orders->linkSubscription($order->id, $subscriptionId, $now);

        $subscription = $this->subscriptions->find($subscriptionId);

        if ($subscription === null) {
            return;
        }

        // pending → active dispara impay_subscription_active → provisión.
        $this->stateMachine->transition($subscription, SubscriptionStatus::Active, [
            'source' => 'annual_hybrid',
            'order_uuid' => $order->uuid,
        ]);

        $this->logger->info('renewals', sprintf(
            'Suscripción lógica creada para el order %s (vence %s).',
            $order->uuid,
            $periodEnd->format('Y-m-d'),
        ));
    }

    /**
     * Job diario impay_renewal_reminders: para suscripciones lógicas que
     * vencen en ≤30 días, garantiza un link de pago abierto y dispara el
     * recordatorio en las marcas 30/15/5/0 (el email se cuelga del hook
     * en la Fase 4).
     */
    public function sendReminders(): void
    {
        $now = $this->clock->now();
        $horizon = $now->add(new \DateInterval('P30D'));

        foreach ($this->subscriptions->findLogicalExpiring($horizon) as $subscription) {
            if ($subscription->currentPeriodEnd === null || $subscription->cancelAtPeriodEnd) {
                continue;
            }

            $daysLeft = $this->fullDaysUntil($now, $subscription->currentPeriodEnd);
            $mark = $this->dueMark($daysLeft);

            if ($mark === null || $this->alreadyNotified($subscription, $mark)) {
                continue;
            }

            try {
                $link = $this->ensureOpenLink($subscription);
            } catch (\Throwable $exception) {
                $this->logger->error('renewals', sprintf(
                    'No fue posible generar el link de renovación de la suscripción %s: %s',
                    $subscription->uuid,
                    $exception->getMessage(),
                ));

                continue;
            }

            $this->recordNotice($subscription, $mark);

            do_action('impay_renewal_reminder', $subscription, $link, $mark);

            $this->logger->info('renewals', sprintf(
                'Recordatorio de renovación (%d días) enviado para la suscripción %s.',
                $mark,
                $subscription->uuid,
            ));
        }
    }

    /**
     * Un link de pago fue pagado: order kind=renewal, periodo +1 y
     * reactivación si estaba vencida o en mora.
     */
    public function applyPaidLink(PaymentLink $link, GatewayPayment $payment): void
    {
        if ($link->status !== PaymentLinkStatus::Open) {
            return; // Idempotencia: link ya pagado/anulado.
        }

        if ($payment->status !== PaymentStatus::Approved) {
            $this->logger->info('renewals', sprintf(
                'Pago %s del link %s en estado %s: sin efecto.',
                $payment->gatewayPaymentId,
                $link->uuid,
                $payment->status->value,
            ));

            return;
        }

        $subscription = $link->subscriptionId !== null
            ? $this->subscriptions->find($link->subscriptionId)
            : null;

        $price = $link->priceId > 0 ? $this->prices->find($link->priceId) : null;
        $now = $this->clock->now();
        $orderUuid = Uuid::v4();

        $orderId = $this->orders->insert([
            'uuid' => $orderUuid,
            'customer_id' => $link->customerId,
            'product_id' => $price?->productId ?? ($subscription?->productId ?? 0),
            'price_id' => $link->priceId,
            'subscription_id' => $subscription?->id,
            'kind' => ($subscription !== null ? OrderKind::Renewal : OrderKind::Purchase)->value,
            'status' => OrderStatus::Pending->value,
            'currency' => $payment->currency,
            'amount' => $payment->amount,
            'gateway' => $payment->gateway,
            'gateway_ref' => $link->gatewayRef,
            'external_reference' => $orderUuid,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $order = $this->orders->find($orderId);

        if ($order !== null) {
            // Registra el pago, marca el order como paid y dispara impay_order_paid.
            $this->payments->applyOrderPayment($order, $payment);
        }

        $this->paymentLinks->updateStatus($link->id, PaymentLinkStatus::Paid, $orderId);

        if ($subscription !== null) {
            $interval = $price !== null && $price->interval->isRecurring()
                ? $price->interval->period()
                : new \DateInterval('P1Y');

            // Consistente con PaymentService: si venció, el nuevo periodo
            // corre desde hoy (el cliente no paga tiempo muerto).
            $base = $subscription->currentPeriodEnd !== null && $subscription->currentPeriodEnd > $now
                ? $subscription->currentPeriodEnd
                : $now;

            $this->subscriptions->extendPeriod($subscription->id, $base, $base->add($interval), $now);

            try {
                $this->stateMachine->transition($subscription, SubscriptionStatus::Active, [
                    'source' => 'renewal_link',
                    'payment_link' => $link->uuid,
                ]);
            } catch (InvalidTransitionException $exception) {
                $this->logger->warning('renewals', $exception->getMessage(), ['link' => $link->uuid]);
            }

            do_action('impay_renewal_paid', $subscription, $order);
        }

        $this->logger->info('renewals', sprintf('Link de pago %s aplicado (order %s).', $link->uuid, $orderUuid));
    }

    /**
     * Devuelve el link abierto de la suscripción o crea uno nuevo en su pasarela.
     */
    private function ensureOpenLink(Subscription $subscription): PaymentLink
    {
        $existing = $this->paymentLinks->findOpenBySubscription($subscription->id);

        if ($existing !== null) {
            return $existing;
        }

        $customer = $this->customers->find($subscription->customerId);
        $price = $this->prices->find($subscription->priceId);
        $product = $this->products->find($subscription->productId);

        if ($customer === null || $price === null || $product === null) {
            throw new \RuntimeException('La suscripción no tiene cliente, precio o producto válidos.');
        }

        $gateway = $this->container->get(GatewayRegistry::class)->get($subscription->gateway);

        $description = sprintf(
            'Renovación %s — vence el %s',
            $product->name,
            $subscription->currentPeriodEnd?->format('Y-m-d') ?? '',
        );

        return $gateway->createPaymentLink(new PaymentLinkRequest(
            customer: $customer,
            amount: Money::of($price->amount, $price->currency),
            description: $description,
            subscriptionId: $subscription->id,
            priceId: $price->id,
        ));
    }

    /**
     * Días completos hasta el vencimiento (0 = vence hoy o ya venció).
     */
    private function fullDaysUntil(\DateTimeImmutable $now, \DateTimeImmutable $end): int
    {
        if ($end <= $now) {
            return 0;
        }

        return (int) $now->diff($end)->format('%a');
    }

    /**
     * Marca de recordatorio vigente: la menor marca ≥ días restantes
     * (daysLeft 12 → marca 15). Así un job caído no salta recordatorios.
     * Más de 30 días → ninguna.
     */
    private function dueMark(int $daysLeft): ?int
    {
        if ($daysLeft > max(self::REMINDER_MARKS)) {
            return null;
        }

        $candidates = array_filter(self::REMINDER_MARKS, static fn (int $mark): bool => $mark >= $daysLeft);

        return $candidates === [] ? null : min($candidates);
    }

    private function alreadyNotified(Subscription $subscription, int $mark): bool
    {
        $notices = $this->noticesFor($subscription);

        return in_array($mark, $notices, true);
    }

    private function recordNotice(Subscription $subscription, int $mark): void
    {
        $meta = $subscription->meta ?? [];
        $periodKey = $subscription->currentPeriodEnd?->format('Y-m-d') ?? 'na';

        $all = is_array($meta['renewal_notices'] ?? null) ? $meta['renewal_notices'] : [];
        $current = is_array($all[$periodKey] ?? null) ? $all[$periodKey] : [];
        $current[] = $mark;

        // Solo se conserva el registro del periodo vigente.
        $meta['renewal_notices'] = [$periodKey => array_values(array_unique($current))];

        $this->subscriptions->updateMeta($subscription->id, $meta, $this->clock->now());
    }

    /**
     * @return list<int>
     */
    private function noticesFor(Subscription $subscription): array
    {
        $meta = $subscription->meta ?? [];
        $periodKey = $subscription->currentPeriodEnd?->format('Y-m-d') ?? 'na';
        $all = is_array($meta['renewal_notices'] ?? null) ? $meta['renewal_notices'] : [];
        $current = is_array($all[$periodKey] ?? null) ? $all[$periodKey] : [];

        return array_values(array_map('intval', array_filter($current, 'is_numeric')));
    }
}
