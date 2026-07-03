<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Repositories;

use ImaginaPay\Domain\Entities\Customer;
use ImaginaPay\Domain\Enums\TaxIdType;

class CustomerRepository extends AbstractRepository
{
    public function find(int $id): ?Customer
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE id = %d', [$this->table('customers'), $id]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByUuid(string $uuid): ?Customer
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE uuid = %s', [$this->table('customers'), $uuid]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByEmail(string $email): ?Customer
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE email = %s', [$this->table('customers'), $email]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByWpUserId(int $wpUserId): ?Customer
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE wp_user_id = %d', [$this->table('customers'), $wpUserId]);

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->insertRow($this->table('customers'), $data);
    }

    /**
     * Actualiza los datos de contacto/fiscales del cliente.
     *
     * @param array<string, string|null> $fields
     */
    public function update(int $id, array $fields, \DateTimeImmutable $updatedAt): void
    {
        $allowed = ['full_name', 'company', 'tax_id_type', 'tax_id', 'country', 'phone'];
        $data = array_intersect_key($fields, array_flip($allowed));
        $data['updated_at'] = $this->formatDate($updatedAt);

        $this->db->update(
            $this->table('customers'),
            $data,
            ['id' => $id],
            array_fill(0, count($data), '%s'),
            ['%d'],
        );
    }

    public function linkWpUser(int $id, int $wpUserId, \DateTimeImmutable $updatedAt): void
    {
        $this->db->update(
            $this->table('customers'),
            [
                'wp_user_id' => $wpUserId,
                'updated_at' => $this->formatDate($updatedAt),
            ],
            ['id' => $id],
            ['%d', '%s'],
            ['%d'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Customer
    {
        $taxIdType = $this->toNullableString($row['tax_id_type'] ?? null);

        return new Customer(
            (int) $row['id'],
            (string) $row['uuid'],
            $this->toNullableInt($row['wp_user_id'] ?? null),
            (string) $row['email'],
            (string) $row['full_name'],
            $this->toNullableString($row['company'] ?? null),
            $taxIdType === null ? null : TaxIdType::from($taxIdType),
            $this->toNullableString($row['tax_id'] ?? null),
            (string) ($row['country'] ?? 'CO'),
            $this->toNullableString($row['phone'] ?? null),
            $this->decodeJson($row['gateway_refs'] ?? null),
            $this->requireDate($row['created_at'] ?? null),
            $this->requireDate($row['updated_at'] ?? null),
        );
    }
}
