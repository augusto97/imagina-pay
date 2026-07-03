<?php

declare(strict_types=1);

namespace ImaginaPay\Frontend;

use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Rest\Admin\Presenter;

/**
 * Páginas propias del plugin: [impay_checkout], [impay_gracias],
 * [impay_portal]. Los assets solo se encolan cuando el shortcode se
 * renderiza (cero impacto en el resto del sitio). El checkout imprime
 * el JSON del producto inline para render instantáneo sin fetch.
 */
final class Shortcodes
{
    public const QUERY_VAR = 'impay_product';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly PriceRepository $prices,
    ) {
    }

    public function register(): void
    {
        add_action('init', static function (): void {
            self::registerRewrite();
        });

        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;

            return $vars;
        });

        add_shortcode('impay_checkout', fn (): string => $this->renderCheckout());
        add_shortcode('impay_gracias', fn (): string => $this->renderThanks());
        add_shortcode('impay_portal', fn (): string => $this->renderPortal());
    }

    /**
     * /checkout/{slug} → página checkout con el producto en query var.
     * También la invoca el Activator antes de flush_rewrite_rules().
     */
    public static function registerRewrite(): void
    {
        $pageId = (int) get_option('impay_page_checkout', 0);

        if ($pageId <= 0) {
            return;
        }

        add_rewrite_rule(
            '^checkout/([^/]+)/?$',
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
            'portalUrl' => $this->pageUrl('impay_page_portal', '/mi-cuenta/'),
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
