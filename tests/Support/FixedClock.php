<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Support;

use ImaginaPay\Support\Clock;

final class FixedClock implements Clock
{
    public function __construct(private readonly \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
