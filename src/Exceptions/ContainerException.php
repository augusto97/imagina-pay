<?php

declare(strict_types=1);

namespace ImaginaPay\Exceptions;

final class ContainerException extends ImaginaPayException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'impay_contenedor', 500, $previous);
    }
}
