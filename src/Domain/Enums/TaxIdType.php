<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Enums;

enum TaxIdType: string
{
    case CC = 'CC';
    case NIT = 'NIT';
    case CE = 'CE';
    case PAS = 'PAS';
    case RUT = 'RUT';
    case OTRO = 'OTRO';
}
