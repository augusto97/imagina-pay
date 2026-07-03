<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Admin;

use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\Payment;
use ImaginaPay\Domain\Enums\OrderStatus;
use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Rest\AbstractController;
use ImaginaPay\Rest\Middleware\CapabilityMiddleware;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;
use ImaginaPay\Support\Money;

/**
 * Pagos y orders (admin) + export CSV contable (soporte para facturación
 * DIAN externa: Siigo/Alegra).
 */
final class PaymentsController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly PaymentRepository $payments,
        private readonly OrderRepository $orders,
        private readonly CustomerRepository $customers,
        private readonly ProductRepository $products,
        private readonly Clock $clock,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $permissions = $this->permissions(new NonceMiddleware(), new CapabilityMiddleware('manage_impay'));

        register_rest_route(self::API_NAMESPACE, '/admin/payments', [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->index($r)),
            'permission_callback' => $permissions,
        ]);

        register_rest_route(self::API_NAMESPACE, '/admin/orders', [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->ordersIndex($r)),
            'permission_callback' => $permissions,
        ]);

        register_rest_route(self::API_NAMESPACE, '/admin/export/payments.csv', [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): \WP_REST_Response => $this->exportCsv($r)),
            'permission_callback' => $permissions,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function index(\WP_REST_Request $request): array
    {
        $statusParam = $request->get_param('status');

        $result = $this->payments->list(
            page: max(1, (int) $request->get_param('page')),
            perPage: min(100, max(1, (int) ($request->get_param('per_page') ?: 20))),
            status: is_string($statusParam) && $statusParam !== '' ? PaymentStatus::tryFrom($statusParam) : null,
            gateway: is_string($request->get_param('gateway')) ? $request->get_param('gateway') : null,
            from: $this->dateParam($request, 'from'),
            to: $this->dateParam($request, 'to'),
        );

        $customersById = $this->customers->findByIds(array_map(
            static fn (Payment $p): int => $p->customerId,
            $result['items'],
        ));

        $sums = [];

        foreach ($result['sums'] as $currency => $amount) {
            $sums[] = [
                'currency' => $currency,
                'amount' => $amount,
                'formatted' => Money::of(max(0, $amount), $currency)->format(),
            ];
        }

        return [
            'items' => array_map(
                static fn (Payment $p): array => Presenter::payment($p, $customersById[$p->customerId] ?? null),
                $result['items'],
            ),
            'total' => $result['total'],
            'sums' => $sums,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ordersIndex(\WP_REST_Request $request): array
    {
        $statusParam = $request->get_param('status');

        $result = $this->orders->list(
            page: max(1, (int) $request->get_param('page')),
            perPage: min(100, max(1, (int) ($request->get_param('per_page') ?: 20))),
            status: is_string($statusParam) && $statusParam !== '' ? OrderStatus::tryFrom($statusParam) : null,
            gateway: is_string($request->get_param('gateway')) ? $request->get_param('gateway') : null,
        );

        $customersById = $this->customers->findByIds(array_map(
            static fn (Order $o): int => $o->customerId,
            $result['items'],
        ));
        $productsById = $this->products->findByIds(array_map(
            static fn (Order $o): int => $o->productId,
            $result['items'],
        ));

        return [
            'items' => array_map(
                static fn (Order $o): array => Presenter::order(
                    $o,
                    $customersById[$o->customerId] ?? null,
                    $productsById[$o->productId] ?? null,
                ),
                $result['items'],
            ),
            'total' => $result['total'],
        ];
    }

    private function exportCsv(\WP_REST_Request $request): \WP_REST_Response
    {
        $now = $this->clock->now();
        $from = $this->dateParam($request, 'from') ?? $now->modify('first day of this month')->setTime(0, 0);
        $to = $this->dateParam($request, 'to') ?? $now;

        $rows = $this->payments->rowsForExport($from, $to->setTime(23, 59, 59));

        $columns = ['uuid', 'gateway', 'gateway_payment_id', 'status', 'currency', 'amount', 'method', 'paid_at', 'created_at', 'email', 'full_name', 'tax_id_type', 'tax_id'];
        $lines = [implode(';', $columns)];

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $value = $row[$column] ?? '';
                $value = is_scalar($value) ? (string) $value : '';

                // El monto sale en unidades mayores con coma decimal (contabilidad es-CO).
                if ($column === 'amount') {
                    $cents = (int) $value;
                    $value = sprintf('%d,%02d', intdiv($cents, 100), $cents % 100);
                }

                $line[] = '"' . str_replace('"', '""', $value) . '"';
            }

            $lines[] = implode(';', $line);
        }

        $response = new \WP_REST_Response(implode("\n", $lines), 200);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header(
            'Content-Disposition',
            sprintf('attachment; filename="impay-pagos-%s-a-%s.csv"', $from->format('Ymd'), $to->format('Ymd')),
        );

        return $response;
    }

    private function dateParam(\WP_REST_Request $request, string $name): ?\DateTimeImmutable
    {
        $value = $request->get_param($name);

        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date === false ? null : $date->setTime(0, 0);
    }
}
