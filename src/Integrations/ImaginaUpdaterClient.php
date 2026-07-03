<?php

declare(strict_types=1);

namespace ImaginaPay\Integrations;

use ImaginaPay\Core\Settings;
use ImaginaPay\Domain\Entities\Customer;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Http\HttpClient;
use ImaginaPay\Support\Logger;

/**
 * Cliente de la API de licencias de Imagina Updater. Contrato asumido
 * (ajustar cuando se publique el definitivo):
 *   POST   {base}/wp-json/imagina-updater/v1/licenses               → {license_key}
 *   POST   {base}/wp-json/imagina-updater/v1/licenses/{key}/activate
 *   POST   {base}/wp-json/imagina-updater/v1/licenses/{key}/deactivate
 * Autenticación: header X-Api-Key.
 */
class ImaginaUpdaterClient
{
    private const API_BASE = '/wp-json/imagina-updater/v1';

    public function __construct(
        private readonly HttpClient $http,
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->apiKey() !== '';
    }

    /**
     * Crea una licencia para el cliente y devuelve la license key.
     */
    public function createLicense(Customer $customer, int $updaterProductId, string $reference): string
    {
        $response = $this->http->post(
            $this->endpoint('/licenses'),
            $this->headers(),
            (string) wp_json_encode([
                'email' => $customer->email,
                'name' => $customer->fullName,
                'product_id' => $updaterProductId,
                'reference' => $reference,
            ]),
        );

        $data = $response->json();
        $licenseKey = $data['license_key'] ?? null;

        if (!$response->ok() || !is_string($licenseKey) || $licenseKey === '') {
            throw new GatewayException(sprintf(
                'Imagina Updater respondió %d al crear la licencia.',
                $response->status,
            ));
        }

        $this->logger->info('updater', 'Licencia creada en Imagina Updater.', [
            'reference' => $reference,
            'product_id' => $updaterProductId,
        ]);

        return $licenseKey;
    }

    public function activateLicense(string $licenseKey): void
    {
        $this->licenseAction($licenseKey, 'activate');
    }

    public function deactivateLicense(string $licenseKey): void
    {
        $this->licenseAction($licenseKey, 'deactivate');
    }

    private function licenseAction(string $licenseKey, string $action): void
    {
        $response = $this->http->post(
            $this->endpoint('/licenses/' . rawurlencode($licenseKey) . '/' . $action),
            $this->headers(),
            '{}',
        );

        if (!$response->ok()) {
            throw new GatewayException(sprintf(
                'Imagina Updater respondió %d al %s la licencia.',
                $response->status,
                $action === 'activate' ? 'activar' : 'desactivar',
            ));
        }

        $this->logger->info('updater', sprintf('Licencia %s.', $action === 'activate' ? 'activada' : 'desactivada'));
    }

    private function endpoint(string $path): string
    {
        $base = $this->baseUrl();

        if ($base === '') {
            throw new GatewayException('Imagina Updater no está configurado (falta la URL de la API).');
        }

        return rtrim($base, '/') . self::API_BASE . $path;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === '') {
            throw new GatewayException('Imagina Updater no está configurado (falta la API key).');
        }

        return [
            'X-Api-Key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private function baseUrl(): string
    {
        $url = $this->settings->get('updater_api_url', '');

        return is_string($url) ? trim($url) : '';
    }

    private function apiKey(): string
    {
        $key = $this->settings->get('updater_api_key', '');

        return is_string($key) ? $key : '';
    }
}
