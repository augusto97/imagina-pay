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
