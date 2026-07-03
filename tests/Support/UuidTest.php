<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Support;

use ImaginaPay\Support\Uuid;
use ImaginaPay\Tests\TestCase;

final class UuidTest extends TestCase
{
    public function testGeneratesValidV4(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $uuid = Uuid::v4();

            $this->assertTrue(Uuid::isValid($uuid), "UUID inválido: {$uuid}");
            $this->assertSame(36, strlen($uuid));
        }
    }

    public function testGeneratesUniqueValues(): void
    {
        $uuids = [];

        for ($i = 0; $i < 1000; $i++) {
            $uuids[] = Uuid::v4();
        }

        $this->assertCount(1000, array_unique($uuids));
    }

    public function testValidationAcceptsUppercase(): void
    {
        $this->assertTrue(Uuid::isValid(strtoupper(Uuid::v4())));
    }

    public function testValidationRejectsInvalidValues(): void
    {
        $this->assertFalse(Uuid::isValid(''));
        $this->assertFalse(Uuid::isValid('no-es-un-uuid'));
        $this->assertFalse(Uuid::isValid('12345678-1234-1234-1234-123456789012')); // versión != 4
        $this->assertFalse(Uuid::isValid('zzzzzzzz-zzzz-4zzz-8zzz-zzzzzzzzzzzz'));
    }
}
