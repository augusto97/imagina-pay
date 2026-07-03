<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways;

use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\PaymentLink;
use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Subscription;

/**
 * Contrato de pasarela de pago. Añadir Wompi/ePayco/Bold = una clase
 * nueva que implemente esta interface, cero cambios en el core.
 */
interface GatewayInterface
{
    /**
     * Identificador único: 'mercadopago' | 'paypal' | ...
     */
    public function id(): string;

    /**
     * Crea un checkout de pago único y devuelve la URL de redirección.
     */
    public function createOneTimeCheckout(Order $order): CheckoutSession;

    /**
     * Crea la suscripción en la pasarela (preapproval / billing subscription).
     */
    public function createSubscription(Subscription $subscription, Price $price): CheckoutSession;

    public function cancelSubscription(Subscription $subscription): void;

    /**
     * Solo pasarelas que lo soporten (Mercado Pago). Consultar supports('pause').
     */
    public function pauseSubscription(Subscription $subscription): void;

    public function resumeSubscription(Subscription $subscription): void;

    /**
     * Valida la firma del webhook. Lanza GatewayException si es inválida.
     */
    public function verifyWebhook(\WP_REST_Request $request): WebhookEvent;

    /**
     * Traduce el evento verificado a eventos de dominio.
     */
    public function handleWebhook(WebhookEvent $event): void;

    /**
     * Estado actual de la suscripción en la pasarela (reconciliación).
     *
     * @return array<string, mixed>
     */
    public function fetchSubscription(string $gatewaySubId): array;

    /**
     * @return array<string, mixed>
     */
    public function fetchPayment(string $gatewayPaymentId): array;

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLink;

    /**
     * Capacidades: 'pause', 'pse', 'trial', 'nequi_recurring', ...
     */
    public function supports(string $feature): bool;

    public function mode(): GatewayMode;
}
