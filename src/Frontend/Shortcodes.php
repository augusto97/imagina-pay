<?php

declare(strict_types=1);

namespace ImaginaPay\Frontend;

use ImaginaPay\Domain\Entities\Price;
use ImaginaPay\Domain\Entities\Product;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Rest\Admin\Presenter;
use ImaginaPay\Support\Money;

/**
 * Páginas propias del plugin: [impay_checkout], [impay_gracias],
 * [impay_portal]. Los assets solo se encolan cuando el shortcode se
 * renderiza (cero impacto en el resto del sitio). El checkout imprime
 * el JSON del producto inline para render instantáneo sin fetch.
 */
final class Shortcodes
{
    public const QUERY_VAR = 'impay_product';

    /**
     * Base de la URL de venta. Deliberadamente NO es "checkout":
     * WooCommerce y otros plugins de e-commerce reservan ese slug.
     */
    private const CHECKOUT_BASE = 'pagar';

    /**
     * Al cambiar las reglas de rewrite, subir la versión: se hace flush
     * automático una sola vez (cubre actualizaciones por zip sin reactivar).
     */
    private const REWRITE_VERSION = '2';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly PriceRepository $prices,
    ) {
    }

    public function register(): void
    {
        add_action('init', static function (): void {
            self::registerRewrite();

            if (get_option('impay_rewrite_version') !== self::REWRITE_VERSION) {
                flush_rewrite_rules();
                update_option('impay_rewrite_version', self::REWRITE_VERSION);
            }
        });

        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;

            return $vars;
        });

        add_shortcode('impay_checkout', fn (): string => $this->renderCheckout());
        add_shortcode('impay_gracias', fn (): string => $this->renderThanks());
        add_shortcode('impay_portal', fn (): string => $this->renderPortal());
        add_shortcode('impay_boton', fn (array|string $atts): string => $this->renderBuyButton(is_array($atts) ? $atts : []));
        add_shortcode('impay_productos', fn (array|string $atts): string => $this->renderCatalog(is_array($atts) ? $atts : []));
    }

    /**
     * [impay_productos columnas="3"] — catálogo público: grid de todos los
     * productos activos con imagen, descripción, precio "desde" y botón de
     * compra. Renderizado en PHP con estilos inline: cero assets extra.
     *
     * @param array<string|int, string> $atts
     */
    private function renderCatalog(array $atts): string
    {
        $columns = max(1, min(4, (int) ($atts['columnas'] ?? 3)));

        $products = array_filter(
            $this->products->all(),
            static fn (Product $product): bool => $product->status->value === 'active',
        );

        if ($products === []) {
            return '';
        }

        $cards = '';

        foreach ($products as $product) {
            $prices = array_values(array_filter(
                $this->prices->findByProduct($product->id),
                static fn (Price $price): bool => $price->status->value === 'active',
            ));

            if ($prices === []) {
                continue;
            }

            usort($prices, static fn (Price $a, Price $b): int => $a->amount <=> $b->amount);
            $lowest = $prices[0];

            $intervalLabel = match ($lowest->interval->value) {
                'month' => ' / mes',
                'year' => ' / año',
                default => '',
            };

            $imageHtml = $product->imageUrl !== null && $product->imageUrl !== ''
                ? sprintf(
                    '<img src="%s" alt="%s" loading="lazy" style="width:100%%;height:170px;object-fit:cover;border-radius:11px 11px 0 0;" />',
                    esc_url($product->imageUrl),
                    esc_attr($product->name),
                )
                : '<div style="width:100%;height:12px;"></div>';

            $featuresHtml = '';

            foreach (array_slice($product->features ?? [], 0, 4) as $feature) {
                $featuresHtml .= sprintf(
                    '<li style="margin:0 0 6px;padding-left:22px;position:relative;font-size:14px;color:#3F3F46;">'
                    . '<span style="position:absolute;left:0;color:#059669;">✓</span>%s</li>',
                    esc_html($feature),
                );
            }

            $descriptionHtml = $product->description !== null && $product->description !== ''
                ? sprintf('<p style="margin:0 0 12px;font-size:14px;color:#71717A;line-height:1.5;">%s</p>', esc_html($product->description))
                : '';

            $priceLabel = count($prices) > 1 ? 'Desde ' : '';

            $cards .= sprintf(
                '<div style="background:#fff;border:1px solid #E4E4E7;border-radius:12px;box-shadow:0 1px 2px rgb(0 0 0 / .04);display:flex;flex-direction:column;overflow:hidden;">'
                . '%s'
                . '<div style="padding:20px 22px 22px;display:flex;flex-direction:column;flex:1;">'
                . '<h3 style="margin:0 0 8px;font-size:18px;font-weight:600;letter-spacing:-0.01em;color:#18181B;">%s</h3>'
                . '%s'
                . '<ul style="list-style:none;margin:0 0 16px;padding:0;">%s</ul>'
                . '<div style="margin-top:auto;">'
                . '<p style="margin:0 0 14px;font-size:22px;font-weight:600;color:#18181B;font-variant-numeric:tabular-nums;">%s%s<span style="font-size:14px;font-weight:400;color:#71717A;">%s</span></p>'
                . '<a href="%s" style="display:block;text-align:center;padding:12px 0;border-radius:10px;background:#4F46E5;color:#fff;font-weight:600;font-size:15px;text-decoration:none;">Comprar ahora</a>'
                . '</div></div></div>',
                $imageHtml,
                esc_html($product->name),
                $descriptionHtml,
                $featuresHtml,
                esc_html($priceLabel),
                esc_html(Money::of($lowest->amount, $lowest->currency)->format()),
                esc_html($intervalLabel),
                esc_url(self::checkoutUrl($product->slug)),
            );
        }

        if ($cards === '') {
            return '';
        }

        return sprintf(
            '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%%,%dpx),1fr));gap:24px;">%s</div>',
            $columns >= 4 ? 230 : ($columns === 3 ? 280 : 380),
            $cards,
        );
    }

    /**
     * URL pública de venta de un producto. Con permalinks bonitos usa
     * /pagar/{slug}/; si no, la página de checkout con query var.
     */
    public static function checkoutUrl(string $slug): string
    {
        $permalinkStructure = get_option('permalink_structure', '');

        if (is_string($permalinkStructure) && $permalinkStructure !== '') {
            return home_url('/' . self::CHECKOUT_BASE . '/' . $slug . '/');
        }

        $pageId = (int) get_option('impay_page_checkout', 0);
        $base = $pageId > 0 ? get_permalink($pageId) : false;

        return add_query_arg(self::QUERY_VAR, $slug, is_string($base) ? $base : home_url('/'));
    }

    /**
     * [impay_boton producto="slug" texto="Comprar ahora" color="#4F46E5"]
     * Botón de compra insertable en cualquier página o builder. Cero assets:
     * un solo anchor con estilos inline.
     *
     * @param array<string|int, string> $atts
     */
    private function renderBuyButton(array $atts): string
    {
        $slug = sanitize_title((string) ($atts['producto'] ?? $atts['slug'] ?? ''));

        if ($slug === '') {
            return '';
        }

        $product = $this->products->findBySlug($slug);

        if ($product === null || $product->status->value !== 'active') {
            return '';
        }

        $text = sanitize_text_field((string) ($atts['texto'] ?? 'Comprar ahora'));
        $color = sanitize_hex_color((string) ($atts['color'] ?? '')) ?: '#4F46E5';

        return sprintf(
            '<a href="%s" style="display:inline-block;padding:13px 30px;border-radius:10px;background:%s;'
            . 'color:#ffffff;font-weight:600;font-size:15px;text-decoration:none;line-height:1;">%s</a>',
            esc_url(self::checkoutUrl($slug)),
            esc_attr($color),
            esc_html($text),
        );
    }

    /**
     * /pagar/{slug} → página checkout con el producto en query var.
     * También la invoca el Activator antes de flush_rewrite_rules().
     */
    public static function registerRewrite(): void
    {
        $pageId = (int) get_option('impay_page_checkout', 0);

        if ($pageId <= 0) {
            return;
        }

        add_rewrite_rule(
            '^' . self::CHECKOUT_BASE . '/([^/]+)/?$',
            sprintf('index.php?page_id=%d&%s=$matches[1]', $pageId, self::QUERY_VAR),
            'top',
        );
    }

    private function renderCheckout(): string
    {
        $slug = get_query_var(self::QUERY_VAR);
        $slug = is_string($slug) && $slug !== '' ? sanitize_title($slug) : '';

        $product = $slug !== '' ? $this->products->findBySlug($slug) : null;

        if ($product === null || $product->status->value !== 'active') {
            return '<div id="impay-root"><p style="padding:48px;text-align:center;color:#71717A;">'
                . esc_html('Este producto no está disponible.') . '</p></div>';
        }

        $activePrices = array_values(array_filter(
            $this->prices->findByProduct($product->id),
            static fn ($price): bool => $price->status->value === 'active',
        ));

        $boot = [
            'restUrl' => esc_url_raw(rest_url('impay/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'gateways' => ['mercadopago', 'paypal'],
            'product' => Presenter::product($product, $activePrices),
        ];

        ViteAssets::enqueue('checkout', 'src/checkout/main.tsx');

        return '<script type="application/json" id="impay-boot">' . (string) wp_json_encode($boot) . '</script>'
            . '<div id="impay-root"><div id="impay-checkout-root"></div></div>';
    }

    private function renderThanks(): string
    {
        $orderUuid = isset($_GET['order']) && is_string($_GET['order']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_text_field(wp_unslash($_GET['order'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';

        $boot = [
            'restUrl' => esc_url_raw(rest_url('impay/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'gateways' => [],
            'order' => $orderUuid,
            'portalUrl' => $this->pageUrl('impay_page_portal', '/portal-cliente/'),
        ];

        ViteAssets::enqueue('checkout', 'src/checkout/main.tsx');

        return '<script type="application/json" id="impay-boot">' . (string) wp_json_encode($boot) . '</script>'
            . '<div id="impay-root"><div id="impay-gracias-root"></div></div>';
    }

    private function renderPortal(): string
    {
        $boot = [
            'restUrl' => esc_url_raw(rest_url('impay/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'gateways' => [],
            'loggedIn' => is_user_logged_in(),
            'userName' => is_user_logged_in() ? wp_get_current_user()->display_name : '',
            'supportEmail' => (string) get_option('admin_email', ''),
        ];

        ViteAssets::enqueue('portal', 'src/portal/main.tsx');

        return '<script type="application/json" id="impay-boot">' . (string) wp_json_encode($boot) . '</script>'
            . '<div id="impay-root"><div id="impay-portal-root"></div></div>';
    }

    private function pageUrl(string $option, string $fallback): string
    {
        $pageId = (int) get_option($option, 0);
        $url = $pageId > 0 ? get_permalink($pageId) : false;

        return is_string($url) ? $url : home_url($fallback);
    }
}
