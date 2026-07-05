<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\Wompi;

use ImaginaPay\Core\Settings;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Http\HttpClient;

/**
 * Cliente REST de Wompi (Bancolombia). La llave privada solo se usa
 * server-side (payment sources y transacciones); la pública viaja al
 * navegador para tokenizar (el PAN nunca toca este servidor).
 */
class WompiClient
{
    private const PRODUCTION_URL = 'https://production.wompi.co/v1';
    private const SANDBOX_URL = 'https://sandbox.wompi.co/v1';

    public function __construct(
        private readonly HttpClient $http,
        private readonly Settings $settings,
    ) {
    }

    public function baseUrl(): string
    {
        return $this->isSandbox() ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    public function isSandbox(): bool
    {
        return (bool) $this->settings->get('wompi_sandbox', true);
    }

    public function publicKey(): string
    {
        $value = $this->settings->get('wompi_public_key', '');

        return is_string($value) ? trim($value) : '';
    }

    public function privateKey(): string
    {
        $value = $this->settings->get('wompi_private_key', '');

        return is_string($value) ? trim($value) : '';
    }

    public function integritySecret(): string
    {
        $value = $this->settings->get('wompi_integrity_secret', '');

        return is_string($value) ? trim($value) : '';
    }

    public function eventsSecret(): string
    {
        $value = $this->settings->get('wompi_events_secret', '');

        return is_string($value) ? trim($value) : '';
    }

    public function isConfigured(): bool
    {
        return $this->publicKey() !== ''
            && $this->privateKey() !== ''
            && $this->integritySecret() !== ''
            && $this->eventsSecret() !== '';
    }

    /**
     * Token de aceptación de términos de Wompi (requisito legal para
     * crear payment sources). Se obtiene de la info pública del comercio.
     */
    public function acceptanceToken(): string
    {
        $response = $this->http->get($this->baseUrl() . '/merchants/' . rawurlencode($this->publicKey()));

        if (!$response->ok()) {
            throw new GatewayException(sprintf('Wompi respondió %d al consultar el comercio.', $response->status));
        }

        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $presigned = is_array($data['presigned_acceptance'] ?? null) ? $data['presigned_acceptance'] : [];
        $token = is_string($presigned['acceptance_token'] ?? null) ? $presigned['acceptance_token'] : '';

        if ($token === '') {
            throw new GatewayException('Wompi no devolvió el acceptance_token del comercio.');
        }

        return $token;
    }

    /**
     * Crea la fuente de pago reutilizable a partir del token del navegador.
     *
     * @return array<string, mixed> data de la fuente (id, type, status, public_data).
     */
    public function createPaymentSource(string $type, string $token, string $customerEmail, string $acceptanceToken): array
    {
        $data = $this->post('/payment_sources', [
            'type' => $type,
            'token' => $token,
            'customer_email' => $customerEmail,
            'acceptance_token' => $acceptanceToken,
        ]);

        if (!isset($data['id'])) {
            throw new GatewayException('Wompi no devolvió el id de la fuente de pago.');
        }

        return $data;
    }

    /**
     * Crea una transacción (asíncrona: el estado final llega por webhook).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> data de la transacción (id, status...).
     */
    public function createTransaction(array $payload): array
    {
        $data = $this->post('/transactions', $payload);

        if (!isset($data['id'])) {
            throw new GatewayException('Wompi no devolvió el id de la transacción.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransaction(string $transactionId): array
    {
        $response = $this->http->get($this->baseUrl() . '/transactions/' . rawurlencode($transactionId));

        if (!$response->ok()) {
            throw new GatewayException(sprintf(
                'Wompi respondió %d al consultar la transacción %s.',
                $response->status,
                $transactionId,
            ));
        }

        $json = $response->json();

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        if ($this->privateKey() === '') {
            throw new GatewayException('La llave privada de Wompi no está configurada.');
        }

        $response = $this->http->post(
            $this->baseUrl() . $path,
            [
                'Authorization' => 'Bearer ' . $this->privateKey(),
                'Content-Type' => 'application/json',
            ],
            (string) wp_json_encode($payload),
        );

        $json = $response->json();

        if (!$response->ok()) {
            $reason = is_array($json['error'] ?? null) ? (string) wp_json_encode($json['error']) : $response->body;

            throw new GatewayException(sprintf('Wompi respondió %d en %s: %s', $response->status, $path, $reason));
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
}
