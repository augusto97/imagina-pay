<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Repositories;

use ImaginaPay\Domain\Entities\PaymentLink;
use ImaginaPay\Domain\Enums\PaymentLinkStatus;

class PaymentLinkRepository extends AbstractRepository
{
    public function find(int $id): ?PaymentLink
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE id = %d', [$this->table('payment_links'), $id]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByUuid(string $uuid): ?PaymentLink
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE uuid = %s', [$this->table('payment_links'), $uuid]);

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * Link abierto vigente de una suscripción (para no duplicar links de renovación).
     */
    public function findOpenBySubscription(int $subscriptionId): ?PaymentLink
    {
        $row = $this->selectRow(
            'SELECT * FROM %i WHERE subscription_id = %d AND status = %s ORDER BY id DESC',
            [$this->table('payment_links'), $subscriptionId, PaymentLinkStatus::Open->value],
        );

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->insertRow(
            $this->table('payment_links'),
            $data);
    }

    /**
     * Expira links abiertos cuya fecha límite ya pasó. Devuelve filas afectadas.
     */
    public function expireStale(\DateTimeImmutable $now): int
    {
        $prepared = $this->db->prepare(
            'UPDATE %i SET status = %s WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s',
            $this->table('payment_links'),
            PaymentLinkStatus::Expired->value,
            PaymentLinkStatus::Open->value,
            $this->formatDate($now),
        );

        if (!is_string($prepared)) {
            return 0;
        }

        $affected = $this->db->query($prepared);

        return is_int($affected) ? $affected : 0;
    }

    public function updateStatus(int $id, PaymentLinkStatus $status, ?int $paidOrderId = null): void
    {
        $data = ['status' => $status->value];
        $formats = ['%s'];

        if ($paidOrderId !== null) {
            $data['paid_order_id'] = $paidOrderId;
            $formats[] = '%d';
        }

        $this->db->update($this->table('payment_links'), $data, ['id' => $id], $formats, ['%d']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): PaymentLink
    {
        return new PaymentLink(
            (int) $row['id'],
            (string) $row['uuid'],
            (int) $row['customer_id'],
            $this->toNullableInt($row['subscription_id'] ?? null),
            (int) $row['price_id'],
            (string) $row['gateway'],
            $this->toNullableString($row['gateway_ref'] ?? null),
            (string) $row['url'],
            PaymentLinkStatus::from((string) $row['status']),
            $this->toDate($row['expires_at'] ?? null),
            $this->toNullableInt($row['paid_order_id'] ?? null),
            $this->requireDate($row['created_at'] ?? null),
        );
    }
}
