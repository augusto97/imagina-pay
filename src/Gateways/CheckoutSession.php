<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways;

/**
 * Resultado de crear un checkout/suscripción en la pasarela:
 * URL de redirección + referencia externa (preference_id, order id, etc.).
 */
final class CheckoutSession
{
    public function __construct(
        public readonly string $redirectUrl,
        public readonly ?string $gatewayRef = null,
    ) {
    }
}
