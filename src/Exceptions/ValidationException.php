<?php

declare(strict_types=1);

namespace ImaginaPay\Exceptions;

final class ValidationException extends ImaginaPayException
{
    /**
     * @param array<string, string> $errors Mensajes por campo.
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Los datos enviados no son válidos.',
    ) {
        parent::__construct($message, 'impay_validacion', 422);
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
