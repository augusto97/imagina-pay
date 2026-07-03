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

    /**
     * Duración de un periodo de facturación.
     */
    public function period(): \DateInterval
    {
        return match ($this) {
            self::Month => new \DateInterval('P1M'),
            self::Year => new \DateInterval('P1Y'),
            self::OneTime => throw new \LogicException('Un precio de pago único no tiene periodo de facturación.'),
        };
    }
}
