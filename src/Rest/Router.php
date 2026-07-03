<?php

declare(strict_types=1);

namespace ImaginaPay\Rest;

/**
 * Registra las rutas de todos los controllers en rest_api_init.
 * Los hooks de WP solo delegan: cero lógica aquí.
 */
final class Router
{
    /**
     * @param array<int, AbstractController> $controllers
     */
    public function __construct(private readonly array $controllers)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            foreach ($this->controllers as $controller) {
                $controller->registerRoutes();
            }
        });
    }
}
