<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Repositories;

use ImaginaPay\Domain\Enums\WebhookEventStatus;

/**
 * Idempotencia y auditoría de webhooks. La clave UNIQUE (gateway, event_id)
 * garantiza que un evento repetido no se procese dos veces.
 */
class WebhookEventRepository extends AbstractRepository
{
    /**
     * Fila cruda del evento (para el processor asíncrono).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->selectRow(
            'SELECT * FROM %i WHERE id = %d',
            [$this->table('webhook_events'), $id],
        );
    }

    public function exists(string $gateway, string $eventId): bool
    {
        $row = $this->selectRow(
            'SELECT id FROM %i WHERE gateway = %s AND event_id = %s',
            [$this->table('webhook_events'), $gateway, $eventId],
        );

        return $row !== null;
    }

    public function findStatus(string $gateway, string $eventId): ?WebhookEventStatus
    {
        $row = $this->selectRow(
            'SELECT status FROM %i WHERE gateway = %s AND event_id = %s',
            [$this->table('webhook_events'), $gateway, $eventId],
        );

        if ($row === null) {
            return null;
        }

        return WebhookEventStatus::tryFrom((string) ($row['status'] ?? ''));
    }

    /**
     * Registra un evento recibido. Devuelve null si ya existía (duplicado).
     *
     * @param array<string, mixed> $payload
     */
    public function insertReceived(
        string $gateway,
        string $eventId,
        string $topic,
        array $payload,
        \DateTimeImmutable $receivedAt,
    ): ?int {
        if ($this->exists($gateway, $eventId)) {
            return null;
        }

        return $this->insertRow(
            $this->table('webhook_events'),
            [
                'gateway' => $gateway,
                'event_id' => $eventId,
                'topic' => $topic,
                'payload' => (string) wp_json_encode($payload),
                'status' => WebhookEventStatus::Received->value,
                'attempts' => 0,
                'received_at' => $this->formatDate($receivedAt),
            ],
        );
    }

    public function markProcessed(int $id, \DateTimeImmutable $processedAt): void
    {
        $this->db->update(
            $this->table('webhook_events'),
            [
                'status' => WebhookEventStatus::Processed->value,
                'processed_at' => $this->formatDate($processedAt),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
    }

    public function markFailed(int $id, string $error): void
    {
        $prepared = $this->db->prepare(
            'UPDATE %i SET status = %s, error = %s, attempts = attempts + 1 WHERE id = %d',
            $this->table('webhook_events'),
            WebhookEventStatus::Failed->value,
            $error,
            $id,
        );

        if (is_string($prepared)) {
            $this->db->query($prepared);
        }
    }
}
