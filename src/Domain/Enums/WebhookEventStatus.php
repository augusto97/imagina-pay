<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Enums;

enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
