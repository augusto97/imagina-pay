<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Services;

use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\PaymentRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Repositories\WebhookEventRepository;
use ImaginaPay\Rest\Admin\Presenter;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Money;

/**
 * Métricas del dashboard admin: MRR estimado, activos, ingresos del mes,
 * mora, ingresos 12 meses, próximas renovaciones y tareas manuales.
 */
final class MetricsService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PaymentRepository $payments,
        private readonly CustomerRepository $customers,
        private readonly ProductRepository $products,
        private readonly WebhookEventRepository $webhookEvents,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $now = $this->clock->now();
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $yearAgo = $now->modify('-11 months')->modify('first day of this month')->setTime(0, 0);

        $monthly = $this->payments->monthlyRevenue($yearAgo);
        $currentMonth = $now->format('Y-m');

        $monthRevenue = [];

        foreach ($monthly as $row) {
            if ($row['month'] === $currentMonth) {
                $monthRevenue[$row['currency']] = $row['amount'];
            }
        }

        $upcoming = $this->subscriptions->upcomingRenewals($now, 30);
        $manualTasks = $this->subscriptions->withPendingManualTasks();

        $relatedCustomers = $this->customers->findByIds(array_map(
            static fn ($subscription): int => $subscription->customerId,
            array_merge($upcoming, $manualTasks),
        ));
        $relatedProducts = $this->products->findByIds(array_map(
            static fn ($subscription): int => $subscription->productId,
            array_merge($upcoming, $manualTasks),
        ));

        return [
            'mrr' => $this->formatByCurrency($this->subscriptions->mrrByCurrency()),
            'active_subscriptions' => $this->subscriptions->countByStatus(SubscriptionStatus::Active),
            'past_due_subscriptions' => $this->subscriptions->countByStatus(SubscriptionStatus::PastDue),
            'month_revenue' => $this->formatByCurrency($monthRevenue),
            'revenue_12m' => $monthly,
            'upcoming_renewals' => array_map(
                static fn ($subscription): array => Presenter::subscription(
                    $subscription,
                    $relatedCustomers[$subscription->customerId] ?? null,
                    $relatedProducts[$subscription->productId] ?? null,
                ),
                $upcoming,
            ),
            'manual_tasks' => array_map(
                static fn ($subscription): array => Presenter::subscription(
                    $subscription,
                    $relatedCustomers[$subscription->customerId] ?? null,
                    $relatedProducts[$subscription->productId] ?? null,
                ),
                $manualTasks,
            ),
            'webhook_health' => $this->webhookEvents->lastReceivedByGateway(),
            'generated_at' => $monthStart->format('Y-m'),
        ];
    }

    /**
     * @param array<string, int> $amounts currency => unidad mínima.
     * @return list<array{currency: string, amount: int, formatted: string}>
     */
    private function formatByCurrency(array $amounts): array
    {
        $result = [];

        foreach ($amounts as $currency => $amount) {
            $result[] = [
                'currency' => $currency,
                'amount' => $amount,
                'formatted' => Money::of(max(0, $amount), $currency)->format(),
            ];
        }

        return $result;
    }
}
