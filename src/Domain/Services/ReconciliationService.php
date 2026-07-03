<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Services;

use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Red de seguridad (sección 7): coteja el estado local contra la API de
 * la pasarela para corregir webhooks perdidos, y expira lo que quedó
 * colgado (orders pendientes >48h, links vencidos, anuales +7 días).
 */
final class ReconciliationService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly OrderRepository $orders,
        private readonly PaymentLinkRepository $paymentLinks,
        private readonly GatewayRegistry $gateways,
        private readonly SubscriptionStateMachine $stateMachine,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Job diario impay_reconcile (03:00).
     */
    public function reconcile(): void
    {
        foreach ($this->subscriptions->findForReconciliation() as $subscription) {
            if ($subscription->gatewaySubId === null) {
                continue;
            }

            try {
                $remote = $this->gateways
                    ->get($subscription->gateway)
                    ->fetchSubscription($subscription->gatewaySubId);
            } catch (\Throwable $exception) {
                $this->logger->error('reconciliation', sprintf(
                    'No fue posible consultar la suscripción %s en %s: %s',
                    $subscription->uuid,
                    $subscription->gateway,
                    $exception->getMessage(),
                ));

                continue;
            }

            $remoteStatus = is_string($remote['status'] ?? null) ? $remote['status'] : '';
            $target = $this->mapRemoteStatus($remoteStatus);

            if ($target === null || $target === $subscription->status) {
                continue;
            }

            try {
                $this->stateMachine->transition($subscription, $target, [
                    'source' => 'reconciliation',
                    'remote_status' => $remoteStatus,
                ]);

                $this->logger->warning('reconciliation', sprintf(
                    'Divergencia corregida en la suscripción %s: local %s → remoto %s.',
                    $subscription->uuid,
                    $subscription->status->value,
                    $target->value,
                ));
            } catch (\Throwable $exception) {
                $this->logger->error('reconciliation', sprintf(
                    'No fue posible corregir la suscripción %s: %s',
                    $subscription->uuid,
                    $exception->getMessage(),
                ));
            }
        }
    }

    /**
     * Job diario impay_expire_stale.
     */
    public function expireStale(): void
    {
        $now = $this->clock->now();

        $expiredOrders = $this->orders->expireStale($now->modify('-48 hours'), $now);

        if ($expiredOrders > 0) {
            $this->logger->info('reconciliation', sprintf('%d orders pendientes >48h expirados.', $expiredOrders));
        }

        $expiredLinks = $this->paymentLinks->expireStale($now);

        if ($expiredLinks > 0) {
            $this->logger->info('reconciliation', sprintf('%d links de pago vencidos expirados.', $expiredLinks));
        }

        // Anuales lógicos con +7 días vencidos sin pago → expired + suspensión.
        foreach ($this->subscriptions->findLogicalExpiring($now->modify('-7 days')) as $subscription) {
            try {
                $updated = $this->stateMachine->transition($subscription, SubscriptionStatus::Expired, [
                    'source' => 'expire_stale',
                ]);

                do_action('impay_service_suspend', $updated);

                $this->logger->warning('reconciliation', sprintf(
                    'Suscripción anual %s expirada por falta de pago (+7 días).',
                    $subscription->uuid,
                ));
            } catch (\Throwable $exception) {
                $this->logger->error('reconciliation', $exception->getMessage(), [
                    'subscription' => $subscription->uuid,
                ]);
            }
        }
    }

    private function mapRemoteStatus(string $remoteStatus): ?SubscriptionStatus
    {
        return match (strtolower($remoteStatus)) {
            // Mercado Pago: authorized/paused/cancelled · PayPal: ACTIVE/SUSPENDED/CANCELLED/EXPIRED.
            'authorized', 'active' => SubscriptionStatus::Active,
            'paused', 'suspended' => SubscriptionStatus::Paused,
            'cancelled' => SubscriptionStatus::Cancelled,
            'expired' => SubscriptionStatus::Expired,
            default => null, // pending / approval_pending / desconocidos: sin corrección.
        };
    }
}
