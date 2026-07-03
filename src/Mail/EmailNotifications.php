<?php

declare(strict_types=1);

namespace ImaginaPay\Mail;

use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\PaymentLink;
use ImaginaPay\Domain\Entities\Product;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Money;

/**
 * Emails transaccionales (sección 12), colgados de los hooks de dominio.
 * Todo texto en español (es_CO); fechas presentadas en America/Bogota.
 */
class EmailNotifications
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly CustomerRepository $customers,
        private readonly ProductRepository $products,
        private readonly SubscriptionRepository $subscriptions,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Bienvenida + accesos/licencia. Solo la primera activación
     * (flag welcome_sent en el meta de la suscripción).
     */
    public function welcome(Subscription $subscription): void
    {
        // Releer: la provisión (prioridad 10) pudo escribir la licencia.
        $fresh = $this->subscriptions->find($subscription->id) ?? $subscription;

        if (!empty($fresh->meta['welcome_sent'])) {
            return;
        }

        $customer = $this->customers->find($fresh->customerId);
        $product = $this->products->find($fresh->productId);

        if ($customer === null || $product === null) {
            return;
        }

        $paragraphs = [
            sprintf('Hola %s,', esc_html($customer->fullName)),
            sprintf('Tu suscripción a <strong>%s</strong> ya está activa. ¡Gracias por confiar en nosotros!', esc_html($product->name)),
        ];

        $licenseKey = $fresh->meta['license_key'] ?? null;

        if (is_string($licenseKey) && $licenseKey !== '') {
            $paragraphs[] = sprintf(
                'Tu clave de licencia es: <code style="background:#FAFAFA;border:1px solid #E4E4E7;border-radius:6px;padding:2px 8px;">%s</code>',
                esc_html($licenseKey),
            );
        }

        if ($fresh->currentPeriodEnd !== null) {
            $paragraphs[] = sprintf('Tu servicio está vigente hasta el %s.', $this->formatDate($fresh->currentPeriodEnd));
        }

        $this->mailer->send(
            $customer->email,
            sprintf('Bienvenido a %s', $product->name),
            'Tu servicio está activo',
            $paragraphs,
            'Ir a mi cuenta',
            $this->portalUrl(),
        );

        $meta = $fresh->meta ?? [];
        $meta['welcome_sent'] = true;
        $this->subscriptions->updateMeta($fresh->id, $meta, $this->clock->now());
    }

    /**
     * Recibo de pago (hook impay_payment_approved).
     */
    public function receipt(GatewayPayment $payment, int $customerId): void
    {
        $customer = $this->customers->find($customerId);

        if ($customer === null) {
            return;
        }

        $amount = Money::of(max(0, $payment->amount), $payment->currency)->format();

        $this->mailer->send(
            $customer->email,
            'Recibo de tu pago',
            'Pago recibido',
            [
                sprintf('Hola %s,', esc_html($customer->fullName)),
                sprintf('Recibimos tu pago por <strong>%s</strong>.', esc_html($amount)),
                sprintf(
                    'Fecha: %s · Método: %s · Referencia: %s',
                    $this->formatDate($payment->paidAt ?? $this->clock->now()),
                    esc_html($payment->method ?? 'N/D'),
                    esc_html($payment->gatewayPaymentId),
                ),
                'Puedes descargar tu recibo desde el portal de cliente.',
            ],
            'Ver mis pagos',
            $this->portalUrl(),
        );
    }

    /**
     * Aviso de pago fallido (día 0/3/7). El día 0 también notifica al admin.
     */
    public function dunningNotice(Subscription $subscription, int $day): void
    {
        $customer = $this->customers->find($subscription->customerId);
        $product = $this->products->find($subscription->productId);

        if ($customer === null || $product === null) {
            return;
        }

        $message = match (true) {
            $day >= 7 => 'No hemos podido procesar tu pago y tu servicio ha sido suspendido. Actualiza tu método de pago para reactivarlo.',
            $day >= 3 => 'Seguimos sin poder procesar tu pago. Por favor verifica tu método de pago para evitar la suspensión del servicio.',
            default => 'No pudimos procesar el último cobro de tu suscripción. La pasarela lo reintentará automáticamente; verifica que tu método de pago esté al día.',
        };

        $this->mailer->send(
            $customer->email,
            sprintf('Problema con el pago de %s', $product->name),
            'No pudimos procesar tu pago',
            [
                sprintf('Hola %s,', esc_html($customer->fullName)),
                esc_html($message),
            ],
            'Revisar mi suscripción',
            $this->portalUrl(),
        );

        if ($day === 0) {
            $this->mailer->sendToAdmin(
                sprintf('[Imagina Pay] Pago fallido: %s', $product->name),
                'Pago fallido',
                [sprintf(
                    'La suscripción %s de %s (%s) tiene un pago fallido.',
                    esc_html($subscription->uuid),
                    esc_html($customer->fullName),
                    esc_html($customer->email),
                )],
            );
        }
    }

    /**
     * Recordatorio de renovación anual (30/15/5/0 días).
     */
    public function renewalReminder(Subscription $subscription, PaymentLink $link, int $daysLeft): void
    {
        $customer = $this->customers->find($subscription->customerId);
        $product = $this->products->find($subscription->productId);

        if ($customer === null || $product === null) {
            return;
        }

        $when = match (true) {
            $daysLeft <= 0 => 'vence hoy',
            $daysLeft === 1 => 'vence mañana',
            default => sprintf('vence en %d días', $daysLeft),
        };

        $this->mailer->send(
            $customer->email,
            sprintf('Tu %s %s', $product->name, $when),
            'Es hora de renovar',
            [
                sprintf('Hola %s,', esc_html($customer->fullName)),
                sprintf(
                    'Tu servicio <strong>%s</strong> %s (%s). Renueva ahora para no perder continuidad.',
                    esc_html($product->name),
                    esc_html($when),
                    $subscription->currentPeriodEnd !== null ? $this->formatDate($subscription->currentPeriodEnd) : '',
                ),
            ],
            'Renovar ahora',
            $link->url,
        );
    }

    /**
     * Renovación confirmada (link pagado).
     */
    public function renewalConfirmed(Subscription $subscription, ?Order $order): void
    {
        $fresh = $this->subscriptions->find($subscription->id) ?? $subscription;
        $customer = $this->customers->find($fresh->customerId);
        $product = $this->products->find($fresh->productId);

        if ($customer === null || $product === null) {
            return;
        }

        $paragraphs = [
            sprintf('Hola %s,', esc_html($customer->fullName)),
            sprintf('Tu renovación de <strong>%s</strong> fue confirmada. ¡Gracias!', esc_html($product->name)),
        ];

        if ($fresh->currentPeriodEnd !== null) {
            $paragraphs[] = sprintf('Tu servicio queda vigente hasta el %s.', $this->formatDate($fresh->currentPeriodEnd));
        }

        $this->mailer->send(
            $customer->email,
            sprintf('Renovación confirmada: %s', $product->name),
            'Renovación confirmada',
            $paragraphs,
            'Ir a mi cuenta',
            $this->portalUrl(),
        );
    }

    /**
     * Suscripción cancelada.
     */
    public function cancelled(Subscription $subscription): void
    {
        $customer = $this->customers->find($subscription->customerId);
        $product = $this->products->find($subscription->productId);

        if ($customer === null || $product === null) {
            return;
        }

        $this->mailer->send(
            $customer->email,
            sprintf('Suscripción cancelada: %s', $product->name),
            'Suscripción cancelada',
            [
                sprintf('Hola %s,', esc_html($customer->fullName)),
                sprintf('Tu suscripción a <strong>%s</strong> fue cancelada. No se realizarán más cobros.', esc_html($product->name)),
                'Si fue un error o quieres volver, estaremos felices de ayudarte.',
            ],
        );
    }

    /**
     * Servicio suspendido (mora día 7 o vencimiento anual +7).
     */
    public function suspended(Subscription $subscription): void
    {
        $customer = $this->customers->find($subscription->customerId);
        $product = $this->products->find($subscription->productId);

        if ($customer === null || $product === null) {
            return;
        }

        $this->mailer->send(
            $customer->email,
            sprintf('Servicio suspendido: %s', $product->name),
            'Tu servicio fue suspendido',
            [
                sprintf('Hola %s,', esc_html($customer->fullName)),
                sprintf(
                    'Tu servicio <strong>%s</strong> fue suspendido por falta de pago. Ponte al día para reactivarlo de inmediato.',
                    esc_html($product->name),
                ),
            ],
            'Regularizar mi pago',
            $this->portalUrl(),
        );
    }

    /**
     * Establecer contraseña (se invoca al crear el usuario WP, Fase 6).
     */
    public function passwordSetup(string $email, string $name, string $setupUrl): void
    {
        $this->mailer->send(
            $email,
            'Crea tu contraseña',
            'Tu cuenta está lista',
            [
                sprintf('Hola %s,', esc_html($name)),
                'Creamos una cuenta para que gestiones tus servicios, pagos y licencias desde el portal de cliente.',
                'Define tu contraseña con el siguiente botón:',
            ],
            'Crear contraseña',
            $setupUrl,
        );
    }

    /**
     * Admin: venta nueva (hook impay_order_paid).
     */
    public function adminNewSale(Order $order): void
    {
        $customer = $this->customers->find($order->customerId);
        $product = $this->products->find($order->productId);
        $amount = Money::of($order->amount, $order->currency)->format();

        $this->mailer->sendToAdmin(
            sprintf('[Imagina Pay] Venta nueva: %s', $product?->name ?? 'producto'),
            'Venta nueva',
            [sprintf(
                '%s (%s) pagó <strong>%s</strong> por %s.',
                esc_html($customer?->fullName ?? 'Cliente'),
                esc_html($customer?->email ?? ''),
                esc_html($amount),
                esc_html($product?->name ?? ''),
            )],
        );
    }

    /**
     * Admin: tarea de provisión manual (hook impay_manual_task).
     */
    public function adminManualTask(Subscription $subscription, Product $product): void
    {
        $customer = $this->customers->find($subscription->customerId);

        $this->mailer->sendToAdmin(
            sprintf('[Imagina Pay] Provisión manual pendiente: %s', $product->name),
            'Tarea de provisión pendiente',
            [sprintf(
                'La suscripción %s de %s (%s) requiere provisión manual de <strong>%s</strong>.',
                esc_html($subscription->uuid),
                esc_html($customer?->fullName ?? 'Cliente'),
                esc_html($customer?->email ?? ''),
                esc_html($product->name),
            )],
        );
    }

    /**
     * Presentación en hora de Colombia (los datos viven en UTC).
     */
    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('America/Bogota'))->format('d/m/Y');
    }

    private function portalUrl(): string
    {
        $pageId = (int) get_option('impay_page_portal', 0);
        $url = $pageId > 0 ? get_permalink($pageId) : false;

        return is_string($url) ? $url : home_url('/portal-cliente/');
    }
}
