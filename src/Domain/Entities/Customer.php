<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Entities;

use ImaginaPay\Domain\Enums\TaxIdType;

final class Customer
{
    /**
     * @param array<string, mixed>|null $gatewayRefs
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly ?int $wpUserId,
        public readonly string $email,
        public readonly string $fullName,
        public readonly ?string $company,
        public readonly ?TaxIdType $taxIdType,
        public readonly ?string $taxId,
        public readonly string $country,
        public readonly ?string $phone,
        public readonly ?array $gatewayRefs,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }
}
