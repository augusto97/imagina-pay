<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Admin;

use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Exceptions\NotFoundException;
use ImaginaPay\Exceptions\ValidationException;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Gateways\PaymentLinkRequest;
use ImaginaPay\Rest\AbstractController;
use ImaginaPay\Rest\Middleware\CapabilityMiddleware;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Rest\Validator;
use ImaginaPay\Support\Logger;
use ImaginaPay\Support\Money;

/**
 * POST /admin/payment-links — cobro manual a un cliente: con un precio
 * existente o con monto libre + descripción.
 */
final class PaymentLinksController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly CustomerRepository $customers,
        private readonly PriceRepository $prices,
        private readonly GatewayRegistry $gateways,
        private readonly Validator $validator,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::API_NAMESPACE, '/admin/payment-links', [
            'methods' => 'POST',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->create($r)),
            'permission_callback' => $this->permissions(
                new NonceMiddleware(),
                new CapabilityMiddleware('manage_impay'),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function create(\WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        /** @var array<string, mixed> $body */
        $body = is_array($body) ? $body : [];

        $input = $this->validator->validate($body, [
            'customer' => ['required' => true, 'type' => 'uuid'],
            'gateway' => ['required' => true, 'type' => 'string', 'enum' => array_keys($this->gateways->all())],
            'price' => ['type' => 'uuid'],
            'amount' => ['type' => 'int'],
            'currency' => ['type' => 'string', 'enum' => ['COP', 'USD']],
            'description' => ['type' => 'string', 'max' => 190],
        ]);

        $customer = $this->customers->findByUuid((string) $input['customer']);

        if ($customer === null) {
            throw new NotFoundException('Cliente no encontrado.');
        }

        $priceId = null;

        if (isset($input['price'])) {
            $price = $this->prices->findByUuid((string) $input['price']);

            if ($price === null) {
                throw new NotFoundException('Precio no encontrado.');
            }

            $priceId = $price->id;
            $money = Money::of($price->amount, $price->currency);
            $description = (string) ($input['description'] ?? sprintf('Cobro %s', $price->uuid));
        } else {
            $amount = (int) ($input['amount'] ?? 0);
            $currency = (string) ($input['currency'] ?? '');
            $description = (string) ($input['description'] ?? '');

            if ($amount <= 0 || $currency === '' || $description === '') {
                throw new ValidationException([
                    'amount' => 'Para monto libre indica amount, currency y description.',
                ]);
            }

            $money = Money::of($amount, $currency);
        }

        $gateway = $this->gateways->get((string) $input['gateway']);

        if (!$gateway->supports('currency_' . $money->currency)) {
            throw new ValidationException(['gateway' => sprintf('Esta pasarela no procesa %s.', $money->currency)]);
        }

        $link = $gateway->createPaymentLink(new PaymentLinkRequest(
            customer: $customer,
            amount: $money,
            description: $description,
            priceId: $priceId,
        ));

        $this->logger->info('admin', sprintf('Link de pago manual creado para %s.', $customer->email));

        return ['payment_link' => Presenter::paymentLink($link)];
    }
}
