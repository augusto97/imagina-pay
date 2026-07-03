<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Repositories;

/**
 * Base de repositorios sobre wpdb. Todas las queries directas usan
 * $wpdb->prepare; los helpers insert/update de wpdb preparan internamente.
 */
abstract class AbstractRepository
{
    protected const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(protected readonly \wpdb $db)
    {
    }

    protected function table(string $name): string
    {
        return $this->db->prefix . 'impay_' . $name;
    }

    /**
     * Ejecuta un SELECT de una fila preparado y la devuelve como arreglo asociativo.
     * Los nombres de tabla se pasan como argumento con el placeholder %i.
     *
     * @param literal-string $sql
     * @param array<int, string|int|float> $args
     * @return array<string, mixed>|null
     */
    protected function selectRow(string $sql, array $args): ?array
    {
        $prepared = $this->db->prepare($sql, ...$args);

        if (!is_string($prepared)) {
            return null;
        }

        /** @var array<string, mixed>|null $row */
        $row = $this->db->get_row($prepared, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Inserta una fila derivando los formatos del tipo de cada valor,
     * de modo que columnas opcionales no desalineen los placeholders.
     *
     * @param array<string, mixed> $data
     */
    protected function insertRow(string $table, array $data): int
    {
        $formats = array_map(
            static fn (mixed $value): string => match (true) {
                is_int($value), is_bool($value) => '%d',
                is_float($value) => '%f',
                default => '%s',
            },
            array_values($data),
        );

        $result = $this->db->insert($table, $data, $formats);

        if ($result === false) {
            throw new \RuntimeException(sprintf('Error al insertar en la tabla %s: %s', $table, $this->db->last_error));
        }

        return (int) $this->db->insert_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeJson(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function toDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $value, new \DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }

    protected function requireDate(mixed $value): \DateTimeImmutable
    {
        return $this->toDate($value) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    protected function formatDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }

    protected function toNullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
