<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Enums;

enum ProductType: string
{
    case OneTime = 'one_time';
    case Subscription = 'subscription';
    case AnnualHybrid = 'annual_hybrid';
}
