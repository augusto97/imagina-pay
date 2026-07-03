<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\StateMachine;

use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Exceptions\InvalidTransitionException;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Máquina de estados de suscripción (sección 6 del spec).
 *
 * Toda transición pasa por transition(). Las inválidas lanzan excepción
 * y quedan en impay_logs. Cada transición válida dispara
 * do_action("impay_subscription_{$to}", $subscription, $context) para
 * colgar la provisión (activar VPS, licencias, suspender, etc.).
 */
final class SubscriptionStateMachine
{
    /**
     * Mapa de transiciones permitidas. "expired → active" cubre la
     * reactivación de un annual_hybrid vencido que paga su link tarde.
     * "cancelled" es terminal.
     */
    private const TRANSITIONS = [
        'pending' => ['active', 'cancelled'],
        'active' => ['past_due', 'paused', 'cancelled', 'expired'],
        'past_due' => ['active', 'cancelled'],
        'paused' => ['active', 'cancelled'],
        'expired' => ['active'],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly Logger $logger,
        private readonly Clock $clock,
    ) {
    }

    public function canTransition(SubscriptionStatus $from, SubscriptionStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value], true);
    }

    /**
     * Ejecuta la transición: valida, persiste, loguea y dispara el hook.
     * Una transición al estado actual es un no-op idempotente (los webhooks
     * de pasarela se repiten) y NO dispara hooks.
     *
     * @param array<string, mixed> $context
     * @throws InvalidTransitionException
     */
    public function transition(Subscription $subscription, SubscriptionStatus $to, array $context = []): Subscription
    {
        if ($subscription->status === $to) {
            return $subscription;
        }

        if (!$this->canTransition($subscription->status, $to)) {
            $this->logger->error('state_machine', 'Transición de suscripción inválida.', [
                'subscription_uuid' => $subscription->uuid,
                'from' => $subscription->status->value,
                'to' => $to->value,
                'context' => $context,
            ]);

            throw InvalidTransitionException::between($subscription->status, $to);
        }

        $now = $this->clock->now();
        $cancelledAt = $to === SubscriptionStatus::Cancelled ? $now : null;

        $this->subscriptions->updateStatus($subscription->id, $to, $now, $cancelledAt);
        $updated = $subscription->withStatus($to, $now, $cancelledAt);

        $this->logger->info('state_machine', sprintf(
            'Suscripción %s: %s → %s',
            $subscription->uuid,
            $subscription->status->value,
            $to->value,
        ), $context);

        do_action('impay_subscription_' . $to->value, $updated, $context);

        return $updated;
    }
}
