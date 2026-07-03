<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Admin;

use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\PaymentRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Exceptions\NotFoundException;
use ImaginaPay\Rest\AbstractController;
use ImaginaPay\Rest\Middleware\CapabilityMiddleware;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Support\Logger;
use ImaginaPay\Support\Money;

/**
 * Clientes (admin): listado con búsqueda y ficha 360 (datos fiscales,
 * suscripciones, pagos, LTV).
 */
final class CustomersController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly CustomerRepository $customers,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PaymentRepository $payments,
        private readonly ProductRepository $products,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $permissions = $this->permissions(new NonceMiddleware(), new CapabilityMiddleware('manage_impay'));

        register_rest_route(self::API_NAMESPACE, '/admin/customers', [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->index($r)),
            'permission_callback' => $permissions,
        ]);

        register_rest_route(self::API_NAMESPACE, '/admin/customers/(?P<uuid>[0-9a-fA-F-]{36})', [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->show($r)),
            'permission_callback' => $permissions,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function index(\WP_REST_Request $request): array
    {
        $result = $this->customers->list(
            page: max(1, (int) $request->get_param('page')),
            perPage: min(100, max(1, (int) ($request->get_param('per_page') ?: 20))),
            search: is_string($request->get_param('search')) ? $request->get_param('search') : '',
        );

        return [
            'items' => array_map(
                static fn ($customer): array => Presenter::customer($customer),
                $result['items'],
            ),
            'total' => $result['total'],
        ];
    }

    /**
     * Ficha 360.
     *
     * @return array<string, mixed>
     */
    private function show(\WP_REST_Request $request): array
    {
        $customer = $this->customers->findByUuid(strtolower((string) $request->get_param('uuid')));

        if ($customer === null) {
            throw new NotFoundException('Cliente no encontrado.');
        }

        $subscriptions = $this->subscriptions->findByCustomer($customer->id);
        $productsById = $this->products->findByIds(array_map(
            static fn (Subscription $s): int => $s->productId,
            $subscriptions,
        ));

        $paymentsResult = $this->payments->list(
            page: 1,
            perPage: 50,
            status: \ImaginaPay\Domain\Enums\PaymentStatus::Approved,
            customerId: $customer->id,
        );

        $ltv = [];

        foreach ($paymentsResult['sums'] as $currency => $amount) {
            $ltv[] = [
                'currency' => $currency,
                'amount' => $amount,
                'formatted' => Money::of(max(0, $amount), $currency)->format(),
            ];
        }

        return [
            'customer' => Presenter::customer($customer),
            'subscriptions' => array_map(
                static fn (Subscription $s): array => Presenter::subscription($s, null, $productsById[$s->productId] ?? null),
                $subscriptions,
            ),
            'payments' => array_map(
                static fn ($payment): array => Presenter::payment($payment),
                $paymentsResult['items'],
            ),
            'ltv' => $ltv,
        ];
    }
}
