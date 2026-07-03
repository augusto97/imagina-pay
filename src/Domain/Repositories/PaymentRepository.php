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
     * Listado admin con filtros; incluye el total del rango por moneda.
     *
     * @return array{items: list<Payment>, total: int, sums: array<string, int>}
     */
    public function list(
        int $page = 1,
        int $perPage = 20,
        ?PaymentStatus $status = null,
        ?string $gateway = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?int $customerId = null,
    ): array {
        $args = [$this->table('payments')];
        $conditions = '';

        if ($status !== null) {
            $conditions .= ' AND status = %s';
            $args[] = $status->value;
        }

        if ($gateway !== null && $gateway !== '') {
            $conditions .= ' AND gateway = %s';
            $args[] = $gateway;
        }

        if ($from !== null) {
            $conditions .= ' AND created_at >= %s';
            $args[] = $this->formatDate($from);
        }

        if ($to !== null) {
            $conditions .= ' AND created_at <= %s';
            $args[] = $this->formatDate($to);
        }

        if ($customerId !== null && $customerId > 0) {
            $conditions .= ' AND customer_id = %d';
            $args[] = $customerId;
        }

        $total = (int) $this->selectScalar('SELECT COUNT(*) FROM %i WHERE 1=1' . $conditions, $args);

        $sumRows = $this->selectRows(
            'SELECT currency, SUM(amount) AS total FROM %i WHERE 1=1' . $conditions . ' GROUP BY currency',
            $args,
        );
        $sums = [];

        foreach ($sumRows as $row) {
            $sums[(string) $row['currency']] = (int) $row['total'];
        }

        $listArgs = $args;
        array_push($listArgs, max(1, $perPage), max(0, ($page - 1) * $perPage));

        $rows = $this->selectRows(
            'SELECT * FROM %i WHERE 1=1' . $conditions . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            $listArgs,
        );

        return [
            'items' => array_values(array_map(fn (array $row): Payment => $this->mapRow($row), $rows)),
            'total' => $total,
            'sums' => $sums,
        ];
    }

    /**
     * Ingresos aprobados agrupados por mes y moneda (gráfico 12 meses).
     *
     * @return list<array{month: string, currency: string, amount: int}>
     */
    public function monthlyRevenue(\DateTimeImmutable $since): array
    {
        $rows = $this->selectRows(
            "SELECT DATE_FORMAT(paid_at, '%%Y-%%m') AS month, currency, SUM(amount) AS amount"
            . ' FROM %i WHERE status = %s AND paid_at IS NOT NULL AND paid_at >= %s'
            . ' GROUP BY month, currency ORDER BY month ASC',
            [$this->table('payments'), PaymentStatus::Approved->value, $this->formatDate($since)],
        );

        return array_values(array_map(static fn (array $row): array => [
            'month' => (string) $row['month'],
            'currency' => (string) $row['currency'],
            'amount' => (int) $row['amount'],
        ], $rows));
    }

    /**
     * Filas planas para el export contable CSV.
     *
     * @return list<array<string, mixed>>
     */
    public function rowsForExport(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->selectRows(
            'SELECT p.uuid, p.gateway, p.gateway_payment_id, p.status, p.currency, p.amount, p.method,'
            . ' p.paid_at, p.created_at, c.email, c.full_name, c.tax_id_type, c.tax_id'
            . ' FROM %i p LEFT JOIN %i c ON c.id = p.customer_id'
            . ' WHERE p.created_at BETWEEN %s AND %s ORDER BY p.id ASC',
            [$this->table('payments'), $this->table('customers'), $this->formatDate($from), $this->formatDate($to)],
        );
    }

    /**
     * @return list<Payment>
     */
    public function findBySubscription(int $subscriptionId, int $limit = 50): array
    {
        $rows = $this->selectRows(
            'SELECT * FROM %i WHERE subscription_id = %d ORDER BY id DESC LIMIT %d',
            [$this->table('payments'), $subscriptionId, $limit],
        );

        return array_values(array_map(fn (array $row): Payment => $this->mapRow($row), $rows));
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
