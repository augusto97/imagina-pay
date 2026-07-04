<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Services;

use ImaginaPay\Domain\Entities\Customer;
use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Product;
use ImaginaPay\Domain\Enums\OrderKind;
use ImaginaPay\Domain\Enums\OrderStatus;
use ImaginaPay\Domain\Enums\PriceStatus;
use ImaginaPay\Domain\Enums\ProductStatus;
use ImaginaPay\Domain\Enums\ProductType;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Exceptions\NotFoundException;
use ImaginaPay\Exceptions\ValidationException;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;
use ImaginaPay\Support\Uuid;

/**
 * Flujo de checkout (sección 8.1): crea/actualiza customer, crea order
 * (y subscription si el precio es recurrente), delega en la pasarela y
 * devuelve la URL de redirección. Cero datos de tarjeta en el servidor.
 */
final class CheckoutService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly PriceRepository $prices,
        private readonly CustomerRepository $customers,
        private readonly OrderRepository $orders,
        private readonly SubscriptionRepository $subscriptions,
        private readonly GatewayRegistry $gateways,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input Datos ya validados por el Validator del controller.
     * @return array{redirect_url: string, order: string}
     */
    public function start(array $input): array
    {
        $product = $this->products->findByUuid((string) $input['product']);

        if ($product === null || $product->status !== ProductStatus::Active) {
            throw new NotFoundException('El producto no está disponible.');
        }

        $price = $this->prices->findByUuid((string) $input['price']);

        if ($price === null || $price->status !== PriceStatus::Active || $price->productId !== $product->id) {
            throw new NotFoundException('El precio seleccionado no está disponible.');
        }

        $gateway = $this->gateways->get((string) $input['gateway']);

        if (!$gateway->supports('currency_' . $price->currency)) {
            throw new ValidationException([
                'gateway' => sprintf('Este método de pago no procesa %s.', $price->currency),
            ]);
        }

        // Suscripción real solo para productos subscription con precio recurrente.
        // annual_hybrid se cobra como pago único; su suscripción lógica se crea al pagar (Fase 3).
        $isRecurring = $product->type === ProductType::Subscription && $price->interval->isRecurring();

        if ($isRecurring && !$gateway->supports('recurring')) {
            throw new ValidationException([
                'gateway' => 'Este método de pago no soporta suscripciones.',
            ]);
        }

        $customFields = $this->collectCustomFields($product, $input['custom_fields'] ?? null);

        $customer = $this->upsertCustomer($input);
        $now = $this->clock->now();
        $orderUuid = Uuid::v4();

        $orderId = $this->orders->insert([
            'uuid' => $orderUuid,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price_id' => $price->id,
            'subscription_id' => null,
            'kind' => ($isRecurring ? OrderKind::SubscriptionInitial : OrderKind::Purchase)->value,
            'status' => OrderStatus::Pending->value,
            'currency' => $price->currency,
            'amount' => $price->amount,
            'gateway' => $gateway->id(),
            'external_reference' => $orderUuid,
            'meta' => $customFields === null ? null : (string) wp_json_encode(['custom_fields' => $customFields]),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $session = $isRecurring
            ? $this->startSubscription($orderId, $orderUuid, $customer, $product, $price, $gateway->id())
            : $this->startOneTime($orderId, $gateway->id());

        if ($session->gatewayRef !== null) {
            $this->orders->setGatewayRef($orderId, $session->gatewayRef, $this->clock->now());
        }

        $this->logger->info('checkout', sprintf(
            'Checkout iniciado: order %s, producto %s, gateway %s.',
            $orderUuid,
            $product->slug,
            $gateway->id(),
        ));

        return [
            'redirect_url' => $session->redirectUrl,
            'order' => $orderUuid,
        ];
    }

    private function startOneTime(int $orderId, string $gatewayId): \ImaginaPay\Gateways\CheckoutSession
    {
        $order = $this->orders->find($orderId);

        if ($order === null) {
            throw new NotFoundException('No fue posible crear el pedido.');
        }

        return $this->gateways->get($gatewayId)->createOneTimeCheckout($order);
    }

    private function startSubscription(
        int $orderId,
        string $orderUuid,
        Customer $customer,
        Product $product,
        Price $price,
        string $gatewayId,
    ): \ImaginaPay\Gateways\CheckoutSession {
        $now = $this->clock->now();

        $subscriptionId = $this->subscriptions->insert([
            'uuid' => Uuid::v4(),
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price_id' => $price->id,
            'gateway' => $gatewayId,
            'gateway_sub_id' => null,
            'status' => SubscriptionStatus::Pending->value,
            'cancel_at_period_end' => 0,
            'failed_payments' => 0,
            'meta' => (string) wp_json_encode(['initial_order_uuid' => $orderUuid]),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $subscription = $this->subscriptions->find($subscriptionId);

        if ($subscription === null) {
            throw new NotFoundException('No fue posible crear la suscripción.');
        }

        $this->orders->linkSubscription($orderId, $subscriptionId, $now);

        $session = $this->gateways->get($gatewayId)->createSubscription($subscription, $price);

        if ($session->gatewayRef !== null) {
            $this->subscriptions->setGatewaySubId($subscriptionId, $session->gatewayRef, $this->clock->now());
        }

        return $session;
    }

    /**
     * Valida las respuestas del comprador contra los campos personalizados
     * del producto y las devuelve listas para guardar en el meta del order:
     * [{key, label, value}, ...]. null si el producto no define campos.
     *
     * @return list<array{key: string, label: string, value: string}>|null
     */
    private function collectCustomFields(Product $product, mixed $raw): ?array
    {
        $definitions = $product->customFields ?? [];

        if ($definitions === []) {
            return null;
        }

        $values = is_array($raw) ? $raw : [];
        $errors = [];
        $collected = [];

        foreach ($definitions as $definition) {
            $key = (string) ($definition['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $label = (string) ($definition['label'] ?? $key);
            $value = $values[$key] ?? '';
            $value = is_scalar($value) ? trim(wp_strip_all_tags((string) $value)) : '';

            if (($definition['required'] ?? false) === true && $value === '') {
                $errors['custom_' . $key] = sprintf('El campo "%s" es obligatorio.', $label);
                continue;
            }

            if (mb_strlen($value) > 1000) {
                $errors['custom_' . $key] = sprintf('El campo "%s" es demasiado largo (máx. 1000 caracteres).', $label);
                continue;
            }

            if (($definition['type'] ?? '') === 'select' && $value !== '') {
                $options = array_map('strval', (array) ($definition['options'] ?? []));

                if (!in_array($value, $options, true)) {
                    $errors['custom_' . $key] = sprintf('El valor de "%s" no es una opción válida.', $label);
                    continue;
                }
            }

            if ($value !== '') {
                $collected[] = ['key' => $key, 'label' => $label, 'value' => $value];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $collected === [] ? null : $collected;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function upsertCustomer(array $input): Customer
    {
        $email = (string) $input['email'];
        $now = $this->clock->now();

        $fields = [
            'full_name' => (string) $input['full_name'],
            'company' => isset($input['company']) ? (string) $input['company'] : null,
            'tax_id_type' => isset($input['tax_id_type']) ? (string) $input['tax_id_type'] : null,
            'tax_id' => isset($input['tax_id']) ? (string) $input['tax_id'] : null,
            'country' => isset($input['country']) ? strtoupper((string) $input['country']) : 'CO',
            'phone' => isset($input['phone']) ? (string) $input['phone'] : null,
        ];

        $existing = $this->customers->findByEmail($email);

        if ($existing !== null) {
            $this->customers->update($existing->id, $fields, $now);

            return $this->customers->find($existing->id) ?? $existing;
        }

        $customerId = $this->customers->insert([
            'uuid' => Uuid::v4(),
            'email' => $email,
            ...$fields,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $customer = $this->customers->find($customerId);

        if ($customer === null) {
            throw new NotFoundException('No fue posible registrar el cliente.');
        }

        return $customer;
    }
}
