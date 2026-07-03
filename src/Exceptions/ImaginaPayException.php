<?php

declare(strict_types=1);

namespace ImaginaPay\Exceptions;

/**
 * Excepción base del dominio. Toda excepción propia lleva un código
 * estable (para la respuesta JSON {code, message}) y un status HTTP.
 */
class ImaginaPayException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'impay_error',
        private readonly int $httpStatus = 500,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
