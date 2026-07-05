<?php

declare(strict_types=1);

namespace ImaginaPay\Core;

use ImaginaPay\Support\Crypto;

/**
 * Ajustes del plugin en wp_options. Las credenciales se cifran at-rest
 * (AES-256-GCM) y NUNCA se devuelven en claro por REST: solo los últimos
 * 4 caracteres enmascarados.
 */
final class Settings
{
    private const OPTION = 'impay_settings';

    private const SECRET_KEYS = [
        'mercadopago_access_token',
        'mercadopago_access_token_test',
        'mercadopago_webhook_secret',
        'paypal_client_secret',
        'paypal_client_secret_test',
        'epayco_p_key',
        'updater_api_key',
    ];

    public function __construct(private readonly Crypto $crypto)
    {
    }

    /**
     * Valor de un ajuste; los secretos se devuelven descifrados
     * (solo para consumo interno, jamás exponer por REST).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->readOption();

        if (!array_key_exists($key, $all)) {
            return $default;
        }

        $value = $all[$key];

        if ($this->isSecret($key) && is_string($value) && $value !== '') {
            return $this->crypto->decrypt($value);
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $this->update([$key => $value]);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(array $values): void
    {
        $all = $this->readOption();

        foreach ($values as $key => $value) {
            if ($this->isSecret($key) && is_string($value) && $value !== '') {
                $value = $this->crypto->encrypt($value);
            }

            $all[$key] = $value;
        }

        update_option(self::OPTION, $all, false);
    }

    /**
     * Representación segura para REST: secretos enmascarados (••••1234).
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $all = $this->readOption();
        $safe = [];

        foreach ($all as $key => $value) {
            if ($this->isSecret($key) && is_string($value) && $value !== '') {
                $safe[$key] = $this->mask($this->crypto->decrypt($value));
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }

    public function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    private function mask(string $value): string
    {
        return '••••' . substr($value, -4);
    }

    /**
     * @return array<string, mixed>
     */
    private function readOption(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            return [];
        }

        /** @var array<string, mixed> $stored */
        return $stored;
    }
}
