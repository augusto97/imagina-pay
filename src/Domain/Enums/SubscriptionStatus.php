<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Active => 'Activa',
            self::PastDue => 'Pago vencido',
            self::Paused => 'Pausada',
            self::Cancelled => 'Cancelada',
            self::Expired => 'Vencida',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }
}
