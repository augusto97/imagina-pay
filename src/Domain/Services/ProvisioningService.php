<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Services;

use ImaginaPay\Domain\Entities\Product;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Integrations\ImaginaUpdaterClient;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Provisión según el campo `provisioning` del producto (sección 8.6):
 *  - updater_license: crea/activa licencia en Imagina Updater y la guarda
 *    en el meta de la suscripción (el email de bienvenida y el portal la leen).
 *  - hook: dispara do_action('impay_provision') para automatización externa.
 *  - manual: registra tarea pendiente (meta + log) y notifica al admin.
 */
class ProvisioningService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly SubscriptionRepository $subscriptions,
        private readonly CustomerRepository $customers,
        private readonly ImaginaUpdaterClient $updater,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Se cuelga de impay_subscription_active (prioridad 10, antes que los emails).
     *
     * @param array<string, mixed> $context
     */
    public function provision(Subscription $subscription, array $context = []): void
    {
        $product = $this->products->find($subscription->productId);

        if ($product === null) {
            return;
        }

        $type = is_string($product->provisioning['type'] ?? null) ? $product->provisioning['type'] : '';

        match ($type) {
            'updater_license' => $this->provisionLicense($subscription, $product),
            'hook' => do_action('impay_provision', $subscription, $product),
            'manual' => $this->createManualTask($subscription, $product),
            '' => null, // Producto sin provisión configurada: solo los hooks estándar.
            default => $this->logger->warning('provisioning', sprintf(
                'Tipo de provisión desconocido "%s" en el producto %s.',
                $type,
                $product->slug,
            )),
        };
    }

    /**
     * Se cuelga de impay_service_suspend y de las transiciones cancelled/expired.
     */
    public function suspend(Subscription $subscription): void
    {
        $product = $this->products->find($subscription->productId);

        if ($product === null) {
            return;
        }

        $type = is_string($product->provisioning['type'] ?? null) ? $product->provisioning['type'] : '';

        if ($type !== 'updater_license') {
            // 'hook': el hook público impay_service_suspend ya lo recibió el equipo.
            // 'manual': la suspensión también es manual; queda el log.
            if ($type !== '') {
                $this->logger->info('provisioning', sprintf(
                    'Suspensión de servicio para la suscripción %s (provisión "%s").',
                    $subscription->uuid,
                    $type,
                ));
            }

            return;
        }

        $licenseKey = $subscription->meta['license_key'] ?? null;

        if (!is_string($licenseKey) || $licenseKey === '') {
            return;
        }

        try {
            $this->updater->deactivateLicense($licenseKey);
        } catch (\Throwable $exception) {
            $this->logger->error('provisioning', sprintf(
                'No fue posible desactivar la licencia de la suscripción %s: %s',
                $subscription->uuid,
                $exception->getMessage(),
            ));
        }
    }

    private function provisionLicense(Subscription $subscription, Product $product): void
    {
        $existingKey = $subscription->meta['license_key'] ?? null;

        if (is_string($existingKey) && $existingKey !== '') {
            // Reactivación (recuperación de past_due, renovación de expirada).
            try {
                $this->updater->activateLicense($existingKey);
            } catch (\Throwable $exception) {
                $this->logger->error('provisioning', sprintf(
                    'No fue posible reactivar la licencia de la suscripción %s: %s',
                    $subscription->uuid,
                    $exception->getMessage(),
                ));
            }

            return;
        }

        $customer = $this->customers->find($subscription->customerId);

        if ($customer === null) {
            return;
        }

        $updaterProductId = (int) ($product->provisioning['updater_product_id'] ?? 0);

        try {
            $licenseKey = $this->updater->createLicense($customer, $updaterProductId, $subscription->uuid);
        } catch (\Throwable $exception) {
            $this->logger->error('provisioning', sprintf(
                'No fue posible crear la licencia de la suscripción %s: %s',
                $subscription->uuid,
                $exception->getMessage(),
            ));

            // La licencia no debe perderse: tarea manual para el equipo.
            $this->createManualTask($subscription, $product);

            return;
        }

        $meta = $subscription->meta ?? [];
        $meta['license_key'] = $licenseKey;
        $this->subscriptions->updateMeta($subscription->id, $meta, $this->clock->now());

        do_action('impay_license_created', $subscription, $licenseKey);

        $this->logger->info('provisioning', sprintf(
            'Licencia provisionada para la suscripción %s.',
            $subscription->uuid,
        ));
    }

    private function createManualTask(Subscription $subscription, Product $product): void
    {
        $meta = $subscription->meta ?? [];
        $meta['manual_task'] = [
            'status' => 'pending',
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        $this->subscriptions->updateMeta($subscription->id, $meta, $this->clock->now());

        $this->logger->warning('provisioning', sprintf(
            'Tarea de provisión manual pendiente: %s (suscripción %s).',
            $product->name,
            $subscription->uuid,
        ), ['subscription' => $subscription->uuid, 'product' => $product->slug]);

        do_action('impay_manual_task', $subscription, $product);
    }
}
