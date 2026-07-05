<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways;

use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Subscription;

/**
 * Capacidad extra de las pasarelas modo Tokenized: el BillingEngine
 * dispara cada cobro recurrente contra la fuente de pago guardada.
 */
interface TokenizedGatewayInterface extends GatewayInterface
{
    /**
     * Cobra un periodo contra la fuente guardada. Asíncrono: devuelve el
     * id de la transacción creada; el resultado llega por webhook.
     *
     * @param string $reference Referencia determinista del periodo (idempotencia).
     */
    public function chargeStoredSource(Subscription $subscription, Price $price, string $gatewaySourceId, string $reference): string;
}
