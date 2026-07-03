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
