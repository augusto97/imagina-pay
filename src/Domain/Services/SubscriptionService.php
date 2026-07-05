<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Services;

use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Gateways\CheckoutSession;
use ImaginaPay\Gateways\GatewayMode;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Orquestación de suscripciones. El flujo se ramifica SIEMPRE por
 * GatewayMode, nunca por el nombre de la pasarela: así añadir Wompi
 * (Tokenized, Fase 8) no toca este core.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionStateMachine $stateMachine,
        private readonly GatewayRegistry $gateways,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Inicia el alta de la suscripción en la pasarela y devuelve la sesión
     * de checkout (URL de redirección para que el cliente autorice).
     */
    public function startGatewaySubscription(Subscription $subscription, Price $price): CheckoutSession
    {
        $gateway = $this->gateways->get($subscription->gateway);

        return match ($gateway->mode()) {
            // Hosted: la pasarela crea su objeto de suscripción y redirige.
            // Tokenized (Wompi): el gateway crea la fuente de pago con el
            // token del navegador (meta pending_token) y dispara el primer
            // cobro; los siguientes los agenda el BillingEngine.
            GatewayMode::HostedSubscription, GatewayMode::Tokenized => $gateway->createSubscription($subscription, $price),
        };
    }

    /**
     * Activa la suscripción (webhook authorized/ACTIVATED de la pasarela).
     *
     * @param array<string, mixed> $context
     */
    public function activate(Subscription $subscription, array $context = []): Subscription
    {
        return $this->stateMachine->transition($subscription, SubscriptionStatus::Active, $context);
    }

    /**
     * Cancelación. Por defecto cancel_at_period_end: el servicio sigue
     * activo hasta current_period_end aunque la pasarela deje de cobrar
     * de inmediato (equivalente en MP/PayPal).
     *
     * @param array<string, mixed> $context
     */
    public function cancel(Subscription $subscription, bool $atPeriodEnd = true, array $context = []): Subscription
    {
        if ($subscription->gatewaySubId !== null) {
            $gateway = $this->gateways->get($subscription->gateway);

            if ($gateway->mode() === GatewayMode::HostedSubscription) {
                // El motor de cobro vive en la pasarela: hay que detenerlo allá.
                $gateway->cancelSubscription($subscription);
            }
            // Tokenized: no hay nada que cancelar en la pasarela; el
            // BillingEngine (Fase 8) deja de cobrar al leer el estado local.
        }

        if ($atPeriodEnd && $subscription->status === SubscriptionStatus::Active) {
            $now = $this->clock->now();
            $this->subscriptions->markCancelAtPeriodEnd($subscription->id, true, $now);

            $this->logger->info('subscriptions', sprintf(
                'Suscripción %s marcada para cancelar al fin del periodo.',
                $subscription->uuid,
            ), $context);

            return $subscription->withCancelAtPeriodEnd(true, $now);
        }

        return $this->stateMachine->transition($subscription, SubscriptionStatus::Cancelled, $context);
    }
}
