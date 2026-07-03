<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Middleware;

/**
 * Exige el nonce REST estándar de WP (header X-WP-Nonce, acción wp_rest).
 * Los SPAs lo envían en cada request junto con la cookie de sesión.
 */
final class NonceMiddleware implements Middleware
{
    public function authorize(\WP_REST_Request $request): bool|\WP_Error
    {
        $nonce = $request->get_header('X-WP-Nonce');

        if (!is_string($nonce) || wp_verify_nonce($nonce, 'wp_rest') === false) {
            return new \WP_Error(
                'impay_nonce_invalido',
                'Nonce inválido o vencido. Recarga la página e intenta de nuevo.',
                ['status' => 403],
            );
        }

        return true;
    }
}
