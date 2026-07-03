<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\MercadoPago;

use ImaginaPay\Core\Settings;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Http\HttpClient;
use ImaginaPay\Http\HttpResponse;

/**
 * Cliente REST de Mercado Pago. Sin SDK oficial (bloat): solo los
 * endpoints que usa el plugin, con Bearer token y X-Idempotency-Key
 * en todo POST/PUT.
 */
class MercadoPagoClient
{
    private const BASE_URL = 'https://api.mercadopago.com';

    public function __construct(
        private readonly HttpClient $http,
        private readonly Settings $settings,
    ) {
    }

    public function isSandbox(): bool
    {
        return (bool) $this->settings->get('mercadopago_sandbox', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        $response = $this->http->get(self::BASE_URL . $path, $this->headers());

        return $this->unwrap($response, 'GET ' . $path);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, ?string $idempotencyKey = null): array
    {
        $headers = $this->headers();

        if ($idempotencyKey !== null) {
            $headers['X-Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->http->post(self::BASE_URL . $path, $headers, (string) wp_json_encode($body));

        return $this->unwrap($response, 'POST ' . $path);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body): array
    {
        $response = $this->http->put(self::BASE_URL . $path, $this->headers(), (string) wp_json_encode($body));

        return $this->unwrap($response, 'PUT ' . $path);
    }

    public function webhookSecret(): string
    {
        $secret = $this->settings->get('mercadopago_webhook_secret', '');

        return is_string($secret) ? $secret : '';
    }

    private function accessToken(): string
    {
        $key = $this->isSandbox() ? 'mercadopago_access_token_test' : 'mercadopago_access_token';
        $token = $this->settings->get($key, '');

        if (!is_string($token) || $token === '') {
            throw new GatewayException('Mercado Pago no está configurado: falta el Access Token.');
        }

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrap(HttpResponse $response, string $operation): array
    {
        $json = $response->json();

        if (!$response->ok()) {
            $message = is_string($json['message'] ?? null) ? $json['message'] : 'Error desconocido';

            throw new GatewayException(sprintf(
                'Mercado Pago respondió %d en %s: %s',
                $response->status,
                $operation,
                $message,
            ));
        }

        return $json;
    }
}
