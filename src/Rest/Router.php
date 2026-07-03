<?php

declare(strict_types=1);

namespace ImaginaPay\Rest;

/**
 * Registra las rutas de todos los controllers. Se construye de forma
 * perezosa dentro de rest_api_init (ver Plugin::boot): en requests que
 * no son del API, ni el Router ni los controllers llegan a instanciarse.
 */
final class Router
{
    /**
     * @param array<int, AbstractController> $controllers
     */
    public function __construct(private readonly array $controllers)
    {
    }

    public function registerRoutes(): void
    {
        foreach ($this->controllers as $controller) {
            $controller->registerRoutes();
        }
    }
}
