<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Services;

use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Exceptions\InvalidTransitionException;
use ImaginaPay\Gateways\GatewayMode;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Gateways\TokenizedGatewayInterface;
use ImaginaPay\Gateways\Wompi\WompiGateway;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Motor de cobros para gateways modo Tokenized (Wompi): el plugin agenda
 * y dispara cada cobro recurrente contra la fuente de pago guardada.
 *
 * Job diario impay_billing_run. Reintentos propios: fallo → +24h → +72h
 * (máx. 3 intentos por periodo); el estado (past_due, cancelación al 3er
 * fallo, dunning) lo maneja PaymentService con los MISMOS eventos de
 * dominio que un webhook de MP. Idempotencia: la referencia del cobro es
 * determinista por {subscription_uuid}:{period_end} y el intento se
 * reclama en el meta ANTES de llamar a la pasarela — un job duplicado
 * jamás cobra dos veces.
 */
final class BillingEngine
{
    private const MAX_ATTEMPTS = 3;

    /** Espera antes del reintento N (horas desde el primer intento). */
    private const RETRY_OFFSET_HOURS = [2 => 24, 3 => 72];

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PriceRepository $prices,
        private readonly GatewayRegistry $gateways,
        private readonly SubscriptionStateMachine $stateMachine,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function run(): int
    {
        $tokenizedIds = [];

        foreach ($this->gateways->all() as $gateway) {
            if ($gateway->mode() === GatewayMode::Tokenized && $gateway instanceof TokenizedGatewayInterface) {
                $tokenizedIds[] = $gateway->id();
            }
        }

        if ($tokenizedIds === []) {
            return 0;
        }

        $now = $this->clock->now();
        $charged = 0;

        foreach ($this->subscriptions->findDueForBilling($now) as $subscription) {
            // Solo gateways tokenized: los hosted (MP/PayPal) cobran solos,
            // y las suscripciones lógicas annual_hybrid renuevan por link.
            if (!in_array($subscription->gateway, $tokenizedIds, true)) {
                continue;
            }

            if ($this->processDue($subscription, $now)) {
                ++$charged;
            }
        }

        if ($charged > 0) {
            $this->logger->info('billing', sprintf('BillingEngine: %d cobro(s) disparado(s).', $charged));
        }

        return $charged;
    }

    /**
     * @return bool true si se disparó un cobro.
     */
    private function processDue(Subscription $subscription, \DateTimeImmutable $now): bool
    {
        // Cancelación al fin de periodo: el periodo terminó → expira sin cobrar.
        if ($subscription->cancelAtPeriodEnd) {
            try {
                $this->stateMachine->transition($subscription, SubscriptionStatus::Expired, [
                    'source' => 'billing_engine',
                    'reason' => 'cancel_at_period_end',
                ]);
            } catch (InvalidTransitionException $exception) {
                $this->logger->warning('billing', $exception->getMessage(), ['subscription' => $subscription->uuid]);
            }

            return false;
        }

        $sourceId = is_string($subscription->meta['payment_source_id'] ?? null)
            ? $subscription->meta['payment_source_id']
            : '';

        if ($sourceId === '' || $subscription->currentPeriodEnd === null) {
            $this->logger->error('billing', sprintf(
                'Suscripción %s sin fuente de pago o periodo: no se puede cobrar.',
                $subscription->uuid,
            ));

            return false;
        }

        $price = $this->prices->find($subscription->priceId);

        if ($price === null) {
            $this->logger->error('billing', sprintf('Suscripción %s sin precio asociado.', $subscription->uuid));

            return false;
        }

        $periodKey = $subscription->currentPeriodEnd->format('Ymd');
        $billing = is_array($subscription->meta['billing'] ?? null) ? $subscription->meta['billing'] : [];

        // Periodo nuevo → contador en cero.
        if (($billing['period'] ?? '') !== $periodKey) {
            $billing = ['period' => $periodKey, 'attempts' => 0];
        }

        $attempts = is_int($billing['attempts'] ?? null) ? $billing['attempts'] : 0;

        if ($attempts >= self::MAX_ATTEMPTS) {
            return false; // El dunning (past_due/cancelación) ya está en curso.
        }

        // Pausa entre reintentos: intento 2 a las 24h, intento 3 a las 72h
        // del primero. (El primer intento sale apenas vence el periodo.)
        if ($attempts > 0) {
            $firstAttempt = is_string($billing['first_attempt'] ?? null)
                ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $billing['first_attempt'], new \DateTimeZone('UTC'))
                : false;

            $offsetHours = self::RETRY_OFFSET_HOURS[$attempts + 1];

            if ($firstAttempt !== false && $now < $firstAttempt->modify(sprintf('+%d hours', $offsetHours))) {
                return false; // Aún no toca el reintento.
            }
        }

        // Referencia determinista por periodo + intento: idempotencia de salida.
        $reference = sprintf('%s%s-%s-a%d', WompiGateway::RENEWAL_PREFIX, $subscription->uuid, $periodKey, $attempts + 1);

        // Reclamar el intento ANTES de llamar a la pasarela: si el job se
        // duplica, el segundo ve el contador incrementado y no cobra.
        $billing['attempts'] = $attempts + 1;
        $billing['first_attempt'] = $billing['first_attempt'] ?? $now->format('Y-m-d H:i:s');
        $billing['last_attempt'] = $now->format('Y-m-d H:i:s');
        $billing['last_reference'] = $reference;

        $meta = $subscription->meta ?? [];
        $meta['billing'] = $billing;
        $this->subscriptions->updateMeta($subscription->id, $meta, $now);

        $gateway = $this->gateways->get($subscription->gateway);

        if (!$gateway instanceof TokenizedGatewayInterface) {
            return false;
        }

        try {
            $transactionId = $gateway->chargeStoredSource($subscription, $price, $sourceId, $reference);

            $this->logger->info('billing', sprintf(
                'Cobro %s disparado para la suscripción %s (intento %d, transacción %s).',
                $reference,
                $subscription->uuid,
                $attempts + 1,
                $transactionId,
            ));

            return true;
        } catch (\Throwable $exception) {
            // El intento quedó consumido (no se repite hoy); el resultado
            // del cobro fallido llegará por webhook si la pasarela lo creó.
            $this->logger->error('billing', sprintf(
                'Error al cobrar la suscripción %s: %s',
                $subscription->uuid,
                $exception->getMessage(),
            ));

            return false;
        }
    }
}
