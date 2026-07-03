<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Admin;

use ImaginaPay\Domain\Services\MetricsService;
use ImaginaPay\Rest\AbstractController;
use ImaginaPay\Rest\Middleware\CapabilityMiddleware;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Support\Logger;

final class DashboardController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly MetricsService $metrics,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::API_NAMESPACE, '/admin/dashboard/metrics', [
            'methods' => 'GET',
            'callback' => fn (): \WP_REST_Response => $this->handle(fn (): array => $this->metrics->dashboard()),
            'permission_callback' => $this->permissions(
                new NonceMiddleware(),
                new CapabilityMiddleware('manage_impay'),
            ),
        ]);
    }
}
