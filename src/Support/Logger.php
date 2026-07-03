<?php

declare(strict_types=1);

namespace ImaginaPay\Support;

/**
 * Log estructurado propio (tabla impay_logs). Nada de error_log disperso.
 */
interface Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(LogLevel $level, string $channel, string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $channel, string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $channel, string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $channel, string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $channel, string $message, array $context = []): void;
}
