<?php

declare(strict_types=1);

namespace ImaginaPay\Rest;

use ImaginaPay\Exceptions\ImaginaPayException;
use ImaginaPay\Exceptions\ValidationException;
use ImaginaPay\Rest\Middleware\Middleware;
use ImaginaPay\Support\Logger;

/**
 * Base de controllers REST. Borde de errores: toda excepción de dominio
 * se traduce a JSON {code, message} con su status HTTP + log; cualquier
 * otra excepción se loguea y responde 500 genérico sin filtrar detalles.
 */
abstract class AbstractController
{
    public const API_NAMESPACE = 'impay/v1';

    public function __construct(protected readonly Logger $logger)
    {
    }

    abstract public function registerRoutes(): void;

    /**
     * Ejecuta el handler dentro del borde de errores.
     *
     * @param callable(): (array<string, mixed>|\WP_REST_Response) $handler
     */
    protected function handle(callable $handler): \WP_REST_Response
    {
        try {
            $result = $handler();

            return $result instanceof \WP_REST_Response ? $result : new \WP_REST_Response($result, 200);
        } catch (ValidationException $exception) {
            return new \WP_REST_Response([
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'errors' => $exception->getErrors(),
            ], $exception->getHttpStatus());
        } catch (ImaginaPayException $exception) {
            $this->logger->warning('rest', $exception->getMessage(), [
                'code' => $exception->getErrorCode(),
            ]);

            return new \WP_REST_Response([
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            $this->logger->error('rest', 'Error inesperado en endpoint REST.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $message = 'Ocurrió un error inesperado. Intenta de nuevo.';

            // A los administradores se les muestra el error real: depurar a
            // ciegas es peor que exponerles el detalle (ya está en los logs).
            if (function_exists('current_user_can') && current_user_can('manage_impay')) {
                $message = sprintf('Error interno: %s', $exception->getMessage());
            }

            return new \WP_REST_Response([
                'code' => 'impay_error_interno',
                'message' => $message,
            ], 500);
        }
    }

    /**
     * Compone middlewares en un permission_callback de WP REST.
     */
    protected function permissions(Middleware ...$middlewares): callable
    {
        return static function (\WP_REST_Request $request) use ($middlewares): bool|\WP_Error {
            foreach ($middlewares as $middleware) {
                $result = $middleware->authorize($request);

                if ($result !== true) {
                    return $result;
                }
            }

            return true;
        };
    }
}
