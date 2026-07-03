<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Enums;

enum PriceInterval: string
{
    case OneTime = 'one_time';
    case Month = 'month';
    case Year = 'year';

    public function isRecurring(): bool
    {
        return $this !== self::OneTime;
    }
}
