<?php

declare(strict_types=1);

namespace ImaginaPay\Exceptions;

final class CryptoException extends ImaginaPayException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'impay_cifrado', 500, $previous);
    }
}
