<?php

declare(strict_types=1);

namespace ImaginaPay\Webhooks;

use ImaginaPay\Domain\Repositories\WebhookEventRepository;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Rest\AbstractController;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Receptor de webhooks. Política: validar firma → persistir (idempotencia
 * por UNIQUE gateway+event_id) → encolar en Action Scheduler → responder
 * 200 inmediato. El procesamiento pesado nunca ocurre en el request.
 */
final class WebhookController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly GatewayRegistry $gateways,
        private readonly WebhookEventRepository $events,
        private readonly Clock $clock,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::API_NAMESPACE, '/webhooks/(?P<gateway>[a-z_]+)', [
            'methods' => 'POST',
            'callback' => fn (\WP_REST_Request $request): \WP_REST_Response => $this->receive($request),
            'permission_callback' => '__return_true',
        ]);
    }

    public function receive(\WP_REST_Request $request): \WP_REST_Response
    {
        $gatewayId = (string) $request->get_param('gateway');

        if (!$this->gateways->has($gatewayId)) {
            return new \WP_REST_Response([
                'code' => 'impay_pasarela_desconocida',
                'message' => 'Pasarela no reconocida.',
            ], 404);
        }

        try {
            $event = $this->gateways->get($gatewayId)->verifyWebhook($request);
        } catch (\Throwable $exception) {
            $this->logger->warning('webhooks', 'Webhook rechazado por firma inválida.', [
                'gateway' => $gatewayId,
                'error' => $exception->getMessage(),
            ]);

            return new \WP_REST_Response([
                'code' => 'impay_firma_invalida',
                'message' => 'Firma de webhook inválida.',
            ], 401);
        }

        try {
            $eventRowId = $this->events->insertReceived(
                $event->gateway,
                $event->eventId,
                $event->topic,
                $event->payload,
                $this->clock->now(),
            );
        } catch (\Throwable $exception) {
            $this->logger->error('webhooks', 'No fue posible persistir el evento de webhook.', [
                'gateway' => $gatewayId,
                'error' => $exception->getMessage(),
            ]);

            // 500 para que la pasarela reintente la entrega.
            return new \WP_REST_Response([
                'code' => 'impay_error_interno',
                'message' => 'Error al registrar el evento.',
            ], 500);
        }

        if ($eventRowId === null) {
            // Evento duplicado: 200 para que la pasarela no reintente.
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('impay_process_webhook', [$eventRowId], 'imagina-pay');
        } else {
            // Sin Action Scheduler disponible: procesar en el mismo request
            // (degradación aceptable, el evento ya quedó persistido).
            do_action('impay_process_webhook', $eventRowId);
        }

        return new \WP_REST_Response(['received' => true], 200);
    }
}
