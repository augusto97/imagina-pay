<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Admin;

use ImaginaPay\Domain\Entities\Customer;
use ImaginaPay\Domain\Entities\Order;
use ImaginaPay\Domain\Entities\Payment;
use ImaginaPay\Domain\Entities\PaymentLink;
use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Product;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Support\Money;

/**
 * Serialización de entidades para el API admin. Nunca expone ids internos
 * como referencia pública (siempre uuid) ni datos sensibles.
 */
final class Presenter
{
    private const DATE = 'Y-m-d H:i:s';

    /**
     * @param list<Price> $prices
     * @return array<string, mixed>
     */
    public static function product(Product $product, array $prices = []): array
    {
        return [
            'uuid' => $product->uuid,
            'name' => $product->name,
            'slug' => $product->slug,
            'type' => $product->type->value,
            'description' => $product->description,
            'features' => $product->features,
            'image_url' => $product->imageUrl,
            'status' => $product->status->value,
            'provisioning' => $product->provisioning,
            'custom_fields' => $product->customFields,
            'prices' => array_map(static fn (Price $price): array => self::price($price), $prices),
            'created_at' => $product->createdAt->format(self::DATE),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function price(Price $price): array
    {
        return [
            'uuid' => $price->uuid,
            'currency' => $price->currency,
            'amount' => $price->amount,
            'formatted' => Money::of($price->amount, $price->currency)->format(),
            'interval' => $price->interval->value,
            'trial_days' => $price->trialDays,
            'status' => $price->status->value,
            'gateway_refs' => $price->gatewayRefs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customer(Customer $customer): array
    {
        return [
            'uuid' => $customer->uuid,
            'email' => $customer->email,
            'full_name' => $customer->fullName,
            'company' => $customer->company,
            'tax_id_type' => $customer->taxIdType?->value,
            'tax_id' => $customer->taxId,
            'country' => $customer->country,
            'phone' => $customer->phone,
            'created_at' => $customer->createdAt->format(self::DATE),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function subscription(Subscription $subscription, ?Customer $customer = null, ?Product $product = null): array
    {
        return [
            'uuid' => $subscription->uuid,
            'gateway' => $subscription->gateway,
            'gateway_sub_id' => $subscription->gatewaySubId,
            'status' => $subscription->status->value,
            'status_label' => $subscription->status->label(),
            'current_period_start' => $subscription->currentPeriodStart?->format(self::DATE),
            'current_period_end' => $subscription->currentPeriodEnd?->format(self::DATE),
            'cancel_at_period_end' => $subscription->cancelAtPeriodEnd,
            'failed_payments' => $subscription->failedPayments,
            'license_key' => is_string($subscription->meta['license_key'] ?? null)
                ? $subscription->meta['license_key']
                : null,
            'manual_task_pending' => ($subscription->meta['manual_task']['status'] ?? '') === 'pending',
            'customer' => $customer !== null ? self::customer($customer) : null,
            'product' => $product !== null ? ['uuid' => $product->uuid, 'name' => $product->name, 'type' => $product->type->value] : null,
            'created_at' => $subscription->createdAt->format(self::DATE),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function order(Order $order, ?Customer $customer = null, ?Product $product = null): array
    {
        $customFields = $order->meta['custom_fields'] ?? null;

        return [
            'uuid' => $order->uuid,
            'custom_fields' => is_array($customFields) ? array_values(array_filter($customFields, 'is_array')) : null,
            'kind' => $order->kind->value,
            'status' => $order->status->value,
            'currency' => $order->currency,
            'amount' => $order->amount,
            'formatted' => Money::of($order->amount, $order->currency)->format(),
            'gateway' => $order->gateway,
            'paid_at' => $order->paidAt?->format(self::DATE),
            'customer' => $customer !== null ? self::customer($customer) : null,
            'product' => $product !== null ? ['uuid' => $product->uuid, 'name' => $product->name] : null,
            'created_at' => $order->createdAt->format(self::DATE),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payment(Payment $payment, ?Customer $customer = null, ?Order $order = null): array
    {
        $customFields = $order?->meta['custom_fields'] ?? null;

        return [
            'uuid' => $payment->uuid,
            'custom_fields' => is_array($customFields) ? array_values(array_filter($customFields, 'is_array')) : null,
            'gateway' => $payment->gateway,
            'gateway_payment_id' => $payment->gatewayPaymentId,
            'status' => $payment->status->value,
            'currency' => $payment->currency,
            'amount' => $payment->amount,
            'formatted' => Money::of($payment->amount, $payment->currency)->format(),
            'method' => $payment->method,
            'paid_at' => $payment->paidAt?->format(self::DATE),
            'customer' => $customer !== null ? self::customer($customer) : null,
            'created_at' => $payment->createdAt->format(self::DATE),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentLink(PaymentLink $link): array
    {
        return [
            'uuid' => $link->uuid,
            'gateway' => $link->gateway,
            'url' => $link->url,
            'status' => $link->status->value,
            'expires_at' => $link->expiresAt?->format(self::DATE),
            'created_at' => $link->createdAt->format(self::DATE),
        ];
    }

    /**
     * @param array<string, mixed> $row Fila cruda de impay_webhook_events.
     * @return array<string, mixed>
     */
    public static function webhookEvent(array $row): array
    {
        $payload = json_decode(is_string($row['payload'] ?? null) ? $row['payload'] : '', true);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'gateway' => (string) ($row['gateway'] ?? ''),
            'event_id' => (string) ($row['event_id'] ?? ''),
            'topic' => (string) ($row['topic'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'error' => is_string($row['error'] ?? null) ? $row['error'] : null,
            'attempts' => (int) ($row['attempts'] ?? 0),
            'payload' => is_array($payload) ? $payload : null,
            'received_at' => (string) ($row['received_at'] ?? ''),
            'processed_at' => is_string($row['processed_at'] ?? null) ? $row['processed_at'] : null,
        ];
    }
}
