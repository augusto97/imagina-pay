<?php

declare(strict_types=1);

namespace ImaginaPay\Jobs;

use ImaginaPay\Core\Container;
use ImaginaPay\Webhooks\WebhookProcessor;

/**
 * Registra los hooks de jobs. Los hooks solo delegan: la resolución del
 * servicio ocurre de forma perezosa dentro del callback (cero costo en
 * requests que no ejecutan jobs).
 */
final class Scheduler
{
    public function __construct(private readonly Container $container)
    {
    }

    public function register(): void
    {
        add_action('impay_process_webhook', function (int|string $eventRowId): void {
            $this->container->get(WebhookProcessor::class)->process((int) $eventRowId);
        });
    }
}
