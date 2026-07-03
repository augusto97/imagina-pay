<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Repositories;

use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Enums\OrderKind;
use ImaginaPay\Domain\Enums\OrderStatus;

class OrderRepository extends AbstractRepository
{
    public function find(int $id): ?Order
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE id = %d', [$this->table('orders'), $id]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByUuid(string $uuid): ?Order
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE uuid = %s', [$this->table('orders'), $uuid]);

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * El external_reference que viaja a la pasarela es el mismo uuid del order.
     */
    public function findByExternalReference(string $externalReference): ?Order
    {
        $row = $this->selectRow(
            'SELECT * FROM %i WHERE external_reference = %s',
            [$this->table('orders'), $externalReference],
        );

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByGatewayPaymentId(string $gatewayPaymentId): ?Order
    {
        $row = $this->selectRow(
            'SELECT * FROM %i WHERE gateway_payment_id = %s',
            [$this->table('orders'), $gatewayPaymentId],
        );

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->insertRow(
            $this->table('orders'),
            $data);
    }

    public function linkSubscription(int $id, int $subscriptionId, \DateTimeImmutable $updatedAt): void
    {
        $this->db->update(
            $this->table('orders'),
            [
                'subscription_id' => $subscriptionId,
                'updated_at' => $this->formatDate($updatedAt),
            ],
            ['id' => $id],
            ['%d', '%s'],
            ['%d'],
        );
    }

    public function setGatewayRef(int $id, string $gatewayRef, \DateTimeImmutable $updatedAt): void
    {
        $this->db->update(
            $this->table('orders'),
            [
                'gateway_ref' => $gatewayRef,
                'updated_at' => $this->formatDate($updatedAt),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
    }

    public function setGatewayPaymentId(int $id, string $gatewayPaymentId, \DateTimeImmutable $updatedAt): void
    {
        $this->db->update(
            $this->table('orders'),
            [
                'gateway_payment_id' => $gatewayPaymentId,
                'updated_at' => $this->formatDate($updatedAt),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
    }

    public function updateStatus(int $id, OrderStatus $status, \DateTimeImmutable $updatedAt, ?\DateTimeImmutable $paidAt = null): void
    {
        $data = [
            'status' => $status->value,
            'updated_at' => $this->formatDate($updatedAt),
        ];
        $formats = ['%s', '%s'];

        if ($paidAt !== null) {
            $data['paid_at'] = $this->formatDate($paidAt);
            $formats[] = '%s';
        }

        $this->db->update($this->table('orders'), $data, ['id' => $id], $formats, ['%d']);
    }

    /**
     * Expira orders pendientes creados antes del umbral (>48h). Devuelve
     * el número de filas afectadas.
     */
    public function expireStale(\DateTimeImmutable $threshold, \DateTimeImmutable $now): int
    {
        $prepared = $this->db->prepare(
            'UPDATE %i SET status = %s, updated_at = %s WHERE status = %s AND created_at < %s',
            $this->table('orders'),
            OrderStatus::Expired->value,
            $this->formatDate($now),
            OrderStatus::Pending->value,
            $this->formatDate($threshold),
        );

        if (!is_string($prepared)) {
            return 0;
        }

        $affected = $this->db->query($prepared);

        return is_int($affected) ? $affected : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Order
    {
        return new Order(
            (int) $row['id'],
            (string) $row['uuid'],
            (int) $row['customer_id'],
            (int) $row['product_id'],
            (int) $row['price_id'],
            $this->toNullableInt($row['subscription_id'] ?? null),
            OrderKind::from((string) $row['kind']),
            OrderStatus::from((string) $row['status']),
            (string) $row['currency'],
            (int) $row['amount'],
            (string) $row['gateway'],
            $this->toNullableString($row['gateway_ref'] ?? null),
            $this->toNullableString($row['gateway_payment_id'] ?? null),
            (string) $row['external_reference'],
            $this->toDate($row['paid_at'] ?? null),
            $this->decodeJson($row['meta'] ?? null),
            $this->requireDate($row['created_at'] ?? null),
            $this->requireDate($row['updated_at'] ?? null),
        );
    }
}
