<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\MercadoPago;

use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Support\Clock;

/**
 * Verificación obligatoria de la firma de webhooks de Mercado Pago.
 *
 * Header x-signature: "ts=...,v1=...". Manifest:
 * "id:{data.id};request-id:{x-request-id};ts:{ts};" → HMAC-SHA256 con el
 * secret → comparación constante con v1. Ventana máxima de 5 minutos.
 */
final class MercadoPagoWebhookVerifier
{
    private const MAX_AGE_SECONDS = 300;

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * @throws GatewayException Si la firma es inválida, vieja o faltan datos.
     */
    public function verify(string $xSignature, string $xRequestId, string $dataId, string $secret): void
    {
        if ($secret === '') {
            throw new GatewayException('El secret de webhooks de Mercado Pago no está configurado.');
        }

        if ($xSignature === '') {
            throw new GatewayException('Webhook sin header x-signature.');
        }

        $parts = [];

        foreach (explode(',', $xSignature) as $segment) {
            $pair = explode('=', trim($segment), 2);

            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';

        if ($ts === '' || $v1 === '' || preg_match('/^\d+$/', $ts) !== 1) {
            throw new GatewayException('Header x-signature con formato inválido.');
        }

        $timestamp = (int) $ts;

        // MP puede enviar ts en milisegundos; normalizar a segundos.
        if ($timestamp > 9999999999) {
            $timestamp = intdiv($timestamp, 1000);
        }

        $age = abs($this->clock->now()->getTimestamp() - $timestamp);

        if ($age > self::MAX_AGE_SECONDS) {
            throw new GatewayException('Webhook rechazado: firma con más de 5 minutos de antigüedad.');
        }

        // data.id alfanumérico va en minúsculas según la documentación de MP.
        $manifest = sprintf('id:%s;request-id:%s;ts:%s;', strtolower($dataId), $xRequestId, $ts);
        $expected = hash_hmac('sha256', $manifest, $secret);

        if (!hash_equals($expected, $v1)) {
            throw new GatewayException('Webhook rechazado: firma inválida.');
        }
    }
}
