<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Support;

use ImaginaPay\Exceptions\CryptoException;
use ImaginaPay\Support\Crypto;
use ImaginaPay\Tests\TestCase;

final class CryptoTest extends TestCase
{
    private function crypto(): Crypto
    {
        return Crypto::fromAuthKey('una-auth-key-de-prueba-suficientemente-larga');
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $crypto = $this->crypto();
        $secret = 'APP_USR-1234567890abcdef-token-mp';

        $encrypted = $crypto->encrypt($secret);

        $this->assertNotSame($secret, $encrypted);
        $this->assertStringStartsWith('v1:', $encrypted);
        $this->assertSame($secret, $crypto->decrypt($encrypted));
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $crypto = $this->crypto();

        // IV aleatorio: el mismo plaintext jamás produce el mismo ciphertext.
        $this->assertNotSame($crypto->encrypt('secreto'), $crypto->encrypt('secreto'));
    }

    public function testSupportsEmptyAndUnicodePlaintext(): void
    {
        $crypto = $this->crypto();

        $this->assertSame('', $crypto->decrypt($crypto->encrypt('')));
        $this->assertSame('contraseña-ñÑ-émoji-💳', $crypto->decrypt($crypto->encrypt('contraseña-ñÑ-émoji-💳')));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $crypto = $this->crypto();
        $encrypted = $crypto->encrypt('secreto');

        $binary = base64_decode(substr($encrypted, 3), true);
        $this->assertIsString($binary);
        // Voltear un bit del último byte (ciphertext).
        $binary[strlen($binary) - 1] = chr(ord($binary[strlen($binary) - 1]) ^ 0x01);
        $tampered = 'v1:' . base64_encode($binary);

        $this->expectException(CryptoException::class);
        $crypto->decrypt($tampered);
    }

    public function testWrongKeyIsRejected(): void
    {
        $encrypted = Crypto::fromAuthKey('clave-numero-uno')->encrypt('secreto');

        $this->expectException(CryptoException::class);
        Crypto::fromAuthKey('clave-numero-dos')->decrypt($encrypted);
    }

    public function testUnknownVersionPrefixIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->crypto()->decrypt('v9:AAAA');
    }

    public function testCorruptBase64IsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->crypto()->decrypt('v1:esto-no-es-base64-válido!!!');
    }

    public function testTruncatedPayloadIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        $this->crypto()->decrypt('v1:' . base64_encode('corto'));
    }

    public function testEmptyAuthKeyIsRejected(): void
    {
        $this->expectException(CryptoException::class);
        Crypto::fromAuthKey('');
    }

    public function testRawKeyMustBe32Bytes(): void
    {
        $this->expectException(CryptoException::class);
        new Crypto('clave-corta');
    }
}
