<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Services;

use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Dunning (sección 6): la pasarela reintenta los cobros por su cuenta;
 * el plugin SOLO notifica al cliente (día 0/3/7) y suspende la provisión
 * en el día 7. Nunca reintenta cobros. Los emails se cuelgan del hook
 * impay_dunning_notice en la Fase 4.
 */
final class DunningService
{
    private const NOTICE_DAYS = [0, 3, 7];
    private const SUSPEND_DAY = 7;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Job diario impay_dunning_notices.
     */
    public function run(): void
    {
        $now = $this->clock->now();

        foreach ($this->subscriptions->findByStatus(SubscriptionStatus::PastDue) as $subscription) {
            $this->processSubscription($subscription, $now);
        }
    }

    private function processSubscription(Subscription $subscription, \DateTimeImmutable $now): void
    {
        $meta = $subscription->meta ?? [];
        $episode = is_array($meta['dunning'] ?? null) ? $meta['dunning'] : [];

        $since = $this->parseSince($episode) ?? $now;
        $notices = $this->parseNotices($episode);
        $daysInDunning = (int) $since->diff($now)->format('%a');

        $changed = $episode === [];

        foreach (self::NOTICE_DAYS as $day) {
            if ($daysInDunning < $day || in_array($day, $notices, true)) {
                continue;
            }

            $notices[] = $day;
            $changed = true;

            do_action('impay_dunning_notice', $subscription, $day);

            $this->logger->info('dunning', sprintf(
                'Aviso de pago vencido (día %d) para la suscripción %s.',
                $day,
                $subscription->uuid,
            ));

            if ($day >= self::SUSPEND_DAY) {
                do_action('impay_service_suspend', $subscription);

                $this->logger->warning('dunning', sprintf(
                    'Provisión suspendida por mora (día %d): suscripción %s.',
                    $day,
                    $subscription->uuid,
                ));
            }
        }

        if ($changed) {
            $meta['dunning'] = [
                'since' => $since->format('Y-m-d H:i:s'),
                'notices' => $notices,
            ];

            $this->subscriptions->updateMeta($subscription->id, $meta, $now);
        }
    }

    /**
     * @param array<mixed> $episode
     */
    private function parseSince(array $episode): ?\DateTimeImmutable
    {
        $since = $episode['since'] ?? null;

        if (!is_string($since) || $since === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $since, new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }

    /**
     * @param array<mixed> $episode
     * @return list<int>
     */
    private function parseNotices(array $episode): array
    {
        $notices = is_array($episode['notices'] ?? null) ? $episode['notices'] : [];

        return array_values(array_map('intval', array_filter($notices, 'is_numeric')));
    }
}
