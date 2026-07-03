<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Middleware;

/**
 * Rate limit por IP con transients (spec 8.1: 10 req / 10 min por defecto
 * en endpoints públicos).
 */
final class RateLimitMiddleware implements Middleware
{
    public function __construct(
        private readonly string $bucket,
        private readonly int $maxRequests = 10,
        private readonly int $windowSeconds = 600,
    ) {
    }

    public function authorize(\WP_REST_Request $request): bool|\WP_Error
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : 'desconocida';

        $key = 'impay_rl_' . md5($this->bucket . '|' . $ip);
        $count = get_transient($key);
        $count = is_numeric($count) ? (int) $count : 0;

        if ($count >= $this->maxRequests) {
            return new \WP_Error(
                'impay_demasiadas_solicitudes',
                'Demasiadas solicitudes. Espera unos minutos e intenta de nuevo.',
                ['status' => 429],
            );
        }

        set_transient($key, $count + 1, $this->windowSeconds);

        return true;
    }
}
