<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Enums;

enum ProductStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Draft = 'draft';
}
