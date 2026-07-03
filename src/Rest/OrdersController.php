<?php

declare(strict_types=1);

namespace ImaginaPay\Rest;

use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Exceptions\NotFoundException;
use ImaginaPay\Rest\Middleware\RateLimitMiddleware;
use ImaginaPay\Support\Logger;

/**
 * GET /orders/{uuid}/status — polling de la página /gracias (cada 3 s,
 * máx. 2 min). Público pero solo devuelve {status, product_name}:
 * nada sensible.
 */
final class OrdersController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly OrderRepository $orders,
        private readonly ProductRepository $products,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            self::API_NAMESPACE,
            '/orders/(?P<uuid>[0-9a-fA-F-]{36})/status',
            [
                'methods' => 'GET',
                'callback' => fn (\WP_REST_Request $request): \WP_REST_Response => $this->handle(
                    fn (): array => $this->status((string) $request->get_param('uuid')),
                ),
                // Margen amplio: el polling legítimo hace ~40 requests en 2 min.
                'permission_callback' => $this->permissions(
                    new RateLimitMiddleware('order_status', 120, 600),
                ),
            ],
        );
    }

    /**
     * @return array{status: string, product_name: string}
     */
    private function status(string $uuid): array
    {
        $order = $this->orders->findByUuid(strtolower($uuid));

        if ($order === null) {
            throw new NotFoundException('Pedido no encontrado.');
        }

        $product = $this->products->find($order->productId);

        return [
            'status' => $order->status->value,
            'product_name' => $product?->name ?? '',
        ];
    }
}
