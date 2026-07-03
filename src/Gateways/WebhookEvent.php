<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways;

/**
 * Evento de webhook ya verificado (firma válida), listo para persistir
 * en impay_webhook_events y encolar su procesamiento.
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $gateway,
        public readonly string $eventId,
        public readonly string $topic,
        public readonly array $payload,
    ) {
    }
}
