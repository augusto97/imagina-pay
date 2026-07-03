<?php

declare(strict_types=1);

namespace ImaginaPay\Exceptions;

use ImaginaPay\Domain\Enums\SubscriptionStatus;

final class InvalidTransitionException extends ImaginaPayException
{
    public static function between(SubscriptionStatus $from, SubscriptionStatus $to): self
    {
        return new self(sprintf(
            'Transición de suscripción inválida: "%s" → "%s".',
            $from->value,
            $to->value,
        ));
    }

    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'impay_transicion_invalida', 409, $previous);
    }
}
