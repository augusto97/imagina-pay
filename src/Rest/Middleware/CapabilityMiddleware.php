<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Middleware;

final class CapabilityMiddleware implements Middleware
{
    public function __construct(private readonly string $capability)
    {
    }

    public function authorize(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!current_user_can($this->capability)) {
            return new \WP_Error(
                'impay_sin_permisos',
                'No tienes permisos para realizar esta acción.',
                ['status' => is_user_logged_in() ? 403 : 401],
            );
        }

        return true;
    }
}
