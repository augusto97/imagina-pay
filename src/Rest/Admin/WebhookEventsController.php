<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Admin;

use ImaginaPay\Domain\Repositories\LogRepository;
use ImaginaPay\Domain\Repositories\WebhookEventRepository;
use ImaginaPay\Exceptions\NotFoundException;
use ImaginaPay\Rest\AbstractController;
use ImaginaPay\Rest\Middleware\CapabilityMiddleware;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Support\Logger;
use ImaginaPay\Webhooks\WebhookProcessor;

/**
 * Webhooks & Logs (admin): tabla de eventos con retry e indicador de
 * salud, y tab de logs estructurados.
 */
final class WebhookEventsController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly WebhookEventRepository $events,
        private readonly LogRepository $logs,
        private readonly WebhookProcessor $processor,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $permissions = $this->permissions(new NonceMiddleware(), new CapabilityMiddleware('manage_impay'));

        register_rest_route(self::API_NAMESPACE, '/admin/webhook-events', [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->index($r)),
            'permission_callback' => $permissions,
        ]);

        register_rest_route(self::API_NAMESPACE, '/admin/webhook-events/(?P<id>\d+)/retry', [
            'methods' => 'POST',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->retry($r)),
            'permission_callback' => $permissions,
        ]);

        register_rest_route(self::API_NAMESPACE, '/admin/logs', [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->logsIndex($r)),
            'permission_callback' => $permissions,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function index(\WP_REST_Request $request): array
    {
        $result = $this->events->list(
            page: max(1, (int) $request->get_param('page')),
            perPage: min(100, max(1, (int) ($request->get_param('per_page') ?: 20))),
            status: is_string($request->get_param('status')) ? $request->get_param('status') : null,
            gateway: is_string($request->get_param('gateway')) ? $request->get_param('gateway') : null,
        );

        return [
            'items' => array_map(
                static fn (array $row): array => Presenter::webhookEvent($row),
                $result['items'],
            ),
            'total' => $result['total'],
            'health' => $this->events->lastReceivedByGateway(),
        ];
    }

    /**
     * Reprocesa un evento (webhook perdido o fallido).
     *
     * @return array<string, mixed>
     */
    private function retry(\WP_REST_Request $request): array
    {
        $eventId = (int) $request->get_param('id');
        $existing = $this->events->find($eventId);

        if ($existing === null) {
            throw new NotFoundException('Evento no encontrado.');
        }

        $this->processor->process($eventId);

        $this->logger->info('admin', sprintf('Retry manual del evento de webhook #%d.', $eventId));

        $fresh = $this->events->find($eventId);

        return ['event' => Presenter::webhookEvent(is_array($fresh) ? $fresh : $existing)];
    }

    /**
     * @return array<string, mixed>
     */
    private function logsIndex(\WP_REST_Request $request): array
    {
        $result = $this->logs->list(
            page: max(1, (int) $request->get_param('page')),
            perPage: min(200, max(1, (int) ($request->get_param('per_page') ?: 50))),
            level: is_string($request->get_param('level')) ? $request->get_param('level') : null,
            channel: is_string($request->get_param('channel')) ? $request->get_param('channel') : null,
        );

        return $result;
    }
}
