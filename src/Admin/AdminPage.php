<?php

declare(strict_types=1);

namespace ImaginaPay\Admin;

use ImaginaPay\Frontend\ViteAssets;

/**
 * Página "Imagina Pay" en wp-admin: monta el SPA React dentro del canvas
 * normal de WordPress (menú y admin bar visibles) — el plugin debe
 * sentirse parte de WordPress, no una aplicación aparte.
 */
final class AdminPage
{
    private const MENU_SLUG = 'imagina-pay';

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_menu_page(
                'Imagina Pay',
                'Imagina Pay',
                'manage_impay',
                self::MENU_SLUG,
                function (): void {
                    $this->render();
                },
                'dashicons-money-alt',
                56,
            );
        });

        add_action('admin_enqueue_scripts', static function (string $hookSuffix): void {
            if (str_contains($hookSuffix, self::MENU_SLUG)) {
                ViteAssets::enqueue('admin', 'src/admin/main.tsx');
            }
        });
    }

    private function render(): void
    {
        $bootData = [
            'restUrl' => esc_url_raw(rest_url('impay/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'adminUrl' => esc_url_raw(admin_url()),
            'userName' => wp_get_current_user()->display_name,
            'gateways' => ['mercadopago', 'paypal'],
            'version' => defined('IMPAY_VERSION') ? (string) constant('IMPAY_VERSION') : '',
        ];

        echo '<script type="application/json" id="impay-boot">' . wp_json_encode($bootData) . '</script>';

        // El h1 (oculto) da a WordPress su punto de anclaje para las notices
        // y mantiene la jerarquía de encabezados para lectores de pantalla.
        echo '<div class="wrap">';
        echo '<h1 class="screen-reader-text">Imagina Pay</h1>';
        echo '<div id="impay-root">';

        if (!ViteAssets::isBuilt()) {
            echo '<div style="padding:48px;font-family:sans-serif;color:#71717A;">';
            echo esc_html('El frontend de Imagina Pay no está compilado. Ejecuta "npm install && npm run build" en el directorio frontend/ del plugin.');
            echo '</div>';
        }

        echo '</div></div>';
    }
}
