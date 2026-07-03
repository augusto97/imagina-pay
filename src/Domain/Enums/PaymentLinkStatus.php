<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Enums;

enum PaymentLinkStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Expired = 'expired';
    case Void = 'void';
}
