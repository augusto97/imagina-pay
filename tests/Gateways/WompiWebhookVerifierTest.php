<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Gateways;

use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Gateways\Wompi\WompiWebhookVerifier;
use ImaginaPay\Tests\TestCase;

final class WompiWebhookVerifierTest extends TestCase
{
    private const SECRET = 'secreto-eventos-wompi';

    /**
     * @return array<string, mixed>
     */
    private function event(?string $checksum = null): array
    {
        $transaction = [
            'id' => '1234-1605025087-49201',
            'status' => 'APPROVED',
            'amount_in_cents' => 4990000,
        ];

        $properties = ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'];
        $timestamp = 1530291411;

        $concatenated = $transaction['id'] . $transaction['status'] . $transaction['amount_in_cents'];

        return [
            'event' => 'transaction.updated',
            'data' => ['transaction' => $transaction],
            'signature' => [
                'properties' => $properties,
                'checksum' => $checksum ?? hash('sha256', $concatenated . $timestamp . self::SECRET),
            ],
            'timestamp' => $timestamp,
        ];
    }

    public function testAcceptsValidChecksum(): void
    {
        (new WompiWebhookVerifier())->verify($this->event(), self::SECRET);

        $this->addToAssertionCount(1); // No lanzó excepción.
    }

    public function testRejectsInvalidChecksum(): void
    {
        $this->expectException(GatewayException::class);
        (new WompiWebhookVerifier())->verify($this->event(str_repeat('0', 64)), self::SECRET);
    }

    public function testRejectsWhenSecretMissing(): void
    {
        $this->expectException(GatewayException::class);
        (new WompiWebhookVerifier())->verify($this->event(), '');
    }

    public function testRejectsTamperedAmount(): void
    {
        $event = $this->event();
        $event['data']['transaction']['amount_in_cents'] = 100; // firmado con 4990000

        $this->expectException(GatewayException::class);
        (new WompiWebhookVerifier())->verify($event, self::SECRET);
    }

    public function testPropertiesAreReadDynamicallyFromTheEvent(): void
    {
        // Firma que solo cubre id y status (properties distintas).
        $transaction = ['id' => 'txn-1', 'status' => 'DECLINED', 'amount_in_cents' => 5];
        $timestamp = 99;

        $event = [
            'data' => ['transaction' => $transaction],
            'signature' => [
                'properties' => ['transaction.id', 'transaction.status'],
                'checksum' => hash('sha256', 'txn-1DECLINED' . $timestamp . self::SECRET),
            ],
            'timestamp' => $timestamp,
        ];

        (new WompiWebhookVerifier())->verify($event, self::SECRET);

        $this->addToAssertionCount(1);
    }
}
