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
     * INSERT explícito: wpdb->insert no escapa nombres de columna y
     * `interval` es palabra reservada de MySQL (rompería la query).
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $prepared = $this->db->prepare(
            'INSERT INTO %i (uuid, product_id, currency, amount, `interval`, trial_days, status, created_at, updated_at)'
            . ' VALUES (%s, %d, %s, %d, %s, %d, %s, %s, %s)',
            $this->table('prices'),
            (string) ($data['uuid'] ?? ''),
            (int) ($data['product_id'] ?? 0),
            (string) ($data['currency'] ?? ''),
            (int) ($data['amount'] ?? 0),
            (string) ($data['interval'] ?? ''),
            (int) ($data['trial_days'] ?? 0),
            (string) ($data['status'] ?? 'active'),
            (string) ($data['created_at'] ?? ''),
            (string) ($data['updated_at'] ?? ''),
        );

        if (!is_string($prepared) || $this->db->query($prepared) === false) {
            throw new \RuntimeException('Error al insertar el precio: ' . $this->db->last_error);
        }

        return (int) $this->db->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->updateRow($this->table('prices'), $id, $data);
    }

    /**
     * @param list<int> $ids
     * @return array<int, Price> Indexado por id.
     */
    public function findByIds(array $ids): array
    {
        $result = [];

        foreach (array_unique($ids) as $id) {
            $price = $this->find($id);

            if ($price !== null) {
                $result[$id] = $price;
            }
        }

        return $result;
    }

    /**
     * Guarda referencias de planes creados perezosamente en las pasarelas
     * (p. ej. paypal_plan_id).
     *
     * @param array<string, mixed> $gatewayRefs
     */
    public function updateGatewayRefs(int $id, array $gatewayRefs, \DateTimeImmutable $updatedAt): void
    {
        $this->db->update(
            $this->table('prices'),
            [
                'gateway_refs' => (string) wp_json_encode($gatewayRefs),
                'updated_at' => $this->formatDate($updatedAt),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
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
