<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Portal;

use ImaginaPay\Exceptions\ImaginaPayException;
use ImaginaPay\Rest\AbstractController;
use ImaginaPay\Rest\Middleware\RateLimitMiddleware;
use ImaginaPay\Rest\Validator;
use ImaginaPay\Support\Logger;

/**
 * POST /portal/login — login propio estilizado del portal vía wp_signon.
 * Rate limit agresivo (anti fuerza bruta).
 */
final class LoginController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly Validator $validator,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::API_NAMESPACE, '/portal/login', [
            'methods' => 'POST',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->login($r)),
            'permission_callback' => $this->permissions(
                new RateLimitMiddleware('portal_login', 5, 600),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function login(\WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        /** @var array<string, mixed> $body */
        $body = is_array($body) ? $body : [];

        $input = $this->validator->validate($body, [
            'email' => ['required' => true, 'type' => 'email', 'max' => 190],
            'password' => ['required' => true, 'type' => 'string', 'max' => 200],
        ]);

        $user = wp_signon([
            'user_login' => (string) $input['email'],
            'user_password' => (string) $body['password'],
            'remember' => true,
        ]);

        if (is_wp_error($user)) {
            $this->logger->warning('portal', 'Intento de login fallido en el portal.');

            throw new ImaginaPayException('Credenciales incorrectas.', 'impay_login_invalido', 401);
        }

        return [
            'logged_in' => true,
            'nonce' => wp_create_nonce('wp_rest'),
        ];
    }
}
