<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\PayPal;

use ImaginaPay\Core\Settings;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Http\HttpClient;
use ImaginaPay\Http\HttpResponse;

/**
 * Cliente REST de PayPal (sin SDK). OAuth client_credentials con token
 * cacheado en transient (expira ~9h; se renueva antes de las 8h).
 * PayPal-Request-Id como clave de idempotencia en POST.
 */
class PayPalClient
{
    private const LIVE_URL = 'https://api-m.paypal.com';
    private const SANDBOX_URL = 'https://api-m.sandbox.paypal.com';
    private const TOKEN_TTL = 28800; // 8 horas.

    public function __construct(
        private readonly HttpClient $http,
        private readonly Settings $settings,
    ) {
    }

    public function isSandbox(): bool
    {
        return (bool) $this->settings->get('paypal_sandbox', false);
    }

    public function webhookId(): string
    {
        $webhookId = $this->settings->get('paypal_webhook_id', '');

        return is_string($webhookId) ? $webhookId : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        $response = $this->http->get($this->baseUrl() . $path, $this->headers());

        return $this->unwrap($response, 'GET ' . $path);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = [], ?string $requestId = null): array
    {
        $headers = $this->headers();

        if ($requestId !== null) {
            $headers['PayPal-Request-Id'] = $requestId;
        }

        $response = $this->http->post(
            $this->baseUrl() . $path,
            $headers,
            $body === [] ? '{}' : (string) wp_json_encode($body),
        );

        return $this->unwrap($response, 'POST ' . $path);
    }

    /**
     * Verificación oficial de firma de webhooks de PayPal.
     *
     * @param array<string, string> $headers Headers paypal-* del request entrante.
     * @param array<string, mixed> $event Cuerpo del evento tal como llegó.
     */
    public function verifyWebhookSignature(array $headers, array $event): bool
    {
        $webhookId = $this->webhookId();

        if ($webhookId === '') {
            throw new GatewayException('El Webhook ID de PayPal no está configurado.');
        }

        $payload = [
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ];

        $result = $this->post('/v1/notifications/verify-webhook-signature', $payload);

        return ($result['verification_status'] ?? '') === 'SUCCESS';
    }

    public function baseUrl(): string
    {
        return $this->isSandbox() ? self::SANDBOX_URL : self::LIVE_URL;
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

    private function accessToken(): string
    {
        $transientKey = 'impay_pp_token_' . ($this->isSandbox() ? 'sandbox' : 'live');
        $cached = get_transient($transientKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $idKey = $this->isSandbox() ? 'paypal_client_id_test' : 'paypal_client_id';
        $secretKey = $this->isSandbox() ? 'paypal_client_secret_test' : 'paypal_client_secret';

        $clientId = $this->settings->get($idKey, '');
        $clientSecret = $this->settings->get($secretKey, '');

        if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
            throw new GatewayException('PayPal no está configurado: faltan las credenciales.');
        }

        $response = $this->http->post(
            $this->baseUrl() . '/v1/oauth2/token',
            [
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Autenticación HTTP Basic estándar de OAuth.
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'grant_type=client_credentials',
        );

        $data = $response->json();
        $token = $data['access_token'] ?? null;

        if (!$response->ok() || !is_string($token) || $token === '') {
            throw new GatewayException('No fue posible autenticarse con PayPal.');
        }

        $expiresIn = is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : self::TOKEN_TTL;
        set_transient($transientKey, $token, min($expiresIn - 300, self::TOKEN_TTL));

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrap(HttpResponse $response, string $operation): array
    {
        $json = $response->json();

        if (!$response->ok()) {
            $message = is_string($json['message'] ?? null) ? $json['message'] : 'Error desconocido';
            $name = is_string($json['name'] ?? null) ? $json['name'] : '';

            throw new GatewayException(sprintf(
                'PayPal respondió %d en %s: %s %s',
                $response->status,
                $operation,
                $name,
                $message,
            ));
        }

        return $json;
    }
}
