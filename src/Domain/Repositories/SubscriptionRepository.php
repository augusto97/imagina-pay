<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Repositories;

use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\SubscriptionStatus;

class SubscriptionRepository extends AbstractRepository
{
    public function find(int $id): ?Subscription
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE id = %d', [$this->table('subscriptions'), $id]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByUuid(string $uuid): ?Subscription
    {
        $row = $this->selectRow('SELECT * FROM %i WHERE uuid = %s', [$this->table('subscriptions'), $uuid]);

        return $row === null ? null : $this->mapRow($row);
    }

    public function findByGatewaySubId(string $gateway, string $gatewaySubId): ?Subscription
    {
        $row = $this->selectRow(
            'SELECT * FROM %i WHERE gateway = %s AND gateway_sub_id = %s',
            [$this->table('subscriptions'), $gateway, $gatewaySubId],
        );

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * @return list<Subscription>
     */
    public function findByCustomer(int $customerId): array
    {
        $prepared = $this->db->prepare(
            'SELECT * FROM %i WHERE customer_id = %d ORDER BY id DESC',
            $this->table('subscriptions'),
            $customerId,
        );

        if (!is_string($prepared)) {
            return [];
        }

        /** @var array<int, array<string, mixed>>|null $rows */
        $rows = $this->db->get_results($prepared, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(fn (array $row): Subscription => $this->mapRow($row), $rows));
    }

    /**
     * Listado admin con filtros y búsqueda por cliente (email/nombre).
     *
     * @return array{items: list<Subscription>, total: int}
     */
    public function list(
        int $page = 1,
        int $perPage = 20,
        ?SubscriptionStatus $status = null,
        ?string $gateway = null,
        ?int $productId = null,
        string $search = '',
    ): array {
        $sql = 'SELECT s.* FROM %i s INNER JOIN %i c ON c.id = s.customer_id WHERE 1=1';
        $countSql = 'SELECT COUNT(*) FROM %i s INNER JOIN %i c ON c.id = s.customer_id WHERE 1=1';
        $args = [$this->table('subscriptions'), $this->table('customers')];

        $conditions = '';

        if ($status !== null) {
            $conditions .= ' AND s.status = %s';
            $args[] = $status->value;
        }

        if ($gateway !== null && $gateway !== '') {
            $conditions .= ' AND s.gateway = %s';
            $args[] = $gateway;
        }

        if ($productId !== null && $productId > 0) {
            $conditions .= ' AND s.product_id = %d';
            $args[] = $productId;
        }

        if ($search !== '') {
            $like = '%' . $this->db->esc_like($search) . '%';
            $conditions .= ' AND (c.email LIKE %s OR c.full_name LIKE %s OR s.uuid LIKE %s)';
            array_push($args, $like, $like, $like);
        }

        $total = (int) $this->selectScalar($countSql . $conditions, $args);

        $sql .= $conditions . ' ORDER BY s.id DESC LIMIT %d OFFSET %d';
        array_push($args, max(1, $perPage), max(0, ($page - 1) * $perPage));

        $rows = $this->selectRows($sql, $args);

        return [
            'items' => array_values(array_map(fn (array $row): Subscription => $this->mapRow($row), $rows)),
            'total' => $total,
        ];
    }

    public function countByStatus(SubscriptionStatus $status): int
    {
        return (int) $this->selectScalar(
            'SELECT COUNT(*) FROM %i WHERE status = %s',
            [$this->table('subscriptions'), $status->value],
        );
    }

    /**
     * MRR estimado por moneda: precio mensual completo, anuales prorrateados /12.
     *
     * @return array<string, int> currency => monto mensual en unidad mínima.
     */
    public function mrrByCurrency(): array
    {
        $rows = $this->selectRows(
            'SELECT p.currency, SUM(CASE WHEN p.`interval` = %s THEN p.amount ELSE ROUND(p.amount / 12) END) AS mrr'
            . ' FROM %i s INNER JOIN %i p ON p.id = s.price_id'
            . ' WHERE s.status = %s AND p.`interval` != %s GROUP BY p.currency',
            ['month', $this->table('subscriptions'), $this->table('prices'), 'active', 'one_time'],
        );

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row['currency']] = (int) $row['mrr'];
        }

        return $result;
    }

    /**
     * Renovaciones/vencimientos en los próximos N días (suscripciones activas).
     *
     * @return list<Subscription>
     */
    public function upcomingRenewals(\DateTimeImmutable $now, int $days = 30, int $limit = 50): array
    {
        $prepared = $this->db->prepare(
            'SELECT * FROM %i WHERE status = %s AND current_period_end IS NOT NULL'
            . ' AND current_period_end BETWEEN %s AND %s ORDER BY current_period_end ASC LIMIT %d',
            $this->table('subscriptions'),
            SubscriptionStatus::Active->value,
            $this->formatDate($now),
            $this->formatDate($now->add(new \DateInterval('P' . $days . 'D'))),
            $limit,
        );

        return $this->mapRows($prepared);
    }

    /**
     * Suscripciones con tarea de provisión manual pendiente (meta.manual_task).
     *
     * @return list<Subscription>
     */
    public function withPendingManualTasks(int $limit = 50): array
    {
        $prepared = $this->db->prepare(
            'SELECT * FROM %i WHERE meta LIKE %s AND meta LIKE %s ORDER BY id DESC LIMIT %d',
            $this->table('subscriptions'),
            '%"manual_task"%',
            '%"pending"%',
            $limit,
        );

        return $this->mapRows($prepared);
    }

    /**
     * @return list<Subscription>
     */
    public function findByStatus(SubscriptionStatus $status, int $limit = 500): array
    {
        $prepared = $this->db->prepare(
            'SELECT * FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d',
            $this->table('subscriptions'),
            $status->value,
            $limit,
        );

        return $this->mapRows($prepared);
    }

    /**
     * Suscripciones con motor en la pasarela que deben cotejarse a diario.
     *
     * @return list<Subscription>
     */
    public function findForReconciliation(int $limit = 500): array
    {
        $prepared = $this->db->prepare(
            'SELECT * FROM %i WHERE gateway_sub_id IS NOT NULL'
            . " AND status IN ('active', 'past_due', 'pending') ORDER BY id ASC LIMIT %d",
            $this->table('subscriptions'),
            $limit,
        );

        return $this->mapRows($prepared);
    }

    /**
     * Suscripciones lógicas (annual_hybrid, gateway_sub_id NULL) activas
     * cuyo periodo vence antes de la fecha dada.
     *
     * @return list<Subscription>
     */
    public function findLogicalExpiring(\DateTimeImmutable $before, int $limit = 500): array
    {
        $prepared = $this->db->prepare(
            'SELECT * FROM %i WHERE gateway_sub_id IS NULL AND status = %s'
            . ' AND current_period_end IS NOT NULL AND current_period_end <= %s ORDER BY current_period_end ASC LIMIT %d',
            $this->table('subscriptions'),
            SubscriptionStatus::Active->value,
            $this->formatDate($before),
            $limit,
        );

        return $this->mapRows($prepared);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->insertRow(
            $this->table('subscriptions'),
            $data);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function updateMeta(int $id, array $meta, \DateTimeImmutable $updatedAt): void
    {
        $this->db->update(
            $this->table('subscriptions'),
            [
                'meta' => (string) wp_json_encode($meta),
                'updated_at' => $this->formatDate($updatedAt),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Persiste una transición de estado. cancelled_at solo se escribe al cancelar.
     */
    public function updateStatus(
        int $id,
        SubscriptionStatus $status,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $cancelledAt = null,
    ): void {
        $data = [
            'status' => $status->value,
            'updated_at' => $this->formatDate($updatedAt),
        ];
        $formats = ['%s', '%s'];

        if ($cancelledAt !== null) {
            $data['cancelled_at'] = $this->formatDate($cancelledAt);
            $formats[] = '%s';
        }

        $this->db->update($this->table('subscriptions'), $data, ['id' => $id], $formats, ['%d']);
    }

    public function setGatewaySubId(int $id, string $gatewaySubId, \DateTimeImmutable $updatedAt): void
    {
        $this->db->update(
            $this->table('subscriptions'),
            [
                'gateway_sub_id' => $gatewaySubId,
                'updated_at' => $this->formatDate($updatedAt),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Extiende el periodo vigente tras un cobro aprobado y resetea el
     * contador de pagos fallidos.
     */
    public function extendPeriod(
        int $id,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        \DateTimeImmutable $updatedAt,
    ): void {
        $this->db->update(
            $this->table('subscriptions'),
            [
                'current_period_start' => $this->formatDate($periodStart),
                'current_period_end' => $this->formatDate($periodEnd),
                'failed_payments' => 0,
                'updated_at' => $this->formatDate($updatedAt),
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s'],
            ['%d'],
        );
    }

    /**
     * Incrementa pagos fallidos de forma atómica y devuelve el nuevo conteo.
     */
    public function incrementFailedPayments(int $id, \DateTimeImmutable $updatedAt): int
    {
        $prepared = $this->db->prepare(
            'UPDATE %i SET failed_payments = failed_payments + 1, updated_at = %s WHERE id = %d',
            $this->table('subscriptions'),
            $this->formatDate($updatedAt),
            $id,
        );

        if (is_string($prepared)) {
            $this->db->query($prepared);
        }

        $row = $this->selectRow(
            'SELECT failed_payments FROM %i WHERE id = %d',
            [$this->table('subscriptions'), $id],
        );

        return $row === null ? 0 : (int) ($row['failed_payments'] ?? 0);
    }

    public function markCancelAtPeriodEnd(int $id, bool $cancelAtPeriodEnd, \DateTimeImmutable $updatedAt): void
    {
        $this->db->update(
            $this->table('subscriptions'),
            [
                'cancel_at_period_end' => $cancelAtPeriodEnd ? 1 : 0,
                'updated_at' => $this->formatDate($updatedAt),
            ],
            ['id' => $id],
            ['%d', '%s'],
            ['%d'],
        );
    }

    /**
     * @return list<Subscription>
     */
    private function mapRows(mixed $preparedSql): array
    {
        if (!is_string($preparedSql)) {
            return [];
        }

        /** @var array<int, array<string, mixed>>|null $rows */
        $rows = $this->db->get_results($preparedSql, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(fn (array $row): Subscription => $this->mapRow($row), $rows));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Subscription
    {
        return new Subscription(
            (int) $row['id'],
            (string) $row['uuid'],
            (int) $row['customer_id'],
            (int) $row['product_id'],
            (int) $row['price_id'],
            (string) $row['gateway'],
            $this->toNullableString($row['gateway_sub_id'] ?? null),
            SubscriptionStatus::from((string) $row['status']),
            $this->toDate($row['current_period_start'] ?? null),
            $this->toDate($row['current_period_end'] ?? null),
            (bool) (int) ($row['cancel_at_period_end'] ?? 0),
            $this->toDate($row['cancelled_at'] ?? null),
            (int) ($row['failed_payments'] ?? 0),
            $this->decodeJson($row['meta'] ?? null),
            $this->requireDate($row['created_at'] ?? null),
            $this->requireDate($row['updated_at'] ?? null),
        );
    }
}
