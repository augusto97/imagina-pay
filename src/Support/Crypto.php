<?php

declare(strict_types=1);

namespace ImaginaPay\Support;

use ImaginaPay\Exceptions\CryptoException;

/**
 * Cifrado simétrico AES-256-GCM para credenciales at-rest.
 *
 * La clave se deriva de AUTH_KEY vía hash_hkdf (SHA-256). El payload
 * cifrado lleva prefijo de versión para permitir rotación futura:
 * "v1:" . base64(iv[12] . tag[16] . ciphertext).
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = 'v1';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== 32) {
            throw new CryptoException('La clave de cifrado debe tener exactamente 32 bytes.');
        }
    }

    /**
     * Deriva la clave de cifrado desde AUTH_KEY de WordPress.
     */
    public static function fromAuthKey(string $authKey): self
    {
        if ($authKey === '') {
            throw new CryptoException('AUTH_KEY vacío: no es posible derivar la clave de cifrado.');
        }

        return new self(hash_hkdf('sha256', $authKey, 32, 'impay-settings-encryption-v1'));
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new CryptoException('No fue posible cifrar el valor.');
        }

        return self::VERSION . ':' . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $prefix = self::VERSION . ':';

        if (!str_starts_with($payload, $prefix)) {
            throw new CryptoException('Formato de valor cifrado no reconocido.');
        }

        $binary = base64_decode(substr($payload, strlen($prefix)), true);

        if ($binary === false || strlen($binary) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new CryptoException('El valor cifrado está corrupto.');
        }

        $iv = substr($binary, 0, self::IV_LENGTH);
        $tag = substr($binary, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($binary, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new CryptoException('El valor cifrado es inválido o fue alterado.');
        }

        return $plaintext;
    }
}
