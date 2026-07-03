<?php

declare(strict_types=1);

namespace ImaginaPay\Jobs;

use ImaginaPay\Core\Container;
use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Services\DunningService;
use ImaginaPay\Domain\Services\MaintenanceService;
use ImaginaPay\Domain\Services\ReconciliationService;
use ImaginaPay\Domain\Services\RenewalService;
use ImaginaPay\Webhooks\WebhookProcessor;

/**
 * Registra los hooks de jobs y su calendario en Action Scheduler.
 * Los hooks solo delegan: la resolución del servicio ocurre de forma
 * perezosa dentro del callback (cero costo en requests sin jobs).
 */
final class Scheduler
{
    private const GROUP = 'imagina-pay';

    /**
     * hook => [hora UTC de arranque, intervalo en segundos].
     */
    private const RECURRING = [
        'impay_reconcile' => ['03:00', DAY_IN_SECONDS],
        'impay_expire_stale' => ['04:00', DAY_IN_SECONDS],
        'impay_renewal_reminders' => ['08:00', DAY_IN_SECONDS],
        'impay_dunning_notices' => ['09:00', DAY_IN_SECONDS],
        'impay_cleanup' => ['02:00', WEEK_IN_SECONDS],
    ];

    public function __construct(private readonly Container $container)
    {
    }

    public function register(): void
    {
        add_action('impay_process_webhook', function (int|string $eventRowId): void {
            $this->container->get(WebhookProcessor::class)->process((int) $eventRowId);
        });

        add_action('impay_reconcile', function (): void {
            $this->container->get(ReconciliationService::class)->reconcile();
        });

        add_action('impay_expire_stale', function (): void {
            $this->container->get(ReconciliationService::class)->expireStale();
        });

        add_action('impay_renewal_reminders', function (): void {
            $this->container->get(RenewalService::class)->sendReminders();
        });

        add_action('impay_dunning_notices', function (): void {
            $this->container->get(DunningService::class)->run();
        });

        add_action('impay_cleanup', function (): void {
            $this->container->get(MaintenanceService::class)->cleanup();
        });

        // Los orders pagados de productos annual_hybrid crean su suscripción lógica.
        add_action('impay_order_paid', function (mixed $order): void {
            if ($order instanceof Order) {
                $this->container->get(RenewalService::class)->handleOrderPaid($order);
            }
        });

        add_action('init', function (): void {
            $this->scheduleRecurring();
        });
    }

    /**
     * Agenda los jobs recurrentes si aún no existen (idempotente).
     */
    private function scheduleRecurring(): void
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }

        foreach (self::RECURRING as $hook => [$startTime, $interval]) {
            if (as_has_scheduled_action($hook, null, self::GROUP)) {
                continue;
            }

            $timestamp = strtotime('tomorrow ' . $startTime . ' UTC');

            as_schedule_recurring_action(
                $timestamp === false ? time() + $interval : $timestamp,
                $interval,
                $hook,
                [],
                self::GROUP,
            );
        }
    }
}
