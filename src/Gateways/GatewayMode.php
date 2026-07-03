<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways;

/**
 * Modo de operación de una pasarela. Todo el flujo de suscripciones se
 * ramifica según el modo, nunca según el nombre de la pasarela.
 */
enum GatewayMode
{
    /**
     * La pasarela ejecuta el cobro recurrente (Mercado Pago preapproval,
     * PayPal Billing Subscriptions). El plugin solo orquesta estado vía webhooks.
     */
    case HostedSubscription;

    /**
     * La pasarela solo guarda el token del medio de pago (Wompi, v2).
     * El plugin agenda y dispara cada cobro con el BillingEngine (Fase 8).
     */
    case Tokenized;
}
