<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Entities;

use ImaginaPay\Domain\Enums\ProductStatus;
use ImaginaPay\Domain\Enums\ProductType;

final class Product
{
    /**
     * @param list<string>|null $features
     * @param array<string, mixed>|null $provisioning
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $name,
        public readonly string $slug,
        public readonly ProductType $type,
        public readonly ?string $description,
        public readonly ?array $features,
        public readonly ?string $imageUrl,
        public readonly ProductStatus $status,
        public readonly ?array $provisioning,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }
}
