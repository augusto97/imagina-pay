<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
