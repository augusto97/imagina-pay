<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Portal;

use ImaginaPay\Domain\Entities\Customer;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\PaymentRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\SubscriptionService;
use ImaginaPay\Exceptions\ImaginaPayException;
use ImaginaPay\Exceptions\NotFoundException;
use ImaginaPay\Rest\AbstractController;
use ImaginaPay\Rest\Admin\Presenter;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Rest\Validator;
use ImaginaPay\Support\Logger;

/**
 * Portal del cliente (/me/*). Autorización: usuario WP logueado con
 * customer vinculado por wp_user_id; cada recurso se filtra por ese
 * customer (jamás se accede a datos de otros).
 */
final class PortalController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly CustomerRepository $customers,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PaymentRepository $payments,
        private readonly PaymentLinkRepository $paymentLinks,
        private readonly ProductRepository $products,
        private readonly SubscriptionService $subscriptionService,
        private readonly Validator $validator,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $permissions = $this->permissions(new NonceMiddleware());

        register_rest_route(self::API_NAMESPACE, '/me', [
            [
                'methods' => 'GET',
                'callback' => fn (): \WP_REST_Response => $this->handle(fn (): array => $this->profile()),
                'permission_callback' => $permissions,
            ],
            [
                'methods' => 'PUT',
                'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->updateProfile($r)),
                'permission_callback' => $permissions,
            ],
        ]);

        register_rest_route(self::API_NAMESPACE, '/me/subscriptions', [
            'methods' => 'GET',
            'callback' => fn (): \WP_REST_Response => $this->handle(fn (): array => $this->mySubscriptions()),
            'permission_callback' => $permissions,
        ]);

        register_rest_route(self::API_NAMESPACE, '/me/subscriptions/(?P<uuid>[0-9a-fA-F-]{36})/cancel', [
            'methods' => 'POST',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->cancelSubscription($r)),
            'permission_callback' => $permissions,
        ]);

        register_rest_route(self::API_NAMESPACE, '/me/payments', [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->myPayments($r)),
            'permission_callback' => $permissions,
        ]);

        register_rest_route(self::API_NAMESPACE, '/me/payment-links', [
            'methods' => 'GET',
            'callback' => fn (): \WP_REST_Response => $this->handle(fn (): array => $this->myPaymentLinks()),
            'permission_callback' => $permissions,
        ]);
    }

    /**
     * Customer del usuario logueado. 401/403 en JSON de dominio.
     */
    private function currentCustomer(): Customer
    {
        if (!is_user_logged_in()) {
            throw new ImaginaPayException('Debes iniciar sesión.', 'impay_no_autenticado', 401);
        }

        $customer = $this->customers->findByWpUserId(get_current_user_id());

        if ($customer === null) {
            throw new ImaginaPayException(
                'Tu usuario no tiene una cuenta de cliente asociada.',
                'impay_sin_cliente',
                403,
            );
        }

        return $customer;
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(): array
    {
        return ['customer' => Presenter::customer($this->currentCustomer())];
    }

    /**
     * @return array<string, mixed>
     */
    private function updateProfile(\WP_REST_Request $request): array
    {
        $customer = $this->currentCustomer();
        $body = $request->get_json_params();

        /** @var array<string, mixed> $body */
        $body = is_array($body) ? $body : [];

        $input = $this->validator->validate($body, [
            'full_name' => ['type' => 'string', 'max' => 190],
            'company' => ['type' => 'string', 'max' => 190],
            'tax_id_type' => ['type' => 'string', 'enum' => ['CC', 'NIT', 'CE', 'PAS', 'RUT', 'OTRO']],
            'tax_id' => ['type' => 'string', 'max' => 40],
            'country' => ['type' => 'string', 'max' => 2],
            'phone' => ['type' => 'string', 'max' => 40],
        ]);

        if ($input !== []) {
            /** @var array<string, string|null> $fields */
            $fields = array_map(static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null, $input);
            $this->customers->update($customer->id, $fields, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }

        $fresh = $this->customers->find($customer->id) ?? $customer;

        return ['customer' => Presenter::customer($fresh)];
    }

    /**
     * @return array<string, mixed>
     */
    private function mySubscriptions(): array
    {
        $customer = $this->currentCustomer();
        $subscriptions = $this->subscriptions->findByCustomer($customer->id);
        $productsById = $this->products->findByIds(array_map(
            static fn (Subscription $s): int => $s->productId,
            $subscriptions,
        ));

        $items = [];

        foreach ($subscriptions as $subscription) {
            $item = Presenter::subscription($subscription, null, $productsById[$subscription->productId] ?? null);

            $openLink = $this->paymentLinks->findOpenBySubscription($subscription->id);
            $item['renewal_link'] = $openLink !== null ? Presenter::paymentLink($openLink) : null;

            $items[] = $item;
        }

        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelSubscription(\WP_REST_Request $request): array
    {
        $customer = $this->currentCustomer();
        $subscription = $this->subscriptions->findByUuid(strtolower((string) $request->get_param('uuid')));

        if ($subscription === null || $subscription->customerId !== $customer->id) {
            throw new NotFoundException('Suscripción no encontrada.');
        }

        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];
        $reason = is_string($body['reason'] ?? null) ? sanitize_text_field($body['reason']) : '';

        // El cliente siempre cancela al fin del periodo (sigue activo hasta entonces).
        $updated = $this->subscriptionService->cancel($subscription, true, [
            'source' => 'portal',
            'reason' => $reason,
        ]);

        if ($reason !== '') {
            $this->logger->info('portal', sprintf('Motivo de cancelación de %s: %s', $subscription->uuid, $reason));
        }

        return ['subscription' => Presenter::subscription($this->subscriptions->find($updated->id) ?? $updated)];
    }

    /**
     * @return array<string, mixed>
     */
    private function myPayments(\WP_REST_Request $request): array
    {
        $customer = $this->currentCustomer();

        $result = $this->payments->list(
            page: max(1, (int) $request->get_param('page')),
            perPage: 20,
            customerId: $customer->id,
        );

        return [
            'items' => array_map(static fn ($payment): array => Presenter::payment($payment), $result['items']),
            'total' => $result['total'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function myPaymentLinks(): array
    {
        $customer = $this->currentCustomer();
        $items = [];

        foreach ($this->subscriptions->findByCustomer($customer->id) as $subscription) {
            $link = $this->paymentLinks->findOpenBySubscription($subscription->id);

            if ($link !== null) {
                $items[] = Presenter::paymentLink($link);
            }
        }

        return ['items' => $items];
    }
}
