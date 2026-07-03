<?php

declare(strict_types=1);

namespace ImaginaPay\Rest;

use ImaginaPay\Rest\Middleware\CapabilityMiddleware;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Endpoint de diagnóstico para el admin: confirma que la API base,
 * el nonce y las capabilities funcionan.
 */
final class HealthController extends AbstractController
{
    public function __construct(Logger $logger, private readonly Clock $clock)
    {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::API_NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $request): \WP_REST_Response => $this->handle(
                fn (): array => [
                    'plugin' => 'imagina-pay',
                    'version' => defined('IMPAY_VERSION') ? (string) constant('IMPAY_VERSION') : 'dev',
                    'time_utc' => $this->clock->now()->format(\DateTimeInterface::ATOM),
                ],
            ),
            'permission_callback' => $this->permissions(
                new NonceMiddleware(),
                new CapabilityMiddleware('manage_impay'),
            ),
        ]);
    }
}
