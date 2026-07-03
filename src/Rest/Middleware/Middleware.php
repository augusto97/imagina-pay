<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Middleware;

/**
 * Middleware de autorización de rutas REST. Se componen en el
 * permission_callback de cada ruta (ver AbstractController).
 */
interface Middleware
{
    /**
     * @return true|\WP_Error true si la solicitud puede continuar.
     */
    public function authorize(\WP_REST_Request $request): bool|\WP_Error;
}
