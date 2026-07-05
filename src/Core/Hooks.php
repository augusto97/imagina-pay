<?php

declare(strict_types=1);

namespace ImaginaPay\Core;

use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\PaymentLink;
use ImaginaPay\Domain\Entities\Product;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Services\CustomerAccountService;
use ImaginaPay\Domain\Services\ProvisioningService;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Mail\EmailNotifications;

/**
 * Conecta los listeners internos a los hooks de dominio. La provisión
 * corre con prioridad 10 y los emails con 20: así el correo de
 * bienvenida ya encuentra la licencia creada. Los hooks solo delegan;
 * los servicios se resuelven perezosamente.
 */
final class Hooks
{
    private const PROVISION_PRIORITY = 10;
    private const MAIL_PRIORITY = 20;

    public function __construct(private readonly Container $container)
    {
    }

    public function register(): void
    {
        // ── Provisión ────────────────────────────────────────────────
        add_action('impay_subscription_active', function (mixed $subscription, mixed $context = []): void {
            if ($subscription instanceof Subscription) {
                $this->provisioning()->provision($subscription, is_array($context) ? $context : []);
            }
        }, self::PROVISION_PRIORITY, 2);

        add_action('impay_service_suspend', function (mixed $subscription): void {
            if ($subscription instanceof Subscription) {
                $this->provisioning()->suspend($subscription);
            }
        }, self::PROVISION_PRIORITY);

        add_action('impay_subscription_cancelled', function (mixed $subscription): void {
            if ($subscription instanceof Subscription) {
                $this->provisioning()->suspend($subscription);
            }
        }, self::PROVISION_PRIORITY);

        add_action('impay_subscription_expired', function (mixed $subscription): void {
            if ($subscription instanceof Subscription) {
                $this->provisioning()->suspend($subscription);
            }
        }, self::PROVISION_PRIORITY);

        // ── Cuenta WP del cliente (prio 12: entre provisión y emails).
        // También aplica los cambios de perfil pendientes del checkout. ──
        add_action('impay_order_paid', function (mixed $order): void {
            if ($order instanceof Order) {
                $this->container->get(CustomerAccountService::class)->onOrderPaid($order);
            }
        }, 12);

        // ── Emails ───────────────────────────────────────────────────
        add_action('impay_subscription_active', function (mixed $subscription): void {
            if ($subscription instanceof Subscription) {
                $this->mail()->welcome($subscription);
            }
        }, self::MAIL_PRIORITY);

        add_action('impay_payment_approved', function (mixed $payment, mixed $customerId): void {
            if ($payment instanceof GatewayPayment && is_int($customerId)) {
                $this->mail()->receipt($payment, $customerId);
            }
        }, self::MAIL_PRIORITY, 2);

        add_action('impay_dunning_notice', function (mixed $subscription, mixed $day): void {
            if ($subscription instanceof Subscription && is_int($day)) {
                $this->mail()->dunningNotice($subscription, $day);
            }
        }, self::MAIL_PRIORITY, 2);

        add_action('impay_renewal_reminder', function (mixed $subscription, mixed $link, mixed $daysLeft): void {
            if ($subscription instanceof Subscription && $link instanceof PaymentLink && is_int($daysLeft)) {
                $this->mail()->renewalReminder($subscription, $link, $daysLeft);
            }
        }, self::MAIL_PRIORITY, 3);

        add_action('impay_renewal_paid', function (mixed $subscription, mixed $order = null): void {
            if ($subscription instanceof Subscription) {
                $this->mail()->renewalConfirmed($subscription, $order instanceof Order ? $order : null);
            }
        }, self::MAIL_PRIORITY, 2);

        add_action('impay_subscription_cancelled', function (mixed $subscription): void {
            if ($subscription instanceof Subscription) {
                $this->mail()->cancelled($subscription);
            }
        }, self::MAIL_PRIORITY);

        add_action('impay_service_suspend', function (mixed $subscription): void {
            if ($subscription instanceof Subscription) {
                $this->mail()->suspended($subscription);
            }
        }, self::MAIL_PRIORITY);

        add_action('impay_order_paid', function (mixed $order): void {
            if ($order instanceof Order) {
                $this->mail()->adminNewSale($order);
            }
        }, self::MAIL_PRIORITY);

        add_action('impay_manual_task', function (mixed $subscription, mixed $product): void {
            if ($subscription instanceof Subscription && $product instanceof Product) {
                $this->mail()->adminManualTask($subscription, $product);
            }
        }, self::MAIL_PRIORITY, 2);
    }

    private function provisioning(): ProvisioningService
    {
        return $this->container->get(ProvisioningService::class);
    }

    private function mail(): EmailNotifications
    {
        return $this->container->get(EmailNotifications::class);
    }
}
