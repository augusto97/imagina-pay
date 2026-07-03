<?php

declare(strict_types=1);

namespace ImaginaPay\Admin;

use ImaginaPay\Frontend\ViteAssets;

/**
 * Página "Imagina Pay" en wp-admin: monta el SPA React full-screen.
 * #impay-root es un overlay fijo que cubre el contenido y el menú de WP
 * (queda visible solo la admin bar), estilo Imagina Reports.
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

        // Overlay full-screen: cubre el menú y el contenido de WP; la admin
        // bar (z-index 99999) queda accesible por encima.
        echo '<style>
            #impay-root {
                position: fixed;
                top: 32px;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 99990;
                background: #FAFAFA;
                overflow: hidden;
            }
            @media screen and (max-width: 782px) {
                #impay-root { top: 46px; }
            }
            #wpfooter, .update-nag, .notice, #wpbody-content > .error { display: none !important; }
        </style>';
        echo '<script type="application/json" id="impay-boot">' . wp_json_encode($bootData) . '</script>';
        echo '<div id="impay-root">';

        if (!ViteAssets::isBuilt()) {
            echo '<div style="padding:48px;font-family:sans-serif;color:#71717A;">';
            echo esc_html('El frontend de Imagina Pay no está compilado. Ejecuta "npm install && npm run build" en el directorio frontend/ del plugin.');
            echo '</div>';
        }

        echo '</div>';
    }
}
