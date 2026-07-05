<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\Wompi;

use ImaginaPay\Exceptions\GatewayException;

/**
 * Verificación del checksum de eventos de Wompi: se concatenan los
 * valores de signature.properties (extraídos DINÁMICAMENTE del evento,
 * en su orden) + timestamp + secreto de eventos → SHA-256 → comparar
 * con signature.checksum en tiempo constante.
 */
final class WompiWebhookVerifier
{
    /**
     * @param array<string, mixed> $body Body JSON del evento.
     * @throws GatewayException Si el checksum es inválido o faltan datos.
     */
    public function verify(array $body, string $eventsSecret): void
    {
        if ($eventsSecret === '') {
            throw new GatewayException('El secreto de eventos de Wompi no está configurado.');
        }

        $signature = is_array($body['signature'] ?? null) ? $body['signature'] : [];
        $checksum = is_string($signature['checksum'] ?? null) ? $signature['checksum'] : '';
        $properties = is_array($signature['properties'] ?? null) ? $signature['properties'] : [];
        $timestamp = $body['timestamp'] ?? null;
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if ($checksum === '' || $properties === [] || !is_scalar($timestamp)) {
            throw new GatewayException('Evento de Wompi sin firma completa (checksum/properties/timestamp).');
        }

        $concatenated = '';

        foreach ($properties as $property) {
            if (!is_string($property)) {
                throw new GatewayException('Evento de Wompi con properties inválidas.');
            }

            $concatenated .= $this->extract($data, $property);
        }

        $expected = hash('sha256', $concatenated . (string) $timestamp . $eventsSecret);

        if (!hash_equals($expected, strtolower($checksum))) {
            throw new GatewayException('Evento de Wompi rechazado: checksum inválido.');
        }
    }

    /**
     * Valor de una ruta con puntos ("transaction.amount_in_cents") dentro
     * de data, como string sin formato (los enteros se concatenan tal cual).
     *
     * @param array<string, mixed> $data
     */
    private function extract(array $data, string $path): string
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw new GatewayException(sprintf('Evento de Wompi sin la propiedad firmada "%s".', $path));
            }

            $value = $value[$segment];
        }

        if (!is_scalar($value)) {
            throw new GatewayException(sprintf('Propiedad firmada "%s" de Wompi no es escalar.', $path));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
