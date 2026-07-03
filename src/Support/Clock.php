<?php

declare(strict_types=1);

namespace ImaginaPay\Support;

/**
 * Fuente de tiempo inyectable. Toda fecha del sistema se maneja en UTC;
 * la conversión a America/Bogota ocurre solo en presentación.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
