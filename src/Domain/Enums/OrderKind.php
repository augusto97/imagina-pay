<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Enums;

enum OrderKind: string
{
    case Purchase = 'purchase';
    case Renewal = 'renewal';
    case SubscriptionInitial = 'subscription_initial';
}
