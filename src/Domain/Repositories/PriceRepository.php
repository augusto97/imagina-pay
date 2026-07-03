<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Repositories;

use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Enums\PriceInterval;
use ImaginaPay\Domain\Enums\PriceStatus;

class PriceRepository extends AbstractRepository
{
    public function find(int $id): ?Price
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE id = %d', [$this->table('prices'), $id]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByUuid(string $uuid): ?Price
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE uuid = %s', [$this->table('prices'), $uuid]);

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * @return list<Price>
     */
    public function findByProduct(int $productId): array
    {
        $prepared = $this->db->prepare(
            'SELECT * FROM %i WHERE product_id = %d ORDER BY id ASC',
            $this->table('prices'),
            $productId,
        );

        if (!is_string($prepared)) {
            return [];
        }

        /** @var array<int, array<string, mixed>>|null $rows */
        $rows = $this->db->get_results($prepared, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(fn (array $row): Price => $this->mapRow($row), $rows));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->insertRow($this->table('prices'), $data);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Price
    {
        return new Price(
            (int) $row['id'],
            (string) $row['uuid'],
            (int) $row['product_id'],
            (string) $row['currency'],
            (int) $row['amount'],
            PriceInterval::from((string) $row['interval']),
            (int) ($row['trial_days'] ?? 0),
            $this->decodeJson($row['gateway_refs'] ?? null),
            PriceStatus::from((string) $row['status']),
            $this->requireDate($row['created_at'] ?? null),
            $this->requireDate($row['updated_at'] ?? null),
        );
    }
}
