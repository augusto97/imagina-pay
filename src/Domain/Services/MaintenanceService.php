<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Services;

use ImaginaPay\Core\Settings;
use ImaginaPay\Domain\Repositories\LogRepository;
use ImaginaPay\Domain\Repositories\WebhookEventRepository;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Job semanal impay_cleanup: retención de logs (90 días por defecto,
 * configurable) y de eventos de webhook (180 días).
 */
final class MaintenanceService
{
    private const DEFAULT_LOG_RETENTION_DAYS = 90;
    private const WEBHOOK_RETENTION_DAYS = 180;

    public function __construct(
        private readonly LogRepository $logs,
        private readonly WebhookEventRepository $webhookEvents,
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function cleanup(): void
    {
        $now = $this->clock->now();

        $retentionDays = $this->settings->get('log_retention_days', self::DEFAULT_LOG_RETENTION_DAYS);
        $retentionDays = is_numeric($retentionDays) ? max(1, (int) $retentionDays) : self::DEFAULT_LOG_RETENTION_DAYS;

        $deletedLogs = $this->logs->deleteOlderThan($now->sub(new \DateInterval('P' . $retentionDays . 'D')));
        $deletedEvents = $this->webhookEvents->deleteOlderThan(
            $now->sub(new \DateInterval('P' . self::WEBHOOK_RETENTION_DAYS . 'D')),
        );

        $this->logger->info('maintenance', sprintf(
            'Limpieza: %d logs y %d eventos de webhook eliminados.',
            $deletedLogs,
            $deletedEvents,
        ));
    }
}
