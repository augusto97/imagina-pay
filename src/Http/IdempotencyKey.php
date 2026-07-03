<?php

declare(strict_types=1);

namespace ImaginaPay\Http;

/**
 * Claves de idempotencia deterministas para POST salientes: la misma
 * operación de dominio produce siempre la misma clave, de modo que un
 * reintento o un job duplicado jamás dupliquen efectos en la pasarela.
 */
final class IdempotencyKey
{
    public static function derive(string ...$parts): string
    {
        return hash('sha256', implode('|', $parts));
    }
}
