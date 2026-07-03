<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Enums;

enum PriceStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
