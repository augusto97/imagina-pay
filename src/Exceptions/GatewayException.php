<?php

declare(strict_types=1);

namespace ImaginaPay\Exceptions;

final class GatewayException extends ImaginaPayException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'impay_pasarela', 502, $previous);
    }
}
