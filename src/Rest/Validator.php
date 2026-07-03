<?php

declare(strict_types=1);

namespace ImaginaPay\Rest;

use ImaginaPay\Exceptions\ValidationException;
use ImaginaPay\Support\Uuid;

/**
 * Validación de entrada con esquemas centralizados. Nunca $_POST directo:
 * los controllers validan el body JSON contra un esquema declarativo.
 *
 * Esquema: ['campo' => ['required' => true, 'type' => 'email', 'max' => 190, 'enum' => [...]]]
 * Tipos soportados: string | email | int | bool | array | uuid.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $schema
     * @return array<string, mixed> Solo los campos declarados en el esquema, saneados.
     * @throws ValidationException
     */
    public function validate(array $data, array $schema): array
    {
        $errors = [];
        $clean = [];

        foreach ($schema as $field => $rules) {
            $required = (bool) ($rules['required'] ?? false);
            $exists = array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '';

            if (!$exists) {
                if ($required) {
                    $errors[$field] = 'Este campo es obligatorio.';
                }

                continue;
            }

            $value = $data[$field];
            $type = is_string($rules['type'] ?? null) ? $rules['type'] : 'string';
            $validated = $this->validateType($value, $type, $field, $errors);

            if ($validated === null) {
                continue;
            }

            if (isset($rules['max']) && is_int($rules['max']) && is_string($validated) && mb_strlen($validated) > $rules['max']) {
                $errors[$field] = sprintf('Máximo %d caracteres.', $rules['max']);
                continue;
            }

            if (isset($rules['enum']) && is_array($rules['enum']) && !in_array($validated, $rules['enum'], true)) {
                $errors[$field] = 'Valor no permitido.';
                continue;
            }

            $clean[$field] = $validated;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }

    /**
     * @param array<string, string> $errors
     */
    private function validateType(mixed $value, string $type, string $field, array &$errors): mixed
    {
        switch ($type) {
            case 'string':
                if (!is_string($value)) {
                    $errors[$field] = 'Debe ser texto.';

                    return null;
                }

                return sanitize_text_field($value);

            case 'email':
                if (!is_string($value) || !is_email($value)) {
                    $errors[$field] = 'Correo electrónico inválido.';

                    return null;
                }

                return sanitize_email($value);

            case 'int':
                if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
                    $errors[$field] = 'Debe ser un número entero.';

                    return null;
                }

                return (int) $value;

            case 'bool':
                if (!is_bool($value)) {
                    $errors[$field] = 'Debe ser verdadero o falso.';

                    return null;
                }

                return $value;

            case 'array':
                if (!is_array($value)) {
                    $errors[$field] = 'Formato inválido.';

                    return null;
                }

                return $value;

            case 'uuid':
                if (!is_string($value) || !Uuid::isValid($value)) {
                    $errors[$field] = 'Identificador inválido.';

                    return null;
                }

                return strtolower($value);

            default:
                $errors[$field] = 'Tipo de validación desconocido.';

                return null;
        }
    }
}
