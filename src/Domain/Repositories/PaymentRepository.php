<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Repositories;

use ImaginaPay\Domain\Entities\Payment;
use ImaginaPay\Domain\Enums\PaymentStatus;

class PaymentRepository extends AbstractRepository
{
    public function find(int $id): ?Payment
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE id = %d', [$this->table('payments'), $id]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByUuid(string $uuid): ?Payment
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE uuid = %s', [$this->table('payments'), $uuid]);

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * Clave de idempotencia de cobros entrantes: (gateway, gateway_payment_id).
     */
    public function findByGatewayPaymentId(string $gateway, string $gatewayPaymentId): ?Payment
    {
        $row = $this->selectRow(
            'SELECT * FROM %i WHERE gateway = %s AND gateway_payment_id = %s',
            [$this->table('payments'), $gateway, $gatewayPaymentId],
        );

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * Actualiza el estado de un pago existente (pending → approved, etc.).
     */
    public function updateStatus(int $id, PaymentStatus $status, ?\DateTimeImmutable $paidAt = null): void
    {
        $data = ['status' => $status->value];
        $formats = ['%s'];

        if ($paidAt !== null) {
            $data['paid_at'] = $this->formatDate($paidAt);
            $formats[] = '%s';
        }

        $this->db->update($this->table('payments'), $data, ['id' => $id], $formats, ['%d']);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->insertRow(
            $this->table('payments'),
            $data);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Payment
    {
        return new Payment(
            (int) $row['id'],
            (string) $row['uuid'],
            $this->toNullableInt($row['order_id'] ?? null),
            $this->toNullableInt($row['subscription_id'] ?? null),
            (int) $row['customer_id'],
            (string) $row['gateway'],
            (string) $row['gateway_payment_id'],
            PaymentStatus::from((string) $row['status']),
            (string) $row['currency'],
            (int) $row['amount'],
            $this->toNullableString($row['method'] ?? null),
            $this->toDate($row['paid_at'] ?? null),
            $this->decodeJson($row['raw'] ?? null),
            $this->requireDate($row['created_at'] ?? null),
        );
    }
}
