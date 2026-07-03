<?php

declare(strict_types=1);

namespace ImaginaPay\Support;

use ImaginaPay\Domain\Repositories\LogRepository;

final class DatabaseLogger implements Logger
{
    public function __construct(
        private readonly LogRepository $logs,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(LogLevel $level, string $channel, string $message, array $context = []): void
    {
        $this->logs->insert($level, $channel, $message, $context, $this->clock->now());
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $channel, string $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $channel, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $channel, string $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $channel, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $channel, string $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $channel, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $channel, string $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $channel, $message, $context);
    }
}
