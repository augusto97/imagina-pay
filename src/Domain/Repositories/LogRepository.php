<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Repositories;

use ImaginaPay\Support\LogLevel;

class LogRepository extends AbstractRepository
{
    /**
     * @param array<string, mixed> $context
     */
    public function insert(
        LogLevel $level,
        string $channel,
        string $message,
        array $context,
        \DateTimeImmutable $createdAt,
    ): void {
        // El log jamás debe tumbar el flujo principal: fallo silencioso.
        $this->db->insert(
            $this->table('logs'),
            [
                'level' => $level->value,
                'channel' => $channel,
                'message' => $message,
                'context' => (string) wp_json_encode($context),
                'created_at' => $this->formatDate($createdAt),
            ],
            ['%s', '%s', '%s', '%s', '%s'],
        );
    }

    /**
     * Listado admin (tab de logs).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(int $page = 1, int $perPage = 50, ?string $level = null, ?string $channel = null): array
    {
        $args = [$this->table('logs')];
        $conditions = '';

        if ($level !== null && $level !== '') {
            $conditions .= ' AND level = %s';
            $args[] = $level;
        }

        if ($channel !== null && $channel !== '') {
            $conditions .= ' AND channel = %s';
            $args[] = $channel;
        }

        $total = (int) $this->selectScalar('SELECT COUNT(*) FROM %i WHERE 1=1' . $conditions, $args);

        array_push($args, max(1, $perPage), max(0, ($page - 1) * $perPage));

        $rows = $this->selectRows(
            'SELECT * FROM %i WHERE 1=1' . $conditions . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            $args,
        );

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * Retención: borra logs con más de N días (job impay_cleanup).
     */
    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        $prepared = $this->db->prepare(
            'DELETE FROM %i WHERE created_at < %s',
            $this->table('logs'),
            $this->formatDate($threshold),
        );

        if (!is_string($prepared)) {
            return 0;
        }

        $deleted = $this->db->query($prepared);

        return is_int($deleted) ? $deleted : 0;
    }
}
