<?php

declare(strict_types=1);

namespace ImaginaPay\Rest\Admin;

use ImaginaPay\Domain\Enums\PriceInterval;
use ImaginaPay\Domain\Enums\PriceStatus;
use ImaginaPay\Domain\Enums\ProductStatus;
use ImaginaPay\Domain\Enums\ProductType;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Exceptions\NotFoundException;
use ImaginaPay\Frontend\Shortcodes;
use ImaginaPay\Exceptions\ValidationException;
use ImaginaPay\Rest\AbstractController;
use ImaginaPay\Rest\Middleware\CapabilityMiddleware;
use ImaginaPay\Rest\Middleware\NonceMiddleware;
use ImaginaPay\Rest\Validator;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;
use ImaginaPay\Support\Uuid;

/**
 * CRUD de productos y precios (admin). Los precios nunca se editan en
 * montos: se archivan y se crea uno nuevo (integridad histórica).
 */
final class ProductsController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly ProductRepository $products,
        private readonly PriceRepository $prices,
        private readonly Validator $validator,
        private readonly Clock $clock,
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $permissions = $this->permissions(new NonceMiddleware(), new CapabilityMiddleware('manage_impay'));
        $uuidPattern = '(?P<uuid>[0-9a-fA-F-]{36})';

        register_rest_route(self::API_NAMESPACE, '/admin/products', [
            [
                'methods' => 'GET',
                'callback' => fn (): \WP_REST_Response => $this->handle(fn (): array => $this->index()),
                'permission_callback' => $permissions,
            ],
            [
                'methods' => 'POST',
                'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->create($r)),
                'permission_callback' => $permissions,
            ],
        ]);

        register_rest_route(self::API_NAMESPACE, '/admin/products/' . $uuidPattern, [
            'methods' => 'PUT',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->update($r)),
            'permission_callback' => $permissions,
        ]);

        register_rest_route(self::API_NAMESPACE, '/admin/products/' . $uuidPattern . '/prices', [
            'methods' => 'POST',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->createPrice($r)),
            'permission_callback' => $permissions,
        ]);

        register_rest_route(self::API_NAMESPACE, '/admin/prices/' . $uuidPattern, [
            'methods' => 'PUT',
            'callback' => fn (\WP_REST_Request $r): \WP_REST_Response => $this->handle(fn (): array => $this->updatePrice($r)),
            'permission_callback' => $permissions,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function index(): array
    {
        $items = [];

        foreach ($this->products->all() as $product) {
            $item = Presenter::product($product, $this->prices->findByProduct($product->id));
            $item['checkout_url'] = Shortcodes::checkoutUrl($product->slug);
            $items[] = $item;
        }

        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function create(\WP_REST_Request $request): array
    {
        $input = $this->validateProduct($request, creating: true);
        $now = $this->clock->now();
        $name = (string) $input['name'];
        $slug = isset($input['slug']) && $input['slug'] !== '' ? (string) $input['slug'] : sanitize_title($name);

        if ($this->products->findBySlug($slug) !== null) {
            throw new ValidationException(['slug' => 'Ya existe un producto con este slug.']);
        }

        $productId = $this->products->insert([
            'uuid' => Uuid::v4(),
            'name' => $name,
            'slug' => $slug,
            'type' => (string) $input['type'],
            'description' => isset($input['description']) ? (string) $input['description'] : null,
            'features' => isset($input['features']) ? (string) wp_json_encode($input['features']) : null,
            'image_url' => isset($input['image_url']) ? (string) $input['image_url'] : null,
            'status' => (string) ($input['status'] ?? ProductStatus::Draft->value),
            'provisioning' => isset($input['provisioning']) ? (string) wp_json_encode($input['provisioning']) : null,
            'custom_fields' => isset($input['custom_fields']) && is_array($input['custom_fields'])
                ? (string) wp_json_encode($this->normalizeCustomFields($input['custom_fields']))
                : null,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $product = $this->products->find($productId);

        if ($product === null) {
            throw new NotFoundException('No fue posible crear el producto.');
        }

        $this->logger->info('admin', sprintf('Producto creado: %s.', $slug));

        return ['product' => Presenter::product($product)];
    }

    /**
     * @return array<string, mixed>
     */
    private function update(\WP_REST_Request $request): array
    {
        $product = $this->products->findByUuid(strtolower((string) $request->get_param('uuid')));

        if ($product === null) {
            throw new NotFoundException('Producto no encontrado.');
        }

        $input = $this->validateProduct($request, creating: false);
        $data = ['updated_at' => $this->clock->now()->format('Y-m-d H:i:s')];

        foreach (['name', 'description', 'image_url', 'type', 'status'] as $field) {
            if (isset($input[$field])) {
                $data[$field] = (string) $input[$field];
            }
        }

        if (array_key_exists('features', $input)) {
            $data['features'] = (string) wp_json_encode($input['features']);
        }

        if (array_key_exists('provisioning', $input)) {
            $data['provisioning'] = (string) wp_json_encode($input['provisioning']);
        }

        if (array_key_exists('custom_fields', $input)) {
            $data['custom_fields'] = (string) wp_json_encode(
                $this->normalizeCustomFields(is_array($input['custom_fields']) ? $input['custom_fields'] : []),
            );
        }

        $this->products->update($product->id, $data);

        $fresh = $this->products->find($product->id) ?? $product;

        return ['product' => Presenter::product($fresh, $this->prices->findByProduct($fresh->id))];
    }

    /**
     * @return array<string, mixed>
     */
    private function createPrice(\WP_REST_Request $request): array
    {
        $product = $this->products->findByUuid(strtolower((string) $request->get_param('uuid')));

        if ($product === null) {
            throw new NotFoundException('Producto no encontrado.');
        }

        $body = $request->get_json_params();

        /** @var array<string, mixed> $body */
        $body = is_array($body) ? $body : [];

        $input = $this->validator->validate($body, [
            'currency' => ['required' => true, 'type' => 'string', 'enum' => ['COP', 'USD']],
            'amount' => ['required' => true, 'type' => 'int'],
            'interval' => ['required' => true, 'type' => 'string', 'enum' => array_column(PriceInterval::cases(), 'value')],
            'trial_days' => ['type' => 'int'],
        ]);

        if ((int) $input['amount'] <= 0) {
            throw new ValidationException(['amount' => 'El monto debe ser mayor a cero (en unidad mínima).']);
        }

        $now = $this->clock->now();

        $priceId = $this->prices->insert([
            'uuid' => Uuid::v4(),
            'product_id' => $product->id,
            'currency' => (string) $input['currency'],
            'amount' => (int) $input['amount'],
            'interval' => (string) $input['interval'],
            'trial_days' => (int) ($input['trial_days'] ?? 0),
            'status' => PriceStatus::Active->value,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $price = $this->prices->find($priceId);

        if ($price === null) {
            throw new NotFoundException('No fue posible crear el precio.');
        }

        return ['price' => Presenter::price($price)];
    }

    /**
     * Solo permite archivar/reactivar (los montos son inmutables).
     *
     * @return array<string, mixed>
     */
    private function updatePrice(\WP_REST_Request $request): array
    {
        $price = $this->prices->findByUuid(strtolower((string) $request->get_param('uuid')));

        if ($price === null) {
            throw new NotFoundException('Precio no encontrado.');
        }

        $body = $request->get_json_params();

        /** @var array<string, mixed> $body */
        $body = is_array($body) ? $body : [];

        $input = $this->validator->validate($body, [
            'status' => ['required' => true, 'type' => 'string', 'enum' => array_column(PriceStatus::cases(), 'value')],
        ]);

        $this->prices->update($price->id, [
            'status' => (string) $input['status'],
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $fresh = $this->prices->find($price->id) ?? $price;

        return ['price' => Presenter::price($fresh)];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProduct(\WP_REST_Request $request, bool $creating): array
    {
        $body = $request->get_json_params();

        /** @var array<string, mixed> $body */
        $body = is_array($body) ? $body : [];

        return $this->validator->validate($body, [
            'name' => ['required' => $creating, 'type' => 'string', 'max' => 190],
            'slug' => ['type' => 'string', 'max' => 190],
            'type' => ['required' => $creating, 'type' => 'string', 'enum' => array_column(ProductType::cases(), 'value')],
            'description' => ['type' => 'string', 'max' => 5000],
            'features' => ['type' => 'array'],
            'image_url' => ['type' => 'string', 'max' => 500],
            'status' => ['type' => 'string', 'enum' => array_column(ProductStatus::cases(), 'value')],
            'provisioning' => ['type' => 'array'],
            'custom_fields' => ['type' => 'array'],
        ]);
    }

    /**
     * Normaliza la definición de campos extra del checkout. Cada campo:
     * {key, label, type: text|textarea|select, required, options?}. El key
     * se conserva si viene (estabilidad al editar el label) o se deriva.
     *
     * @param array<int|string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    private function normalizeCustomFields(array $raw): array
    {
        $fields = [];
        $usedKeys = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));

            if ($label === '' || mb_strlen($label) > 120) {
                continue;
            }

            $type = (string) ($item['type'] ?? 'text');

            if (!in_array($type, ['text', 'textarea', 'select'], true)) {
                $type = 'text';
            }

            $key = sanitize_key((string) ($item['key'] ?? ''));

            if ($key === '') {
                $key = sanitize_key(str_replace('-', '_', sanitize_title($label)));
            }

            if ($key === '') {
                $key = 'campo';
            }

            // Keys únicos: un duplicado pisaría la respuesta de otro campo.
            $base = $key;
            $suffix = 2;

            while (in_array($key, $usedKeys, true)) {
                $key = $base . '_' . $suffix;
                ++$suffix;
            }

            $usedKeys[] = $key;

            $options = [];

            if ($type === 'select') {
                foreach ((array) ($item['options'] ?? []) as $option) {
                    $option = trim((string) (is_scalar($option) ? $option : ''));

                    if ($option !== '') {
                        $options[] = mb_substr($option, 0, 120);
                    }
                }

                // Un select sin opciones no es usable en el checkout.
                if ($options === []) {
                    $type = 'text';
                }
            }

            if (count($fields) >= 15) {
                throw new ValidationException(['custom_fields' => 'Máximo 15 campos personalizados por producto.']);
            }

            $field = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($item['required'] ?? false),
            ];

            if ($options !== []) {
                $field['options'] = $options;
            }

            $fields[] = $field;
        }

        return $fields;
    }
}
