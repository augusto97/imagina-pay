<?php

declare(strict_types=1);

namespace ImaginaPay\Exceptions;

final class NotFoundException extends ImaginaPayException
{
    public function __construct(string $message = 'Recurso no encontrado.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'impay_no_encontrado', 404, $previous);
    }
}
