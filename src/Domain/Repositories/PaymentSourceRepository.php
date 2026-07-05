<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Repositories;

use ImaginaPay\Domain\Entities\PaymentSource;

class PaymentSourceRepository extends AbstractRepository
{
    public function find(int $id): ?PaymentSource
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE id = %d', [$this->table('payment_sources'), $id]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByGatewaySourceId(string $gateway, string $gatewaySourceId): ?PaymentSource
    {
        $row = $this->selectRow(
            'SELECT * FROM %i WHERE gateway = %s AND gateway_source_id = %s',
            [$this->table('payment_sources'), $gateway, $gatewaySourceId],
        );

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * @return list<PaymentSource>
     */
    public function findByCustomer(int $customerId): array
    {
        $rows = $this->selectRows(
            'SELECT * FROM %i WHERE customer_id = %d ORDER BY id DESC',
            [$this->table('payment_sources'), $customerId],
        );

        return array_values(array_map(fn (array $row): PaymentSource => $this->mapRow($row), $rows));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->insertRow($this->table('payment_sources'), $data);
    }

    public function updateStatus(int $id, string $status, \DateTimeImmutable $updatedAt): void
    {
        $this->db->update(
            $this->table('payment_sources'),
            ['status' => $status, 'updated_at' => $updatedAt->format(self::DATE_FORMAT)],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): PaymentSource
    {
        return new PaymentSource(
            (int) $row['id'],
            (string) $row['uuid'],
            (int) $row['customer_id'],
            (string) $row['gateway'],
            (string) $row['gateway_source_id'],
            (string) $row['type'],
            isset($row['brand']) && is_string($row['brand']) && $row['brand'] !== '' ? $row['brand'] : null,
            isset($row['last_four']) && is_string($row['last_four']) && $row['last_four'] !== '' ? $row['last_four'] : null,
            (string) $row['status'],
            $this->toDate($row['expires_at'] ?? null),
            $this->requireDate($row['created_at'] ?? null),
            $this->requireDate($row['updated_at'] ?? null),
        );
    }
}
