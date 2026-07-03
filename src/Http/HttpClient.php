<?php

declare(strict_types=1);

namespace ImaginaPay\Http;

use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Support\Logger;

/**
 * Cliente HTTP sobre wp_remote_request con reintentos exponenciales:
 * intento inicial + 3 reintentos con esperas de 1s / 4s / 9s ante error
 * de red, 429 o 5xx. Los 4xx no se reintentan (error del request).
 */
class HttpClient
{
    private const MAX_RETRIES = 3;

    private readonly \Closure $sleeper;

    public function __construct(
        private readonly Logger $logger,
        ?\Closure $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = [], int $timeout = 30): HttpResponse
    {
        return $this->request('GET', $url, $headers, null, $timeout);
    }

    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body, $timeout);
    }

    /**
     * @param array<string, string> $headers
     */
    public function put(string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        return $this->request('PUT', $url, $headers, $body, $timeout);
    }

    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES + 1; $attempt++) {
            $args = [
                'method' => $method,
                'headers' => $headers,
                'timeout' => (float) $timeout,
            ];

            if ($body !== null) {
                $args['body'] = $body;
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $lastError = $response->get_error_message();

                $this->logger->warning('http', 'Error de red en solicitud saliente.', [
                    'method' => $method,
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $lastError,
                ]);
            } else {
                $status = (int) wp_remote_retrieve_response_code($response);
                $responseBody = (string) wp_remote_retrieve_body($response);

                // Solo 429 y 5xx son reintentables; el resto se devuelve tal cual.
                if ($status !== 429 && $status < 500) {
                    return new HttpResponse($status, $responseBody);
                }

                $lastError = sprintf('HTTP %d', $status);

                $this->logger->warning('http', 'Respuesta reintentable en solicitud saliente.', [
                    'method' => $method,
                    'url' => $url,
                    'attempt' => $attempt,
                    'status' => $status,
                ]);
            }

            if ($attempt <= self::MAX_RETRIES) {
                ($this->sleeper)($attempt * $attempt); // 1s, 4s, 9s
            }
        }

        throw new GatewayException(sprintf(
            'No fue posible comunicarse con el servicio externo tras %d intentos (%s).',
            self::MAX_RETRIES + 1,
            $lastError,
        ));
    }
}
