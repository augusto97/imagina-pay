<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways;

/**
 * Resultado de crear un checkout/suscripción en la pasarela:
 * URL de redirección + referencia externa (preference_id, order id, etc.).
 * Las pasarelas de widget embebido (ePayco Onpage) no redirigen: entregan
 * en $widget los datos que el frontend usa para abrir su checkout JS.
 */
final class CheckoutSession
{
    /**
     * @param array<string, mixed>|null $widget {provider, key, test, data}
     */
    public function __construct(
        public readonly string $redirectUrl,
        public readonly ?string $gatewayRef = null,
        public readonly ?array $widget = null,
    ) {
    }
}
