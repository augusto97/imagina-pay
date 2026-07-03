<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Admin;

use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\PaymentRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\SubscriptionService;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Exceptions\NotFoundException;
use ImaginaPay\Exceptions\ValidationException;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Rest\AbstractController;
use ImaginaPay\Rest\Middleware\CapabilityMiddleware;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Listado, detalle y acciones de suscripciones (admin).
 */
final class SubscriptionsController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly SubscriptionRepository $subscriptions,
        private readonly CustomerRepository $customers,
        private readonly ProductRepository $products,
        private readonly PaymentRepository $payments,
        private readonly SubscriptionService $subscriptionService,
        private readonly SubscriptionStateMachine $stateMachine,
        private readonly GatewayRegistry $gateways,
        private readonly Clock $clock,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $permissions = $this->permissions(new NonceMiddleware(), new CapabilityMiddleware('manage_impay'));
        $base = '/admin/subscriptions/(?P<uuid>[0-9a-fA-F-]{36})';

        register_rest_route(self::API_NAMESPACE, '/admin/subscriptions', [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->index($r)),
            'permission_callback' => $permissions,
        ]);

        register_rest_route(self::API_NAMESPACE, $base, [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->show($r)),
            'permission_callback' => $permissions,
        ]);

        foreach (['cancel', 'pause', 'resume', 'extend'] as $action) {
            register_rest_route(self::API_NAMESPACE, $base . '/' . $action, [
                'methods' => 'POST',
                'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(
                    fn (): array => $this->action($r, $action),
                ),
                'permission_callback' => $permissions,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function index(\WP_REST_Request $request): array
    {
        $statusParam = $request->get_param('status');
        $productParam = $request->get_param('product');

        $productId = null;

        if (is_string($productParam) && $productParam !== '') {
            $productId = $this->products->findByUuid(strtolower($productParam))?->id ?? -1;
        }

        $result = $this->subscriptions->list(
            page: max(1, (int) $request->get_param('page')),
            perPage: min(100, max(1, (int) ($request->get_param('per_page') ?: 20))),
            status: is_string($statusParam) && $statusParam !== '' ? SubscriptionStatus::tryFrom($statusParam) : null,
            gateway: is_string($request->get_param('gateway')) ? $request->get_param('gateway') : null,
            productId: $productId,
            search: is_string($request->get_param('search')) ? $request->get_param('search') : '',
        );

        $customersById = $this->customers->findByIds(array_map(
            static fn (Subscription $s): int => $s->customerId,
            $result['items'],
        ));
        $productsById = $this->products->findByIds(array_map(
            static fn (Subscription $s): int => $s->productId,
            $result['items'],
        ));

        return [
            'items' => array_map(
                static fn (Subscription $s): array => Presenter::subscription(
                    $s,
                    $customersById[$s->customerId] ?? null,
                    $productsById[$s->productId] ?? null,
                ),
                $result['items'],
            ),
            'total' => $result['total'],
        ];
    }

    /**
     * Detalle con pagos (drawer del admin).
     *
     * @return array<string, mixed>
     */
    private function show(\WP_REST_Request $request): array
    {
        $subscription = $this->findOrFail($request);
        $customer = $this->customers->find($subscription->customerId);
        $product = $this->products->find($subscription->productId);

        return [
            'subscription' => Presenter::subscription($subscription, $customer, $product),
            'payments' => array_map(
                static fn ($payment): array => Presenter::payment($payment),
                $this->payments->findBySubscription($subscription->id),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function action(\WP_REST_Request $request, string $action): array
    {
        $subscription = $this->findOrFail($request);
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];

        $updated = match ($action) {
            'cancel' => $this->subscriptionService->cancel(
                $subscription,
                (bool) ($body['at_period_end'] ?? true),
                ['source' => 'admin'],
            ),
            'pause' => $this->pause($subscription),
            'resume' => $this->resume($subscription),
            'extend' => $this->extend($subscription, (int) ($body['days'] ?? 0)),
            default => throw new NotFoundException('Acción desconocida.'),
        };

        $this->logger->info('admin', sprintf('Acción "%s" sobre la suscripción %s.', $action, $subscription->uuid));

        return [
            'subscription' => Presenter::subscription(
                $this->subscriptions->find($updated->id) ?? $updated,
                $this->customers->find($updated->customerId),
                $this->products->find($updated->productId),
            ),
        ];
    }

    private function pause(Subscription $subscription): Subscription
    {
        $gateway = $this->gateways->get($subscription->gateway);

        if (!$gateway->supports('pause')) {
            throw new ValidationException(['gateway' => 'Esta pasarela no soporta pausar suscripciones.']);
        }

        $gateway->pauseSubscription($subscription);

        return $this->stateMachine->transition($subscription, SubscriptionStatus::Paused, ['source' => 'admin']);
    }

    private function resume(Subscription $subscription): Subscription
    {
        $gateway = $this->gateways->get($subscription->gateway);

        if (!$gateway->supports('pause')) {
            throw new ValidationException(['gateway' => 'Esta pasarela no soporta reanudar suscripciones.']);
        }

        $gateway->resumeSubscription($subscription);

        return $this->stateMachine->transition($subscription, SubscriptionStatus::Active, ['source' => 'admin']);
    }

    private function extend(Subscription $subscription, int $days): Subscription
    {
        if ($days < 1 || $days > 366) {
            throw new ValidationException(['days' => 'Indica entre 1 y 366 días.']);
        }

        $now = $this->clock->now();
        $base = $subscription->currentPeriodEnd !== null && $subscription->currentPeriodEnd > $now
            ? $subscription->currentPeriodEnd
            : $now;

        $this->subscriptions->extendPeriod(
            $subscription->id,
            $subscription->currentPeriodStart ?? $now,
            $base->add(new \DateInterval('P' . $days . 'D')),
            $now,
        );

        return $this->subscriptions->find($subscription->id) ?? $subscription;
    }

    private function findOrFail(\WP_REST_Request $request): Subscription
    {
        $subscription = $this->subscriptions->findByUuid(strtolower((string) $request->get_param('uuid')));

        if ($subscription === null) {
            throw new NotFoundException('Suscripción no encontrada.');
        }

        return $subscription;
    }
}
