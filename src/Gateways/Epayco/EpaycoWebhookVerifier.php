<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways\Epayco;

use ImaginaPay\Exceptions\GatewayException;

/**
 * Verificación obligatoria de la firma de la URL de confirmación de
 * ePayco: x_signature = SHA-256 de
 * "{p_cust_id_cliente}^{p_key}^{x_ref_payco}^{x_transaction_id}^{x_amount}^{x_currency_code}".
 * Los valores se usan EXACTAMENTE como llegan (sin reformatear) porque
 * cualquier cambio invalida el hash.
 */
final class EpaycoWebhookVerifier
{
    /**
     * @param array<string, mixed> $params Body de la confirmación (form-encoded).
     * @throws GatewayException Si la firma es inválida o faltan datos.
     */
    public function verify(array $params, string $custId, string $pKey): void
    {
        if ($custId === '' || $pKey === '') {
            throw new GatewayException('Las credenciales de ePayco (P_CUST_ID / P_KEY) no están configuradas.');
        }

        $refPayco = $this->stringParam($params, 'x_ref_payco');
        $transactionId = $this->stringParam($params, 'x_transaction_id');
        $amount = $this->stringParam($params, 'x_amount');
        $currency = $this->stringParam($params, 'x_currency_code');
        $signature = $this->stringParam($params, 'x_signature');

        if ($refPayco === '' || $signature === '') {
            throw new GatewayException('Confirmación de ePayco sin x_ref_payco o x_signature.');
        }

        $expected = hash('sha256', sprintf(
            '%s^%s^%s^%s^%s^%s',
            $custId,
            $pKey,
            $refPayco,
            $transactionId,
            $amount,
            $currency,
        ));

        if (!hash_equals($expected, strtolower($signature))) {
            throw new GatewayException('Confirmación de ePayco rechazada: firma inválida.');
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
