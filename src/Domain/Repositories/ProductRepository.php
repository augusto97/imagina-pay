<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Repositories;

use ImaginaPay\Domain\Entities\Product;
use ImaginaPay\Domain\Enums\ProductStatus;
use ImaginaPay\Domain\Enums\ProductType;

class ProductRepository extends AbstractRepository
{
    public function find(int $id): ?Product
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE id = %d', [$this->table('products'), $id]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByUuid(string $uuid): ?Product
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE uuid = %s', [$this->table('products'), $uuid]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findBySlug(string $slug): ?Product
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE slug = %s', [$this->table('products'), $slug]);

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $data Columnas de impay_products.
     */
    public function insert(array $data): int
    {
        return $this->insertRow($this->table('products'), $data);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Product
    {
        $features = $this->decodeJson($row['features'] ?? null);

        return new Product(
            (int) $row['id'],
            (string) $row['uuid'],
            (string) $row['name'],
            (string) $row['slug'],
            ProductType::from((string) $row['type']),
            $this->toNullableString($row['description'] ?? null),
            $features === null ? null : array_values(array_map('strval', $features)),
            $this->toNullableString($row['image_url'] ?? null),
            ProductStatus::from((string) $row['status']),
            $this->decodeJson($row['provisioning'] ?? null),
            $this->requireDate($row['created_at'] ?? null),
            $this->requireDate($row['updated_at'] ?? null),
        );
    }
}
