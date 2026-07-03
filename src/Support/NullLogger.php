<?php

declare(strict_types=1);

namespace ImaginaPay\Support;

/**
 * Logger nulo para tests y contextos donde no hay base de datos.
 */
final class NullLogger implements Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(LogLevel $level, string $channel, string $message, array $context = []): void
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $channel, string $message, array $context = []): void
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $channel, string $message, array $context = []): void
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $channel, string $message, array $context = []): void
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $channel, string $message, array $context = []): void
    {
    }
}
