<?php

declare(strict_types=1);

namespace ImaginaPay\Rest;

use ImaginaPay\Core\Settings;
use ImaginaPay\Rest\Middleware\CapabilityMiddleware;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Support\Logger;

/**
 * GET/PUT /admin/settings. Los secretos jamás viajan en claro:
 * el GET los enmascara y el PUT ignora valores enmascarados (no
 * sobreescribir un secreto con su propia máscara).
 */
final class SettingsController extends AbstractController
{
    private const ALLOWED_KEYS = [
        'mercadopago_public_key',
        'mercadopago_public_key_test',
        'mercadopago_access_token',
        'mercadopago_access_token_test',
        'mercadopago_webhook_secret',
        'mercadopago_sandbox',
        'paypal_client_id',
        'paypal_client_id_test',
        'paypal_client_secret',
        'paypal_client_secret_test',
        'paypal_webhook_id',
        'paypal_sandbox',
        'epayco_cust_id',
        'epayco_public_key',
        'epayco_p_key',
        'epayco_test',
        'wompi_public_key',
        'wompi_private_key',
        'wompi_events_secret',
        'wompi_integrity_secret',
        'wompi_sandbox',
        'email_from_name',
        'email_from_address',
        'brand_color',
        'brand_logo_url',
        'cop_usd_rate',
        'log_retention_days',
        'updater_api_url',
        'updater_api_key',
    ];

    public function __construct(
        Logger $logger,
        private readonly Settings $settings,
        private readonly Validator $validator,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $permissions = $this->permissions(
            new NonceMiddleware(),
            new CapabilityMiddleware('manage_impay'),
        );

        register_rest_route(self::API_NAMESPACE, '/admin/settings', [
            [
                'methods' => 'GET',
                'callback' => fn (\WP_REST_Request $request): \WP_REST_Response => $this->handle(
                    fn (): array => ['settings' => $this->settings->export()],
                ),
                'permission_callback' => $permissions,
            ],
            [
                'methods' => 'PUT',
                'callback' => fn (\WP_REST_Request $request): \WP_REST_Response => $this->handle(
                    fn (): array => $this->update($request),
                ),
                'permission_callback' => $permissions,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function update(\WP_REST_Request $request): array
    {
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];

        /** @var array<string, mixed> $body */
        $input = $this->validator->validate($body, [
            'settings' => ['required' => true, 'type' => 'array'],
        ]);

        /** @var array<string, mixed> $incoming */
        $incoming = is_array($input['settings']) ? $input['settings'] : [];
        $changes = [];

        foreach ($incoming as $key => $value) {
            if (!is_string($key) || !in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            // Un secreto que vuelve enmascarado no se toca.
            if ($this->settings->isSecret($key) && is_string($value) && str_starts_with($value, '••••')) {
                continue;
            }

            $changes[$key] = is_string($value) ? sanitize_text_field($value) : $value;
        }

        if ($changes !== []) {
            $this->settings->update($changes);
            $this->logger->info('settings', 'Ajustes actualizados.', ['keys' => array_keys($changes)]);
        }

        return ['settings' => $this->settings->export()];
    }
}
