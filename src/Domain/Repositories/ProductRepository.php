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
     * @return list<Product>
     */
    public function all(): array
    {
        $rows = $this->selectRows('SELECT * FROM %i ORDER BY id DESC', [$this->table('products')]);

        return array_values(array_map(fn (array $row): Product => $this->mapRow($row), $rows));
    }

    /**
     * @param list<int> $ids
     * @return array<int, Product> Indexado por id.
     */
    public function findByIds(array $ids): array
    {
        $result = [];

        foreach (array_unique($ids) as $id) {
            $product = $this->find($id);

            if ($product !== null) {
                $result[$id] = $product;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data Columnas de impay_products.
     */
    public function insert(array $data): int
    {
        return $this->insertRow($this->table('products'), $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->updateRow($this->table('products'), $id, $data);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Product
    {
        $features = $this->decodeJson($row['features'] ?? null);
        $customFields = $this->decodeJson($row['custom_fields'] ?? null);

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
            $customFields === null ? null : array_values(array_filter($customFields, 'is_array')),
            $this->requireDate($row['created_at'] ?? null),
            $this->requireDate($row['updated_at'] ?? null),
        );
    }
}
