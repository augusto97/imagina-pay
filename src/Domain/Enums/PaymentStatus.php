<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Enums;

enum PaymentStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Refunded = 'refunded';
    case ChargedBack = 'charged_back';
}
