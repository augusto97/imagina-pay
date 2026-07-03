<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Gateways;

use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoWebhookVerifier;
use ImaginaPay\Support\Clock;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;

final class MercadoPagoWebhookVerifierTest extends TestCase
{
    private const SECRET = 'secreto-de-webhooks-mp';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-07-03 12:00:00', new \DateTimeZone('UTC'));
    }

    private function verifier(?Clock $clock = null): MercadoPagoWebhookVerifier
    {
        return new MercadoPagoWebhookVerifier($clock ?? new FixedClock($this->now));
    }

    private function sign(string $dataId, string $requestId, int $ts, string $secret = self::SECRET): string
    {
        $manifest = sprintf('id:%s;request-id:%s;ts:%d;', strtolower($dataId), $requestId, $ts);

        return sprintf('ts=%d,v1=%s', $ts, hash_hmac('sha256', $manifest, $secret));
    }

    public function testAcceptsValidSignature(): void
    {
        $ts = $this->now->getTimestamp();

        $this->verifier()->verify(
            $this->sign('12345', 'req-1', $ts),
            'req-1',
            '12345',
            self::SECRET,
        );

        $this->addToAssertionCount(1); // No lanzó excepción.
    }

    public function testAcceptsUppercaseDataIdLoweringIt(): void
    {
        $ts = $this->now->getTimestamp();

        // La firma se calcula con el data.id en minúsculas.
        $this->verifier()->verify(
            $this->sign('ABC123', 'req-1', $ts),
            'req-1',
            'ABC123',
            self::SECRET,
        );

        $this->addToAssertionCount(1);
    }

    public function testRejectsWrongSecret(): void
    {
        $ts = $this->now->getTimestamp();

        $this->expectException(GatewayException::class);
        $this->verifier()->verify(
            $this->sign('12345', 'req-1', $ts, 'otro-secret'),
            'req-1',
            '12345',
            self::SECRET,
        );
    }

    public function testRejectsTamperedDataId(): void
    {
        $ts = $this->now->getTimestamp();

        $this->expectException(GatewayException::class);
        $this->verifier()->verify(
            $this->sign('12345', 'req-1', $ts),
            'req-1',
            '99999', // distinto al firmado
            self::SECRET,
        );
    }

    public function testRejectsSignatureOlderThan5Minutes(): void
    {
        $ts = $this->now->getTimestamp() - 301;

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('5 minutos');
        $this->verifier()->verify(
            $this->sign('12345', 'req-1', $ts),
            'req-1',
            '12345',
            self::SECRET,
        );
    }

    public function testAcceptsMillisecondTimestamps(): void
    {
        $tsMs = $this->now->getTimestamp() * 1000;
        $manifest = sprintf('id:%s;request-id:%s;ts:%d;', '12345', 'req-1', $tsMs);
        $header = sprintf('ts=%d,v1=%s', $tsMs, hash_hmac('sha256', $manifest, self::SECRET));

        $this->verifier()->verify($header, 'req-1', '12345', self::SECRET);

        $this->addToAssertionCount(1);
    }

    public function testRejectsMalformedHeader(): void
    {
        $this->expectException(GatewayException::class);
        $this->verifier()->verify('esto-no-es-una-firma', 'req-1', '12345', self::SECRET);
    }

    public function testRejectsMissingSignature(): void
    {
        $this->expectException(GatewayException::class);
        $this->verifier()->verify('', 'req-1', '12345', self::SECRET);
    }

    public function testRejectsWhenSecretNotConfigured(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('secret');
        $this->verifier()->verify('ts=1,v1=abc', 'req-1', '12345', '');
    }
}
