<?php

declare(strict_types=1);

namespace ImaginaPay\Rest;

use ImaginaPay\Domain\Enums\TaxIdType;
use ImaginaPay\Domain\Services\CheckoutService;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Rest\Middleware\RateLimitMiddleware;
use ImaginaPay\Support\Logger;

/**
 * POST /checkout (público). Anti-abuso: honeypot + rate limit por IP
 * (10 req/10 min) + nonce. Nunca $_POST directo: body JSON validado
 * contra esquema.
 */
final class CheckoutController extends AbstractController
{
    private const HONEYPOT_FIELD = 'website';

    public function __construct(
        Logger $logger,
        private readonly CheckoutService $checkout,
        private readonly GatewayRegistry $gateways,
        private readonly Validator $validator,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::API_NAMESPACE, '/checkout', [
            'methods' => 'POST',
            'callback' => fn (\WP_REST_Request $request): \WP_REST_Response => $this->handle(
                fn (): \WP_REST_Response => $this->start($request),
            ),
            'permission_callback' => $this->permissions(
                new RateLimitMiddleware('checkout', 10, 600),
                new NonceMiddleware(),
            ),
        ]);
    }

    private function start(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];

        // Honeypot: un bot que llena el campo oculto recibe una respuesta
        // neutra y no se procesa nada.
        if (!empty($body[self::HONEYPOT_FIELD])) {
            $this->logger->warning('checkout', 'Checkout bloqueado por honeypot.');

            return new \WP_REST_Response(['redirect_url' => home_url('/'), 'order' => ''], 200);
        }

        /** @var array<string, mixed> $body */
        $input = $this->validator->validate($body, [
            'product' => ['required' => true, 'type' => 'uuid'],
            'price' => ['required' => true, 'type' => 'uuid'],
            'gateway' => ['required' => true, 'type' => 'string', 'enum' => array_keys($this->gateways->all())],
            'full_name' => ['required' => true, 'type' => 'string', 'max' => 190],
            'email' => ['required' => true, 'type' => 'email', 'max' => 190],
            'company' => ['type' => 'string', 'max' => 190],
            'tax_id_type' => ['type' => 'string', 'enum' => array_column(TaxIdType::cases(), 'value')],
            'tax_id' => ['type' => 'string', 'max' => 40],
            'country' => ['type' => 'string', 'max' => 2],
            'phone' => ['type' => 'string', 'max' => 40],
            'custom_fields' => ['type' => 'array'],
            'payment_token' => ['type' => 'string', 'max' => 200],
            'payment_method_type' => ['type' => 'string', 'enum' => ['CARD', 'NEQUI']],
        ]);

        return new \WP_REST_Response($this->checkout->start($input), 200);
    }
}
