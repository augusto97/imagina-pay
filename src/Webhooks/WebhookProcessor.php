<?php

declare(strict_types=1);

namespace ImaginaPay\Webhooks;

use ImaginaPay\Domain\Enums\WebhookEventStatus;
use ImaginaPay\Domain\Repositories\WebhookEventRepository;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Gateways\WebhookEvent;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Procesa eventos de webhook encolados (job impay_process_webhook).
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly WebhookEventRepository $events,
        private readonly GatewayRegistry $gateways,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function process(int $eventRowId): void
    {
        $row = $this->events->find($eventRowId);

        if ($row === null) {
            $this->logger->warning('webhooks', sprintf('Evento #%d no existe: omitido.', $eventRowId));

            return;
        }

        if ((string) ($row['status'] ?? '') === WebhookEventStatus::Processed->value) {
            return; // Idempotencia: job duplicado sobre evento ya procesado.
        }

        $payload = json_decode(is_string($row['payload'] ?? null) ? $row['payload'] : '', true);

        /** @var array<string, mixed> $payload */
        $payload = is_array($payload) ? $payload : [];

        $event = new WebhookEvent(
            (string) $row['gateway'],
            (string) $row['event_id'],
            (string) ($row['topic'] ?? ''),
            $payload,
        );

        try {
            $this->gateways->get($event->gateway)->handleWebhook($event);
            $this->events->markProcessed($eventRowId, $this->clock->now());
        } catch (\Throwable $exception) {
            $this->events->markFailed($eventRowId, $exception->getMessage());

            $this->logger->error('webhooks', sprintf('Fallo procesando evento #%d.', $eventRowId), [
                'gateway' => $event->gateway,
                'topic' => $event->topic,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
