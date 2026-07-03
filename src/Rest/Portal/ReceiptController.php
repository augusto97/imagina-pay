<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Portal;

use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\PaymentRepository;
use ImaginaPay\Exceptions\ImaginaPayException;
use ImaginaPay\Exceptions\NotFoundException;
use ImaginaPay\Rest\AbstractController;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Support\Logger;
use ImaginaPay\Support\Money;

/**
 * GET /me/payments/{uuid}/receipt — recibo HTML imprimible (soporte
 * contable; la factura DIAN formal se emite fuera del plugin).
 */
final class ReceiptController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly PaymentRepository $payments,
        private readonly CustomerRepository $customers,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::API_NAMESPACE, '/me/payments/(?P<uuid>[0-9a-fA-F-]{36})/receipt', [
            'methods' => 'GET',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(
                fn (): \WP_REST_Response => $this->receipt($r),
            ),
            'permission_callback' => $this->permissions(new NonceMiddleware()),
        ]);
    }

    private function receipt(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!is_user_logged_in()) {
            throw new ImaginaPayException('Debes iniciar sesión.', 'impay_no_autenticado', 401);
        }

        $customer = $this->customers->findByWpUserId(get_current_user_id());
        $payment = $this->payments->findByUuid(strtolower((string) $request->get_param('uuid')));

        if ($customer === null || $payment === null || $payment->customerId !== $customer->id) {
            throw new NotFoundException('Pago no encontrado.');
        }

        $siteName = get_bloginfo('name');
        $amount = Money::of($payment->amount, $payment->currency)->format();
        $paidAt = ($payment->paidAt ?? $payment->createdAt)
            ->setTimezone(new \DateTimeZone('America/Bogota'))
            ->format('d/m/Y H:i');

        $taxLine = $customer->taxId !== null
            ? sprintf('%s %s', $customer->taxIdType?->value ?? '', $customer->taxId)
            : '—';

        $html = sprintf(
            '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Recibo %1$s</title>
<style>
body{font-family:Inter,system-ui,sans-serif;color:#18181B;max-width:640px;margin:40px auto;padding:0 24px;}
h1{font-size:20px;letter-spacing:-0.01em;} table{width:100%%;border-collapse:collapse;margin-top:24px;}
td{padding:10px 0;border-bottom:1px solid #E4E4E7;font-size:14px;} td:first-child{color:#71717A;}
td:last-child{text-align:right;font-variant-numeric:tabular-nums;}
.total{font-size:18px;font-weight:600;} .muted{color:#71717A;font-size:12px;margin-top:32px;}
@media print{.no-print{display:none}}
</style></head><body>
<h1>Recibo de pago</h1>
<p style="color:#71717A;font-size:14px;">%2$s · Recibo %1$s</p>
<table>
<tr><td>Cliente</td><td>%3$s</td></tr>
<tr><td>Email</td><td>%4$s</td></tr>
<tr><td>Documento</td><td>%5$s</td></tr>
<tr><td>Fecha de pago</td><td>%6$s</td></tr>
<tr><td>Método</td><td>%7$s</td></tr>
<tr><td>Referencia de pasarela</td><td>%8$s</td></tr>
<tr><td>Total pagado</td><td class="total">%9$s</td></tr>
</table>
<p class="muted">Este documento es un soporte de pago emitido por %2$s. No constituye factura electrónica DIAN.</p>
<button class="no-print" onclick="window.print()" style="margin-top:24px;padding:10px 24px;border-radius:10px;border:1px solid #E4E4E7;background:#fff;cursor:pointer;">Imprimir</button>
</body></html>',
            esc_html($payment->uuid),
            esc_html(is_string($siteName) ? $siteName : ''),
            esc_html($customer->fullName),
            esc_html($customer->email),
            esc_html($taxLine),
            esc_html($paidAt),
            esc_html($payment->method ?? $payment->gateway),
            esc_html($payment->gatewayPaymentId),
            esc_html($amount),
        );

        $response = new \WP_REST_Response($html, 200);
        $response->header('Content-Type', 'text/html; charset=utf-8');

        return $response;
    }
}
